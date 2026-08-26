---
type: Mechanism
title: Mechanism — how the scoped row is deleted
description: How the EAV layer is persuaded to delete rather than write, and why products and categories hook different seams.
resource: https://github.com/brosenberger/module-scope-reset
tags: [magento2, eav, plugins, architecture]
sources:
  - resource: https://github.com/magento/magento2/blob/2.4-develop/app/code/Magento/Eav/Model/Entity/AbstractEntity.php
    title: Magento\Eav\Model\Entity\AbstractEntity::_collectSaveData — the insert/update/delete decision
  - resource: https://github.com/magento/magento2/blob/2.4-develop/app/code/Magento/Catalog/Model/CategoryRepository.php
    title: Magento\Catalog\Model\CategoryRepository::save — reloads and repopulates the entity
generated:
  by: claude-opus-5
  at: 2026-08-26T00:00:00Z
verified:
  - by: magento2-sandbox (Magento Open Source 2.4.8-p5)
    at: 2026-08-26T00:00:00Z
status: stable
stale_after: 2027-08-26T00:00:00Z
---

# The deletion path

`AbstractEntity::_collectSaveData()` sorts every attribute into insert, update or delete. The delete branch needs two conditions at once:

1. `_canUpdateAttribute()` is true — the attribute code is present in the entity's **original data**, meaning a value already existed at this scope.
2. `_isAttributeValueEmpty()` is true for the incoming value.

Both must hold. Miss the first and an empty value is simply skipped; miss the second and it is written. The module's whole job is to arrange both.

# The sentinel differs per entity

Magento's own admin controllers disagree, so there is no single correct answer to copy:

| Entity | Reset value | Source |
|---|---|---|
| Product | `false` for `varchar`, `text`, `datetime`; `null` otherwise | `AttributeFilter::prepareDefaultData()` |
| Category | `null` for everything | `Controller\Adminhtml\Category\Save` |

Passing the wrong one does not fail. It writes a `NULL` row, which pins *"no value"* on that scope instead of restoring inheritance — the same class of defect as a media role pinned to `no_selection`. It reads back as an empty field, with no error anywhere.

The processor takes the rule as a parameter rather than assuming.

# Why the two entities hook different seams

The product plugin sits on `ProductRepositoryInterface::save()`. The category plugin cannot, and the reason is worth knowing before extending this to anything else.

`CategoryRepository::save()` serialises the incoming object with `toNestedArray()`, then **replaces it** with a freshly loaded one and repopulates that from the serialised array. A repository-level plugin therefore mutates an object that is discarded — inconsistently, because the serialiser drops a `null` while carrying a `false` through to be written as a `NULL` row. Both outcomes return `200`.

The category plugin hooks `Magento\Catalog\Model\ResourceModel\Category::save()` instead, where the entity is final and its original data is loaded.

**The general rule this suggests:** before writing a repository plugin that mutates an entity, check whether that repository reloads it. Several do.

# Two details easy to get wrong

- **Required attributes.** The save-time validator rejects the sentinel on a required attribute, so `is_required` is relaxed for that save — exactly what the admin form does.
- **Both bags.** Setting only `_data` leaves the sentinel in the custom-attribute bag, which is re-applied over `_data` during the save and writes the literal string `__EMPTY__VALUE__` into the row.
