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

namespace BroCode\ScopeReset\Test\Unit\Model;

use BroCode\ScopeReset\Model\EmptyValueConstant;
use BroCode\ScopeReset\Model\ScopeResetProcessor;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute as CatalogEavAttribute;
use Magento\Framework\Api\AttributeInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\ImportExport\Model\Import;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ScopeResetProcessorTest extends TestCase
{
    private const SENTINEL = Import::DEFAULT_EMPTY_ATTRIBUTE_VALUE_CONSTANT;

    /**
     * @var ScopeResetProcessor
     */
    private $processor;

    protected function setUp(): void
    {
        $this->processor = new ScopeResetProcessor(new EmptyValueConstant());
    }

    /**
     * The processor runs on every save of both entities, so the no-sentinel
     * path has to leave the entity strictly alone.
     */
    public function testLeavesAnEntityWithoutTheSentinelUntouched(): void
    {
        $product = $this->product(['name' => 'Ordinary Name'], 2);
        $product->expects($this->never())->method('setData');

        $this->processor->process($product, ['name' => $this->attribute('varchar')], 'products/{sku}');
    }

    public function testRefusesAResetAtDefaultScope(): void
    {
        $product = $this->product(['name' => self::SENTINEL], 0);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/default scope/');

        $this->processor->process($product, ['name' => $this->attribute('varchar')], 'products/{sku}');
    }

    public function testRefusesAnAttributeThatDoesNotApply(): void
    {
        $product = $this->product(['colour' => self::SENTINEL], 2);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/does not apply/');

        $this->processor->process($product, [], 'products/{sku}');
    }

    public function testRefusesAGlobalAttribute(): void
    {
        $product = $this->product(['sku_alias' => self::SENTINEL], 2);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/is global/');

        $this->processor->process(
            $product,
            ['sku_alias' => $this->attribute('varchar', true)],
            'products/{sku}'
        );
    }

    /**
     * The reset value differs per entity and getting it wrong writes a NULL row
     * instead of deleting, which fails silently. These two cases are the whole
     * reason the rule is a parameter rather than a constant.
     *
     * @dataProvider resetValueProvider
     * @param string $backendType
     * @param string[] $falseSentinelTypes
     * @param mixed $expected
     */
    public function testUsesTheCorrectResetValue(
        string $backendType,
        array $falseSentinelTypes,
        $expected
    ): void {
        $attribute = $this->attribute($backendType);
        $product = $this->product(['some_code' => self::SENTINEL], 2);

        $product->expects($this->once())
            ->method('setData')
            ->with('some_code', $expected);

        $this->processor->process(
            $product,
            ['some_code' => $attribute],
            'products/{sku}',
            $falseSentinelTypes
        );
    }

    /**
     * @return array<string, array{0: string, 1: string[], 2: mixed}>
     */
    public static function resetValueProvider(): array
    {
        $productRule = ScopeResetProcessor::PRODUCT_FALSE_SENTINEL_BACKEND_TYPES;

        return [
            'product varchar uses false'  => ['varchar', $productRule, false],
            'product text uses false'     => ['text', $productRule, false],
            'product datetime uses false' => ['datetime', $productRule, false],
            'product int uses null'       => ['int', $productRule, null],
            'product decimal uses null'   => ['decimal', $productRule, null],
            'category varchar uses null'  => ['varchar', [], null],
            'category text uses null'     => ['text', [], null],
        ];
    }

    /**
     * Setting only the data bag leaves the sentinel in the custom-attribute
     * bag, which is re-applied over it during the save and writes the literal
     * string into the row. That bug shipped once; this pins it.
     */
    public function testClearsTheCustomAttributeBagAsWellAsTheDataBag(): void
    {
        $product = $this->product(['some_code' => self::SENTINEL], 2);

        $product->expects($this->once())->method('setData')->with('some_code', null);
        $product->expects($this->once())->method('setCustomAttribute')->with('some_code', null);

        $this->processor->process($product, ['some_code' => $this->attribute('int')], 'products/{sku}');
    }

    /**
     * The save-time validator rejects the sentinel on a required attribute,
     * so the processor has to relax it exactly as the admin form does.
     */
    public function testRelaxesRequirednessForTheSave(): void
    {
        $attribute = $this->attribute('varchar');
        $attribute->expects($this->once())->method('setIsRequired')->with(false);

        $this->processor->process(
            $this->product(['some_code' => self::SENTINEL], 2),
            ['some_code' => $attribute],
            'products/{sku}'
        );
    }

    /**
     * A REST payload puts EAV attributes in the custom-attribute bag rather
     * than the data bag, so detection has to read both.
     */
    public function testDetectsTheSentinelInTheCustomAttributeBag(): void
    {
        $custom = $this->createMock(AttributeInterface::class);
        $custom->method('getAttributeCode')->willReturn('description');
        $custom->method('getValue')->willReturn(self::SENTINEL);

        $product = $this->product([], 2, [$custom]);
        $product->expects($this->once())->method('setData')->with('description', false);

        $this->processor->process(
            $product,
            ['description' => $this->attribute('text')],
            'products/{sku}',
            ScopeResetProcessor::PRODUCT_FALSE_SENTINEL_BACKEND_TYPES
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param int $storeId
     * @param AttributeInterface[] $customAttributes
     * @return Product&MockObject
     */
    private function product(array $data, int $storeId, array $customAttributes = []): MockObject
    {
        $product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'setData', 'getCustomAttributes', 'setCustomAttribute', 'getStoreId'])
            ->getMock();

        $product->method('getData')->willReturn($data);
        $product->method('getCustomAttributes')->willReturn($customAttributes);
        $product->method('getStoreId')->willReturn($storeId);

        return $product;
    }

    /**
     * @param string $backendType
     * @param bool $isGlobal
     * @return CatalogEavAttribute&MockObject
     */
    private function attribute(string $backendType, bool $isGlobal = false): MockObject
    {
        $attribute = $this->getMockBuilder(CatalogEavAttribute::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isScopeGlobal', 'getBackendType', 'setIsRequired'])
            ->getMock();

        $attribute->method('isScopeGlobal')->willReturn($isGlobal);
        $attribute->method('getBackendType')->willReturn($backendType);

        return $attribute;
    }
}
