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
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Framework\Model\AbstractModel;

/**
 * Lets a REST client restore store-view inheritance for a category attribute.
 *
 * Categories carry the same trap as products and it bites harder, because a
 * category's store-scoped fields are the ones a merchandiser edits by hand:
 * name, is_active, include_in_menu, url_key and the SEO fields.
 *
 * Hooked on the resource model rather than on CategoryRepositoryInterface,
 * which is where the equivalent product plugin sits. The repository is the
 * wrong seam here: CategoryRepository::save() serialises the incoming object,
 * then *replaces* it with a freshly loaded one and repopulates that from the
 * serialised array. Anything a repository-level plugin sets is therefore
 * discarded — and worse, discarded inconsistently, because the serialiser
 * drops a null while carrying a false through to be written as a NULL row.
 * By the resource model the entity is final and its original data is loaded,
 * which is what the EAV layer needs in order to delete rather than write.
 */
class RestoreCategoryScopeInheritance
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
     * @param \Magento\Catalog\Model\ResourceModel\Category $subject
     * @param \Magento\Framework\Model\AbstractModel $object
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeSave(CategoryResource $subject, AbstractModel $object): array
    {
        if ($object instanceof Category) {
            $this->processor->process($object, $object->getAttributes(), 'categories/{id}');
        }

        return [$object];
    }
}
