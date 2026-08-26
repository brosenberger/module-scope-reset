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

namespace BroCode\ScopeReset\Plugin;

use BroCode\ScopeReset\Model\ScopeResetProcessor;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;

/**
 * Lets a REST client restore store-view inheritance for a product attribute.
 *
 * The API has no way to express "make this attribute inherit again": omitting
 * it keeps the override, an empty string writes an empty override, and
 * repeating the global value pins a copy that stops tracking future changes.
 * Sending the CSV importer's empty-value constant now does what the admin's
 * "Use Default Value" checkbox does — deletes the store-scoped row.
 */
class RestoreProductScopeInheritance
{
    /**
     * @var ScopeResetProcessor
     */
    private $processor;

    /**
     * @param \BroCode\ScopeReset\Model\ScopeResetProcessor $processor
     */
    public function __construct(ScopeResetProcessor $processor)
    {
        $this->processor = $processor;
    }

    /**
     * Translate the reset sentinel into the value the EAV layer deletes on.
     *
     * @param \Magento\Catalog\Api\ProductRepositoryInterface $subject
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param bool $saveOptions
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeSave(
        ProductRepositoryInterface $subject,
        ProductInterface $product,
        $saveOptions = false
    ): array {
        if ($product instanceof Product) {
            $this->processor->process(
                $product,
                $product->getAttributes(),
                'products/{sku}',
                ScopeResetProcessor::PRODUCT_FALSE_SENTINEL_BACKEND_TYPES
            );
        }

        return [$product, $saveOptions];
    }
}
