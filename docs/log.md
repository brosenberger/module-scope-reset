# Change Log

## 2026-08-26

### Categories

- Category support, hooked on `Magento\Catalog\Model\ResourceModel\Category::save()` rather than the repository, which reloads and repopulates the entity and so discards a repository-level plugin's changes
- Reset logic extracted into a shared `ScopeResetProcessor`
- The reset value is now per entity: `false` for product `varchar`/`text`/`datetime`, `null` for categories — matching what Magento's own admin controllers do
- Documented that customers cannot be supported: their EAV value tables have no `store_id` column

### Initial release

- Product support via a `before` plugin on `ProductRepositoryInterface::save()`
- Sentinel read from the CSV importer's `DEFAULT_EMPTY_ATTRIBUTE_VALUE_CONSTANT` rather than declared locally
- Guards for default scope, attributes outside the attribute set, and global-scope attributes
- OKF v0.2 documentation bundle
