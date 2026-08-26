---
type: Verification
title: Verification — what was executed
description: The install, the cases exercised for both entities, and the query mistake that nearly hid a real bug.
resource: https://github.com/brosenberger/module-scope-reset
tags: [magento2, verification, testing]
sources:
  - resource: https://magento2-sandbox.ddev.site
    title: magento2-sandbox — Magento Open Source 2.4.8-p5, sample data, one website with four store views
generated:
  by: claude-opus-5
  at: 2026-08-26T00:00:00Z
verified:
  - by: magento2-sandbox (Magento Open Source 2.4.8-p5)
    at: 2026-08-26T00:00:00Z
status: stable
stale_after: 2027-02-26T00:00:00Z
---

# The install

Magento Open Source **2.4.8-p5**, PHP 8.4, Luma sample data, production mode, one website with four store views. Nothing here was inferred from reading source; source was read only to explain a result already observed.

# Cases exercised

For **products** and **categories**, via both a first-class field and an EAV attribute:

- a scoped override is created, then reset, and the attribute inherits again
- the sibling store view that also had an override is **untouched**
- the scoped row is **deleted**, not rewritten to `NULL`
- a repeated reset returns `200` with no further effect
- an ordinary save with a real value still behaves normally
- a reset at default scope returns `400`

# The measurement error worth recording

The first category test reported success. It had not worked.

The check used `GROUP_CONCAT` over the value rows — and `GROUP_CONCAT` **silently drops NULLs**, so a row rewritten to `NULL` was invisible and the query said the row was gone. It looked like a clean delete. The only reason it surfaced was that the store view resolved an empty name instead of the inherited one, which contradicted the query.

Two lessons kept in the module because they generalise:

- When verifying a **deletion**, use a query that can show a `NULL` row. An aggregate that hides them will confirm whatever you hoped.
- A result that contradicts a passing check is worth more than the check.

# Not tested

- Bulk and async routes.
- Entities other than products and categories.
- Behaviour on any version other than 2.4.8-p5.
