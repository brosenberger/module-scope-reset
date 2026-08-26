<?php
/**
 * Copyright (C) 2026 Benjamin Rosenberger <bensch.rosenberger@gmail.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * @copyright 2026 Benjamin Rosenberger
 * @author bensch.rosenberger@gmail.com
 * @license MIT
 * @link https://brocode.at
 */

declare(strict_types=1);

namespace BroCode\ScopeReset\Test\Integration;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\ImportExport\Model\Import;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Categories are the case that justifies having integration tests at all.
 *
 * The category reset failed twice during development in ways no unit test
 * would have caught: once writing a NULL row instead of deleting, once doing
 * nothing at all — both returning success. The cause was the repository
 * reloading and repopulating the entity, which only shows up against a real
 * save. Each of those failures is pinned below.
 *
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class CategoryScopeResetTest extends TestCase
{
    private const SENTINEL = Import::DEFAULT_EMPTY_ATTRIBUTE_VALUE_CONSTANT;
    private const CATEGORY_ID = 333;

    /**
     * @var CategoryRepositoryInterface
     */
    private $categoryRepository;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ResourceConnection
     */
    private $resource;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->categoryRepository = $objectManager->get(CategoryRepositoryInterface::class);
        $this->storeManager = $objectManager->get(StoreManagerInterface::class);
        $this->resource = $objectManager->get(ResourceConnection::class);
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/category.php
     * @magentoDataFixture Magento/Store/_files/second_store.php
     */
    public function testResetDeletesTheScopedRowAndRestoresInheritance(): void
    {
        $storeId = $this->secondStoreId();
        $default = $this->loadName(0);

        $this->writeOverride($storeId, 'Store Specific Category');
        $this->assertSame(1, $this->countScopedRows('name', $storeId), 'precondition: override exists');

        $this->reset($storeId, 'name');

        $this->assertSame(
            0,
            $this->countScopedRows('name', $storeId),
            'the scoped row must be deleted, not rewritten to NULL'
        );
        $this->assertSame($default, $this->loadName($storeId), 'the category should inherit its default name again');
    }

    /**
     * Regression guard for the first category failure: a false sentinel on a
     * varchar backend wrote a NULL row, which reads back as an empty name and
     * pins it forever. The row-level assertion is the only thing that catches it.
     *
     * @magentoDataFixture Magento/Catalog/_files/category.php
     * @magentoDataFixture Magento/Store/_files/second_store.php
     */
    public function testResetDoesNotLeaveANullRowBehind(): void
    {
        $storeId = $this->secondStoreId();
        $this->writeOverride($storeId, 'Store Specific Category');
        $this->reset($storeId, 'name');

        $this->assertSame(
            0,
            $this->countScopedRows('name', $storeId, true),
            'a NULL row pins "no value" on that scope instead of inheriting'
        );
    }

    /**
     * Regression guard for the second category failure: hooked on the
     * repository, the reset silently did nothing because the repository
     * discards the incoming entity. A passing status told us nothing.
     *
     * @magentoDataFixture Magento/Catalog/_files/category.php
     * @magentoDataFixture Magento/Store/_files/second_store.php
     */
    public function testResetActuallyChangesSomething(): void
    {
        $storeId = $this->secondStoreId();
        $this->writeOverride($storeId, 'Store Specific Category');
        $this->reset($storeId, 'name');

        $this->assertNotSame(
            'Store Specific Category',
            $this->loadName($storeId),
            'the override survived the reset — the plugin is hooked on the wrong seam'
        );
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/category.php
     * @magentoDataFixture Magento/Store/_files/second_store.php
     */
    public function testAnotherStoreViewIsUntouched(): void
    {
        $storeId = $this->secondStoreId();
        $default = $this->loadName(0);

        $this->writeOverride($storeId, 'Store Specific Category');
        $this->reset($storeId, 'name');

        $this->assertSame($default, $this->loadName(0), 'the default scope must not be touched by a store reset');
    }

    /**
     * @return int
     */
    private function secondStoreId(): int
    {
        return (int) $this->storeManager->getStore('fixture_second_store')->getId();
    }

    /**
     * @param int $storeId
     * @param string $value
     * @return void
     */
    private function writeOverride(int $storeId, string $value): void
    {
        $category = $this->categoryRepository->get(self::CATEGORY_ID, $storeId);
        $category->setStoreId($storeId);
        $category->setData('name', $value);
        $this->categoryRepository->save($category);
    }

    /**
     * @param int $storeId
     * @param string $code
     * @return void
     */
    private function reset(int $storeId, string $code): void
    {
        $category = $this->categoryRepository->get(self::CATEGORY_ID, $storeId);
        $category->setStoreId($storeId);
        $category->setData($code, self::SENTINEL);
        $this->categoryRepository->save($category);
    }

    /**
     * @param int $storeId
     * @return string|null
     */
    private function loadName(int $storeId): ?string
    {
        return $this->categoryRepository->get(self::CATEGORY_ID, $storeId)->getName();
    }

    /**
     * @param string $code
     * @param int $storeId
     * @param bool $nullOnly
     * @return int
     */
    private function countScopedRows(string $code, int $storeId, bool $nullOnly = false): int
    {
        $connection = $this->resource->getConnection();

        $select = $connection->select()
            ->from(['v' => $this->resource->getTableName('catalog_category_entity_varchar')], ['cnt' => 'COUNT(*)'])
            ->join(
                ['a' => $this->resource->getTableName('eav_attribute')],
                'a.attribute_id = v.attribute_id',
                []
            )
            ->join(
                ['t' => $this->resource->getTableName('eav_entity_type')],
                't.entity_type_id = a.entity_type_id',
                []
            )
            ->where('a.attribute_code = ?', $code)
            ->where('t.entity_type_code = ?', 'catalog_category')
            ->where('v.entity_id = ?', self::CATEGORY_ID)
            ->where('v.store_id = ?', $storeId);

        if ($nullOnly) {
            $select->where('v.value IS NULL');
        }

        return (int) $connection->fetchOne($select);
    }
}
