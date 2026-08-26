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

# Unit tests

26 tests, 29 assertions, run against PHP 8.4 / PHPUnit 10.5. They cover what the integration testing above cannot repeat cheaply: the sentinel's strict comparison, all three guards, the per-entity reset value, and detection through both the data bag and the custom-attribute bag.

Two of them exist because the corresponding bug shipped: clearing only the data bag, and applying the product reset rule to a category.

Writing them also surfaced a latent type defect. The processor documented its attribute array as `Magento\Eav\Model\Entity\Attribute\AbstractAttribute[]` and called `isScopeGlobal()` on it — a method declared on `Magento\Catalog\Model\ResourceModel\Eav\Attribute`, not on the EAV base. It never failed at runtime, because products and categories both supply catalog attributes, so only a mock built against the documented type exposed it.

# Integration tests

The unit tests prove the processor decides correctly. They cannot prove the decision has the intended effect, because the effect is a deleted database row — so the integration suite asserts on the row itself.

Every assertion **counts rows** rather than reading the value back. An absent row and a `NULL` row both read back as an empty attribute, and that indistinguishability is precisely how the category bug survived its first test.

The category suite pins both of its development failures explicitly, and **passes**:

- `testResetDoesNotLeaveANullRowBehind` — the version that wrote `NULL` instead of deleting
- `testResetActuallyChangesSomething` — the version that silently did nothing, because it was hooked on a repository that discards the entity handed to it

Both returned success at the time. Neither would be caught by a unit test, and neither would be caught by asserting on the value.

Both suites pass: **9 integration tests, 16 assertions**, plus 26 unit tests.

Getting the product suite green came down to one thing, and every earlier theory about it was wrong. The failure surfaced as `The product was unable to be saved` — a generic wrapper that names nothing. Unwrapping `getPrevious()` gave the real cause: **`Rolled back transaction has not been completed correctly`**. A product save rolls back a nested transaction internally, and doing that inside the framework's `@magentoDbIsolation` transaction is illegal.

The fix is `@magentoDbIsolation disabled` on that class, relying on the fixture's rollback script for cleanup. Verified by running the full suite twice in a row: state left behind would fail the second run, and it does not.

Two hours went into fixture swapping and store emulation before anyone unwrapped the exception. **When a repository throws a generic "could not save", read `getPrevious()` first** — the wrapper is never the cause, and guessing at fixtures is guessing.

The same trap in a different costume: the resource model's `saveAttribute()` opens its **own** transaction, so reaching for it as a shortcut inside an isolated test fails the same way, pointing at code unrelated to the assertion.

# Running them

The integration framework installs a fresh Magento into its own database before the first test, driven by `dev/tests/integration/etc/install-config-mysql.php`. On a working development sandbox that install is where the friction is, and none of the failures name the real cause clearly:

| Symptom | Cause | Fix |
|---|---|---|
| `Parameter validation failed` with `Could not connect to the Amqp Server` | the shipped `.dist` declares `amqp-*` keys and `setup:install` validates them by *connecting* | remove those keys; the tests need no broker |
| `Unable to apply data patch …CatalogRuleSampleData…` / `Rolled back transaction has not been completed correctly` | sample-data modules are installed in the sandbox and their patches do not survive the framework's bare install | add `'disable-modules'` covering every `*SampleData` module |
| `Cannot instantiate interface …TwoFactorAuth\Api\UserConfigManagerInterface` | the test install reuses the sandbox's compiled DI, which no longer matches the reduced module set | disable `Magento_TwoFactorAuth` and the Adobe IMS modules too |

All three are properties of the host installation rather than of these tests, and all three cost more time than writing the tests did. A CI runner installing Magento from scratch hits none of them.

# Not tested

- Bulk and async routes.
- Entities other than products and categories.
- Behaviour on any version other than 2.4.8-p5.
