---
type: Limits
title: Limits — what is out of scope and what cannot work
description: Why customers cannot be supported, which entities are covered, and which claims are pinned to a Magento version.
resource: https://github.com/brosenberger/module-scope-reset
tags: [magento2, scope, customers, limitations]
generated:
  by: claude-opus-5
  at: 2026-08-26T00:00:00Z
verified:
  - by: magento2-sandbox (Magento Open Source 2.4.8-p5)
    at: 2026-08-26T00:00:00Z
status: stable
stale_after: 2027-08-26T00:00:00Z
---

# Covered

Products and categories. Both store scoped attribute values in EAV value tables keyed by `store_id`, so both have a scoped row that can be deleted.

# Customers cannot be supported

Not an omission and not a backlog item — there is nothing to reset.

| Table | Has `store_id`? |
|---|---|
| `catalog_product_entity_varchar` | yes |
| `catalog_category_entity_varchar` | yes |
| `customer_entity_varchar` | **no** |
| `customer_entity_int`, `_text`, `_datetime`, `_decimal` | **no** |

`customer_eav_attribute` carries no scope flag either. The one per-scope structure in the customer EAV model is `customer_eav_attribute_website`, and it scopes attribute *metadata* — `is_visible`, `is_required`, `default_value`, `multiline_count` — not values. The `store_id` column on `customer_entity` records which store the account was created in; it does not scope anything.

Customer attribute values are global per customer. A customer plugin would be a no-op wearing a useful-looking name.

# Not attempted

- **Other EAV entities.** Anything with scoped values could work in principle, but each needs its own seam checked — see the category case in [Mechanism](mechanism.md), where the obvious hook silently discards the change.
- **Bulk and async routes.** Untested. The sentinel travels in the payload, so it should behave the same, but "should" is not "does".
- **GraphQL.** Out of scope; the mutation surface for catalog writes is minimal.

# What ages

The mechanism relies on two internal behaviours rather than on a public contract: the delete branch in `AbstractEntity::_collectSaveData()`, and the reset values Magento's own admin controllers use. Neither is an API anyone promised to keep.

Re-test after a major or minor upgrade. The cheapest check is the one in [Verification](verification.md): reset a scoped value, then confirm the row is **absent** rather than `NULL`.
