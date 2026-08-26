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

namespace BroCode\ScopeReset\Model;

use Magento\ImportExport\Model\Import;

/**
 * Resolves the sentinel that means "restore inheritance from the default scope".
 *
 * Deliberately delegates to the CSV importer's own constant rather than
 * declaring a second one, so an integration only ever has to learn a single
 * magic value regardless of whether it imports over REST or over CSV.
 */
class EmptyValueConstant
{
    /**
     * Return the sentinel value that marks a store-scoped attribute for reset.
     *
     * Matches Magento's CSV importer, which is `__EMPTY__VALUE__` on a default
     * install. The importer allows the value to be overridden per import run
     * via Import::FIELD_EMPTY_ATTRIBUTE_VALUE_CONSTANT; there is no equivalent
     * per-request override here, because a REST client cannot carry import
     * parameters and a store-wide override would make the API's behaviour
     * depend on invisible configuration.
     *
     * @return string
     */
    public function get(): string
    {
        return Import::DEFAULT_EMPTY_ATTRIBUTE_VALUE_CONSTANT;
    }

    /**
     * Whether the supplied attribute value is the reset sentinel.
     *
     * Compared strictly against a string cast so that a numeric-looking
     * attribute value can never coincide with the sentinel.
     *
     * @param mixed $value
     * @return bool
     */
    public function matches($value): bool
    {
        return is_string($value) && $value === $this->get();
    }
}
