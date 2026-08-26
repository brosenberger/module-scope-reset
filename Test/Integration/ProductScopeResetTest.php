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

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\ImportExport\Model\Import;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The behaviour the unit tests cannot prove: that the scoped row is actually
 * DELETED, and that the attribute inherits again afterwards.
 *
 * Every assertion here checks the row's existence directly rather than reading
 * the value back through a loaded model, because a NULL row and an absent row
 * both read back as "empty" — which is exactly how the category bug hid.
 *
 * DB isolation is deliberately DISABLED here. A product save rolls back a
 * nested transaction internally, and doing that inside the framework's own
 * isolation transaction fails with "Rolled back transaction has not been
 * completed correctly" — an error that names neither the product nor the
 * scope. The fixture ships a rollback script, so cleanup is handled without it.
 *
 * @magentoAppIsolation enabled
 * @magentoDbIsolation disabled
 */
class ProductScopeResetTest extends TestCase
{
    private const SENTINEL = Import::DEFAULT_EMPTY_ATTRIBUTE_VALUE_CONSTANT;
    private const SKU = 'simple';
    private const STORE_CODE = 'fixturestore';
    private const DEFAULT_NAME = 'Simple Product One';
    private const OVERRIDDEN_NAME = 'StoreTitle';

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

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
        $this->productRepository = $objectManager->get(ProductRepositoryInterface::class);
        $this->storeManager = $objectManager->get(StoreManagerInterface::class);
        $this->resource = $objectManager->get(ResourceConnection::class);
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple_multistore.php
     */
    public function testResetDeletesTheScopedRowAndRestoresInheritance(): void
    {
        $storeId = $this->secondStoreId();

        $this->writeOverride($storeId, self::OVERRIDDEN_NAME);
        $this->assertSame(
            1,
            $this->countScopedRows('name', $storeId),
            'precondition: the override should exist before it is reset'
        );

        $this->reset($storeId, 'name');

        $this->assertSame(
            0,
            $this->countScopedRows('name', $storeId),
            'the scoped row must be deleted, not rewritten to NULL'
        );
        $this->assertSame(
            self::DEFAULT_NAME,
            $this->loadName($storeId),
            'the attribute should resolve the default-scope value again'
        );
    }

    /**
     * A NULL row reads back the same as an absent one, so this asserts on the
     * row itself. It is the regression guard for the bug that shipped.
     *
     * @magentoDataFixture Magento/Catalog/_files/product_simple_multistore.php
     */
    public function testResetDoesNotLeaveANullRowBehind(): void
    {
        $storeId = $this->secondStoreId();
        $this->writeOverride($storeId, self::OVERRIDDEN_NAME);
        $this->reset($storeId, 'name');

        $this->assertSame(
            0,
            $this->countScopedRows('name', $storeId, true),
            'a NULL row pins "no value" on that scope instead of inheriting'
        );
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple_multistore.php
     */
    public function testResetIsIdempotent(): void
    {
        $storeId = $this->secondStoreId();
        $this->writeOverride($storeId, self::OVERRIDDEN_NAME);
        $this->reset($storeId, 'name');
        $this->reset($storeId, 'name');

        $this->assertSame(0, $this->countScopedRows('name', $storeId));
        $this->assertSame(self::DEFAULT_NAME, $this->loadName($storeId));
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple_multistore.php
     */
    public function testOrdinarySavesAreUnaffected(): void
    {
        $storeId = $this->secondStoreId();
        $this->writeOverride($storeId, self::OVERRIDDEN_NAME);

        $this->assertSame(self::OVERRIDDEN_NAME, $this->loadName($storeId));
        $this->assertSame(1, $this->countScopedRows('name', $storeId));
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple_multistore.php
     */
    public function testResetAtDefaultScopeIsRefused(): void
    {
        $product = $this->productRepository->get(self::SKU, true, 0, true);
        $product->setStoreId(0);
        $product->setData('name', self::SENTINEL);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/default scope/');

        $this->productRepository->save($product);
    }

    /**
     * @return int
     */
    private function secondStoreId(): int
    {
        return (int) $this->storeManager->getStore(self::STORE_CODE)->getId();
    }


    /**
     * The repositories take their scope from the *current* store rather than
     * from the entity, so a test has to switch it exactly as a store-code
     * route does.
     *
     * @param int $storeId
     * @param callable $callback
     * @return void
     */
    private function inStore(int $storeId, callable $callback): void
    {
        $original = $this->storeManager->getStore()->getId();
        $this->storeManager->setCurrentStore($storeId);

        try {
            $callback();
        } finally {
            $this->storeManager->setCurrentStore($original);
        }
    }

    /**
     * @param int $storeId
     * @param string $code
     * @return void
     */
    private function writeOverride(int $storeId, string $value): void
    {
        // Deliberately a repository save rather than the resource model's
        // saveAttribute(): that method opens its own transaction, which
        // conflicts with the framework's DB isolation and fails the test with
        // "Rolled back transaction has not been completed correctly" from
        // somewhere unrelated to what is being tested.
        $this->inStore($storeId, function () use ($storeId, $value): void {
            $product = $this->productRepository->get(self::SKU, false, $storeId, true);
            $product->setStoreId($storeId);
            $product->setData('name', $value);
            $this->productRepository->save($product);
        });
    }

    private function reset(int $storeId, string $code): void
    {
        $this->inStore($storeId, function () use ($storeId, $code): void {
            $product = $this->productRepository->get(self::SKU, false, $storeId, true);
            $product->setStoreId($storeId);
            $product->setData($code, self::SENTINEL);
            $this->productRepository->save($product);
        });
    }

    /**
     * @param int $storeId
     * @return string|null
     */
    private function loadName(int $storeId): ?string
    {
        return $this->productRepository->get(self::SKU, false, $storeId, true)->getName();
    }

    /**
     * Count rows directly. Reading the value back cannot distinguish an absent
     * row from a NULL one, and that distinction is the whole point.
     *
     * @param string $code
     * @param int $storeId
     * @param bool $nullOnly
     * @return int
     */
    private function countScopedRows(string $code, int $storeId, bool $nullOnly = false): int
    {
        $connection = $this->resource->getConnection();
        $product = $this->productRepository->get(self::SKU, false, 0, true);

        $select = $connection->select()
            ->from(['v' => $this->resource->getTableName('catalog_product_entity_varchar')], ['cnt' => 'COUNT(*)'])
            ->join(
                ['a' => $this->resource->getTableName('eav_attribute')],
                'a.attribute_id = v.attribute_id',
                []
            )
            ->where('a.attribute_code = ?', $code)
            ->where('v.entity_id = ?', (int) $product->getId())
            ->where('v.store_id = ?', $storeId);

        if ($nullOnly) {
            $select->where('v.value IS NULL');
        }

        return (int) $connection->fetchOne($select);
    }
}
