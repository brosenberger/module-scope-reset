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
use Magento\ImportExport\Model\Import;
use PHPUnit\Framework\TestCase;

class EmptyValueConstantTest extends TestCase
{
    /**
     * @var EmptyValueConstant
     */
    private $model;

    protected function setUp(): void
    {
        $this->model = new EmptyValueConstant();
    }

    /**
     * The point of the module is that integrators learn one magic string.
     * If this ever diverges from the importer, the module has lost its reason
     * to prefer this value over any other.
     */
    public function testSentinelTracksTheCsvImporterConstant(): void
    {
        $this->assertSame(Import::DEFAULT_EMPTY_ATTRIBUTE_VALUE_CONSTANT, $this->model->get());
    }

    public function testMatchesTheSentinel(): void
    {
        $this->assertTrue($this->model->matches(Import::DEFAULT_EMPTY_ATTRIBUTE_VALUE_CONSTANT));
    }

    /**
     * @dataProvider nonSentinelValuesProvider
     * @param mixed $value
     */
    public function testDoesNotMatchAnythingElse($value): void
    {
        $this->assertFalse($this->model->matches($value));
    }

    /**
     * Loose comparison would make several of these match, and a product whose
     * name is literally "0" would then be reset instead of renamed.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function nonSentinelValuesProvider(): array
    {
        return [
            'empty string'      => [''],
            'null'              => [null],
            'false'             => [false],
            'zero int'          => [0],
            'zero string'       => ['0'],
            'ordinary value'    => ['Some product name'],
            'array'             => [[]],
            'lowercased'        => ['__empty__value__'],
            'untrimmed'         => [' __EMPTY__VALUE__ '],
            'substring'         => ['prefix__EMPTY__VALUE__'],
        ];
    }
}
