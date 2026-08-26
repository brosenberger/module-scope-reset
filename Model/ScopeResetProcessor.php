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

use Magento\Framework\Api\CustomAttributesDataInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractExtensibleModel;
use Magento\Store\Model\Store;

/**
 * Turns the reset sentinel into the value the EAV layer deletes a scoped row on.
 *
 * Shared by the product and category plugins: the entities differ but the
 * mechanism does not, because both store scoped values the same way.
 */
class ScopeResetProcessor
{
    /**
     * Backend types whose product reset sentinel is boolean false, not null.
     *
     * Mirrors Magento\Catalog\Controller\Adminhtml\Product\Initialization
     * \Helper\AttributeFilter::prepareDefaultData(). Categories do NOT follow
     * this rule — see the $falseSentinelBackendTypes argument on process().
     */
    public const PRODUCT_FALSE_SENTINEL_BACKEND_TYPES = ['varchar', 'text', 'datetime'];

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
     * Rewrite any sentinel-carrying attribute so the save deletes its scoped row.
     *
     * A no-op when the entity carries no sentinel, so it is safe on every save.
     *
     * The value that triggers the delete differs by entity, which is not
     * obvious and is easy to get wrong. Magento's own admin controllers
     * disagree: the product form substitutes boolean false for varchar, text
     * and datetime backends, while the category controller uses null for
     * everything. Passing false where null is wanted does not fail — it writes
     * a NULL row, which pins "no value" on that scope instead of restoring
     * inheritance, and reads back as an empty field with no error anywhere.
     *
     * @param \Magento\Framework\Model\AbstractExtensibleModel $entity
     * @param \Magento\Eav\Model\Entity\Attribute\AbstractAttribute[] $attributes
     * @param string $entityLabel Used only in error messages, e.g. "products/{sku}"
     * @param string[] $falseSentinelBackendTypes Backends reset with false rather than null
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function process(
        AbstractExtensibleModel $entity,
        array $attributes,
        string $entityLabel,
        array $falseSentinelBackendTypes = []
    ): void {
        $codes = $this->findSentinelAttributes($entity);

        if ($codes === []) {
            return;
        }

        if ((int) $entity->getStoreId() === Store::DEFAULT_STORE_ID) {
            throw new LocalizedException(
                __(
                    'Cannot reset %1 at default scope. There is no parent scope to inherit from — send the '
                    . 'reset through a store-code route such as /rest/{store_code}/V1/%2.',
                    implode(', ', $codes),
                    $entityLabel
                )
            );
        }

        foreach ($codes as $code) {
            if (!isset($attributes[$code])) {
                throw new LocalizedException(
                    __('Attribute %1 does not apply to this %2 and cannot be reset.', $code, $entityLabel)
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

            $resetValue = in_array($attribute->getBackendType(), $falseSentinelBackendTypes, true)
                ? false
                : null;

            // Both bags have to be cleared. Setting only _data leaves the
            // sentinel in the custom-attribute bag, which is re-applied over
            // _data during the save and writes the literal string to the row.
            $entity->setData($code, $resetValue);

            if ($entity instanceof CustomAttributesDataInterface) {
                $entity->setCustomAttribute($code, $resetValue);
            }
        }
    }

    /**
     * Collect attribute codes carrying the reset sentinel.
     *
     * Reads the custom-attribute bag, which is what a REST payload populates
     * for EAV attributes, and the data bag, which is where first-class fields
     * such as a product name or a category name land.
     *
     * @param \Magento\Framework\Model\AbstractExtensibleModel $entity
     * @return string[]
     */
    private function findSentinelAttributes(AbstractExtensibleModel $entity): array
    {
        $codes = [];

        if ($entity instanceof CustomAttributesDataInterface) {
            foreach ($entity->getCustomAttributes() as $attribute) {
                if ($this->emptyValueConstant->matches($attribute->getValue())) {
                    $codes[] = $attribute->getAttributeCode();
                }
            }
        }

        foreach ($entity->getData() as $code => $value) {
            if ($this->emptyValueConstant->matches($value) && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}
