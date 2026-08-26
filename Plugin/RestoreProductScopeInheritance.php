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

use BroCode\ScopeReset\Model\EmptyValueConstant;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\Store;

/**
 * Lets a REST client restore store-view inheritance for a product attribute.
 *
 * The API has no way to express "make this attribute inherit again": omitting
 * it keeps the override, an empty string writes an empty override, and
 * repeating the global value pins a copy that stops tracking future changes.
 * Sending the CSV importer's empty-value constant now does what the admin's
 * "Use Default Value" checkbox does — deletes the store-scoped row.
 *
 * The mechanism mirrors Magento's own adminhtml behaviour: AttributeFilter
 * substitutes boolean false for varchar/text/datetime backends and null for
 * the rest, and AbstractEntity::_processSaveData() turns that into a DELETE of
 * the scoped row rather than a write.
 */
class RestoreProductScopeInheritance
{
    /**
     * Backend types whose reset sentinel is boolean false rather than null.
     *
     * Mirrors Magento\Catalog\Controller\Adminhtml\Product\Initialization
     * \Helper\AttributeFilter::prepareDefaultData().
     */
    private const FALSE_SENTINEL_BACKEND_TYPES = ['varchar', 'text', 'datetime'];

    /**
     * @var EmptyValueConstant
     */
    private $emptyValueConstant;

    /**
     * @param \BroCode\ScopeReset\Model\EmptyValueConstant $emptyValueConstant
     */
    public function __construct(EmptyValueConstant $emptyValueConstant)
    {
        $this->emptyValueConstant = $emptyValueConstant;
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
        $codes = $this->findSentinelAttributes($product);

        if ($codes === []) {
            return [$product, $saveOptions];
        }

        if ((int) $product->getStoreId() === Store::DEFAULT_STORE_ID) {
            throw new LocalizedException(
                __(
                    'Cannot reset %1 at default scope. There is no parent scope to inherit from — '
                    . 'send the reset through a store-code route such as /rest/{store_code}/V1/products/{sku}.',
                    implode(', ', $codes)
                )
            );
        }

        $attributes = $product->getAttributes();

        foreach ($codes as $code) {
            if (!isset($attributes[$code])) {
                // Not on this product's attribute set: the value would be dropped
                // silently anyway, so say so rather than pretend the reset worked.
                throw new LocalizedException(
                    __('Attribute %1 is not in this product\'s attribute set and cannot be reset.', $code)
                );
            }

            $attribute = $attributes[$code];

            if ($attribute->isScopeGlobal()) {
                throw new LocalizedException(
                    __('Attribute %1 is global and has no store-scoped value to reset.', $code)
                );
            }

            // The save-time validator rejects the sentinel on a required
            // attribute, exactly as it does for the admin form, so relax it
            // for this save only.
            $attribute->setIsRequired(false);

            $resetValue = in_array($attribute->getBackendType(), self::FALSE_SENTINEL_BACKEND_TYPES, true)
                ? false
                : null;

            // Both bags have to be cleared. Setting only _data leaves the
            // sentinel in the custom-attribute bag, which is re-applied over
            // _data during the save and writes the literal string to the row.
            $product->setData($code, $resetValue);
            $product->setCustomAttribute($code, $resetValue);
        }

        return [$product, $saveOptions];
    }

    /**
     * Collect attribute codes carrying the reset sentinel.
     *
     * Reads both the custom-attribute bag, which is what a REST payload
     * populates, and directly-set data, so the same sentinel works from PHP.
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @return string[]
     */
    private function findSentinelAttributes(ProductInterface $product): array
    {
        $codes = [];

        foreach ($product->getCustomAttributes() as $attribute) {
            if ($this->emptyValueConstant->matches($attribute->getValue())) {
                $codes[] = $attribute->getAttributeCode();
            }
        }

        foreach ($product->getData() as $code => $value) {
            if ($this->emptyValueConstant->matches($value) && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}
