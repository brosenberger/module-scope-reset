---
type: Usage
title: Usage — resetting a scoped value
description: The sentinel value, where it belongs in a REST payload, and the conditions under which a reset is refused.
resource: https://github.com/brosenberger/module-scope-reset
tags: [magento2, rest-api, usage, store-scope]
generated:
  by: claude-opus-5
  at: 2026-08-26T00:00:00Z
verified:
  - by: magento2-sandbox (Magento Open Source 2.4.8-p5)
    at: 2026-08-26T00:00:00Z
status: stable
stale_after: 2027-08-26T00:00:00Z
---

# The sentinel

`__EMPTY__VALUE__` — read at runtime from `Magento\ImportExport\Model\Import::DEFAULT_EMPTY_ATTRIBUTE_VALUE_CONSTANT` rather than hardcoded, so it stays the same value the CSV importer already accepts. An integration learns one magic string whichever way it loads data.

Send it as an attribute value through a **store-code route**. The scoped row is deleted and the attribute inherits from the default scope again.

# Where it goes in the payload

This is Magento's rule, not the module's, and it is the most common reason a reset appears to do nothing: **a first-class field must be sent top-level, an EAV attribute inside `custom_attributes`.**

| Attribute | Send as |
|---|---|
| product `name`, `price`, `status`, `visibility`, `url_key` | top-level field |
| product `description`, `meta_title`, custom attributes | `custom_attributes` |
| category `name`, `is_active`, `include_in_menu`, `url_key` | top-level field |
| category `description`, SEO fields | `custom_attributes` |

Putting a first-class field into `custom_attributes` is silently ignored by the deserialiser — with or without this module — so the sentinel never arrives and the override survives. The call still returns `200`.

# What is refused

- **Default scope.** A reset sent to `/rest/all/` or a scopeless route returns `400`. There is no parent scope to inherit from, and deleting the global row would orphan every store view.
- **Attributes outside the entity's attribute set.** Refused, rather than accepted and silently dropped the way an ordinary write would be.
- **Global-scope attributes.** Refused — though in practice a typed field such as `price` is rejected by the deserialiser's own type check first, which is equally loud.

Resetting an attribute that already inherits is a no-op returning `200`, so a retried batch is safe.
