# BroCode_ScopeReset

Restore store-view attribute inheritance over the Magento 2 REST API by sending the CSV importer's empty-value constant.

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
# restore inheritance for a first-class field
curl -s -X PUT "$BASE/rest/zurich_view/V1/products/ERP-1001" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"product":{"sku":"ERP-1001","name":"__EMPTY__VALUE__"}}'

# ...or for an EAV attribute
curl -s -X PUT "$BASE/rest/zurich_view/V1/products/ERP-1001" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"product":{"sku":"ERP-1001","custom_attributes":[
        {"attribute_code":"description","value":"__EMPTY__VALUE__"}]}}'
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

## Scope

Products only. Categories and customers have the same inheritance problem and are not covered — the plugin point differs per repository, and shipping an untested one would be worse than shipping none.

## Verified on

Magento Open Source **2.4.8-p5**, PHP 8.4, one website with four store views. Every claim above was executed against a running install: targeted reset of one store view leaving its sibling untouched, repeated resets returning `200` without side effects, ordinary saves unaffected, and both guard paths returning `400`.

## Licence

MIT — see [LICENSE](LICENSE).
