---
okf_version: "0.2"
type: Knowledge Bundle
title: BroCode_ScopeReset — Knowledge Bundle
description: A Magento 2 module that restores store-view attribute inheritance over REST using the CSV importer's empty-value constant.
resource: https://github.com/brosenberger/module-scope-reset
tags: [magento2, rest-api, eav, store-scope, integration]
generated:
  by: claude-opus-5
  at: 2026-08-26T00:00:00Z
status: stable
stale_after: 2027-08-26T00:00:00Z
---

# BroCode_ScopeReset — Knowledge Bundle

Magento's REST API cannot express *"make this attribute inherit from the default scope again"*. The admin can — the **Use Default Value** checkbox deletes the store-scoped row rather than writing anything — but a REST payload carries an attribute code and a value, with no third state.

This module adds that third state: sending Magento's own empty-value constant at store scope deletes the scoped row, for **products and categories**.

# Using it

* [Usage](usage.md) — the sentinel, where it goes in a payload, and what is refused
* [Limits](limits.md) — what is out of scope, what cannot work, and what ages

# Understanding it

* [Mechanism](mechanism.md) — how the EAV layer is persuaded to delete, and why the two entities hook different seams
* [Verification](verification.md) — what was executed against a running install, and the measurement error that nearly hid a bug

# Meta

* [Change Log](log.md)
