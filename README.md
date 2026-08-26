# BroCode_ScopeReset

Restore store-view attribute inheritance for **products and categories** over the Magento 2 REST API by sending the CSV importer's empty-value constant.

## The problem

Magento's REST API has no way to express *"make this attribute inherit from the default scope again"*. The admin has one — the **Use Default Value** checkbox, which deletes the store-scoped row rather than writing anything — but a REST payload has an attribute code and a value, and no third state.

So an integration that accidentally writes a store-view override cannot undo it:

| Sent to `/rest/{store}/V1/products/{sku}` | Result |
|---|---|
| the attribute omitted | previous override kept, untouched |
| `"value": ""` | override rewritten as an empty string — the storefront shows *blank*, not the inherited value |
| `"value": null` | stripped by the serializer, or rejected as a type error |
| the global value repeated | override still exists, now coincidentally equal — and it stops tracking later changes to the global value |

That last row is the one that rots quietly. Six months later somebody edits the global value and one store view keeps the old text forever, for no visible reason.

## What this module does

Sending Magento's own empty-value constant as an attribute value at store scope deletes the store-scoped row, exactly as the admin checkbox does.

```bash
# a product's first-class field
curl -s -X PUT "$BASE/rest/zurich_view/V1/products/ERP-1001" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"product":{"sku":"ERP-1001","name":"__EMPTY__VALUE__"}}'

# ...or an EAV attribute
curl -s -X PUT "$BASE/rest/zurich_view/V1/products/ERP-1001" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"product":{"sku":"ERP-1001","custom_attributes":[
        {"attribute_code":"description","value":"__EMPTY__VALUE__"}]}}'

# categories work the same way
curl -s -X PUT "$BASE/rest/zurich_view/V1/categories/42" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"category":{"id":42,"name":"__EMPTY__VALUE__"}}'
```

The sentinel is **not** hardcoded. It is read from `Magento\ImportExport\Model\Import::DEFAULT_EMPTY_ATTRIBUTE_VALUE_CONSTANT`, which is `__EMPTY__VALUE__` on a default install — the same value the CSV importer already accepts, so an integration only learns one magic string whichever way it loads data.

## Where the sentinel goes in the payload

This trips people up, and it is Magento's rule rather than this module's: **a first-class field must be sent top-level, and an EAV attribute inside `custom_attributes`.**

| Attribute | Send as |
|---|---|
| `name`, `price`, `status`, `visibility`, `weight`, `url_key`, … | top-level product field |
| `description`, `meta_title`, `short_description`, custom attributes | inside `custom_attributes` |

Putting a first-class field into `custom_attributes` is silently ignored by the deserializer — with or without this module — so the sentinel never arrives and the override survives.

## Guards

- **Default scope is refused.** Resetting at `/rest/all/` or a scopeless route returns a `400`: there is no parent scope to inherit from, and deleting the global row would orphan every store view.
- **Attributes outside the product's attribute set are refused**, rather than accepted and silently dropped the way a normal write would be.
- **Global-scope attributes are refused.** In practice a typed field like `price` is rejected by the deserializer's type check before this module sees it, which is equally loud and arguably clearer.

Resetting an attribute that is already inheriting is a no-op and returns `200`, so a retried batch is safe.

## How it works

A `before` plugin on `ProductRepositoryInterface::save()` translates the sentinel into the value the EAV layer deletes on, mirroring `AttributeFilter::prepareDefaultData()` in Magento's own adminhtml controller: boolean `false` for `varchar`/`text`/`datetime` backends, `null` for the rest. `AbstractEntity::_processSaveData()` then issues a `DELETE` for the scoped row instead of a write.

Two details that are easy to get wrong if you reimplement this:

- The save-time validator rejects the sentinel on a **required** attribute, so `is_required` is relaxed for that save — which is exactly what the admin form does.
- Both the data bag **and** the custom-attribute bag have to be cleared. Setting only `_data` leaves the sentinel in `custom_attributes`, which is re-applied over `_data` during the save and writes the literal string `__EMPTY__VALUE__` into the row.

## Install

```bash
composer require brocode/module-scope-reset
bin/magento module:enable BroCode_ScopeReset
bin/magento setup:upgrade
bin/magento cache:flush && bin/magento setup:di:compile
```

## Why categories hook somewhere else

Worth knowing if you extend this, because the obvious seam is wrong.

The product plugin sits on `ProductRepositoryInterface::save()`. The category plugin **cannot**: `CategoryRepository::save()` serialises the incoming object with `toNestedArray()`, then *replaces* it with a freshly loaded one and repopulates that from the array. Anything a repository-level plugin sets is discarded — and discarded inconsistently, because the serialiser drops a `null` while carrying a `false` through to be written as a `NULL` row. Both failure modes return `200`.

So the category plugin hooks `Magento\Catalog\Model\ResourceModel\Category::save()` instead, where the entity is final and its original data is loaded — which is what the EAV layer needs in order to *delete* the row rather than write one.

The two entities also disagree about which value triggers the delete, and Magento's own admin controllers disagree the same way: the product form substitutes boolean `false` for `varchar`/`text`/`datetime` backends, while the category controller uses `null` for everything. The processor takes that as a parameter rather than assuming.

## Customers are not supported, and cannot be

Not an omission — customer attribute values have no store scope to reset.

`customer_entity_varchar`, `_int`, `_text`, `_datetime` and `_decimal` have **no `store_id` column** at all, unlike their catalog equivalents. `customer_eav_attribute` has no scope flag either. The only per-scope thing in the customer EAV model is `customer_eav_attribute_website`, and that scopes attribute *metadata* — visibility, requiredness, default value, multiline count — not values. The `store_id` on `customer_entity` records which store the account was created in; it does not scope anything.

There is therefore no scoped row to delete, and a customer plugin would be a no-op wearing a useful-looking name.

## Verified on

Magento Open Source **2.4.8-p5**, PHP 8.4, one website with four store views. Every claim above was executed against a running install, for **products and categories**, via both a first-class field and an EAV attribute:

- a targeted reset restores inheritance on one store view and leaves its sibling untouched
- the scoped row is **deleted**, not rewritten to `NULL` — checked with a query that shows `NULL` rows, because `GROUP_CONCAT` silently hides them and will tell you the row is gone when it is not
- repeated resets return `200` with no further effect
- ordinary saves are unaffected
- default scope returns `400`

## Tests

**Unit** — 26 tests, no Magento bootstrap needed beyond the framework autoloader:

```bash
vendor/bin/phpunit -c app/code/BroCode/ScopeReset/phpunit.xml.dist
```

They cover the sentinel's strictness, all three guards, the per-entity reset value, and detection through both the data bag and the custom-attribute bag — and deliberately pin two bugs this module had during development: clearing only the data bag, and applying the product reset value to a category.

**Integration** — the tests that matter, because the module's whole job is a side effect on a database row:

```bash
# needs dev/tests/integration/etc/install-config-mysql.php configured
vendor/bin/phpunit -c dev/tests/integration/phpunit.xml.dist \
  app/code/BroCode/ScopeReset/Test/Integration
```

Every assertion counts the scoped **row** rather than reading the value back, because an absent row and a `NULL` row both read back as empty — which is precisely how the category bug hid. The category suite pins both of its development failures: the NULL row, and the version that silently did nothing because it was hooked on a repository that discards the entity it is handed.

## Documentation

An [OKF v0.2](https://github.com/GoogleCloudPlatform/knowledge-catalog/blob/main/okf/SPEC.md) knowledge bundle lives in [`docs/`](docs/index.md): [usage](docs/usage.md), [mechanism](docs/mechanism.md), [limits](docs/limits.md) and [verification](docs/verification.md).

## Licence

MIT — see [LICENSE](LICENSE).
