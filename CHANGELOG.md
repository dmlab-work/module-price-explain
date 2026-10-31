# Changelog

## 0.0.3
- CI: the Magento 2.4.7 integration job runs on Ubuntu 24.04 (newer libxml2 rejects 2.4.7's `esconfig.xsd`).
- Integration fixtures work with 2.4.7's cart rule fixture class and category-product URL rewrites.

## 0.0.2
- Installs on Magento 2.4.7+ and PHP 8.2–8.5 (`magento/framework ^103.0.7`, Magento modules at their 2.4.7 versions, `symfony/console ^6.4||^7.4`).
- Rule verdicts follow the installed core: before 2.4.8 a rule whose discount step exceeds the item qty is reported as applied with zero discount, and dynamic-bundle children are discounted before the parent; before 2.4.9 every rule's conditions see the undiscounted subtotal of the whole cart, including rules with "Apply to" actions.
- CI: unit tests on PHP 8.2–8.5 (PHPUnit `^11.5||^12.5`), integration tests on Magento 2.4.7 (PHP 8.3) and 2.4.9 (PHP 8.5); CI installs no longer send Packagist download notifications.
- Composer metadata: `homepage`, `support.issues`, `support.source`, keywords.
- README: problem → outcome intro, screenshot, install and requirements up front, implementation details under Architecture.

## 0.0.1
- Read-only price explanation of a quote, or of an order via its quote (evaluated with current rules and prices; differences from the placed order are reported).
- Item prices: base, special (with dates), tier/group, catalog rule (with rule names), custom options, the winning layer, custom price; unexplained deltas list third-party `catalog_product_get_final_price` observers; price index mismatch/missing row with indexer status.
- Totals collectors in `sales.xml` order with per-amount deltas and sort order; third-party collectors flagged; collectors without changes hidden (text/admin), always listed in JSON.
- Cart price rules: applied (per-item, shipping, free shipping) or rejected with stage and reason — inactive, website, customer group (incl. excluded website), dates, coupon, coupon/customer usage, failing condition nodes with expected vs actual (category/option names, Yes/No, de-duplicated values, per-item results for Found/Subselect), actions, discount step/qty, discarded by an earlier rule; reconciliation warnings against core's `applied_rule_ids`. Configurable/bundle children folded under the parent (`MP01 → MP01-32-Black`) in outcomes and in Found/Subselect trees (one row per visible item; differing parent/child actuals shown per member; JSON `item_ids`, `member_actuals`).
- Free shipping: granting rules (items/address mode), item and address flags, selected method and stored rate, carrier semantics; verdict `effective`, `not_honoured_by_carrier`, `partial`, `stale_rate`, `unknown_carrier`, `not_granted` or `no_method`, warning `free_shipping_not_effective`.
- Tax: product/customer tax class, address per `based_on` (with fallback source), applied rates per item and shipping, algorithm and config flags, reason for "no tax".
- Summary with store view, website and customer group names, customer or Guest; order reports name the order (number and placement date; JSON also status; admin links to the order view).
- Totals: subtotal, discount, shipping, tax and grand total of the recollection (JSON `totals`); for orders also the ordered amounts with differences marked. The `grand_total` collector row shows its change.
- Human labels in text and admin; JSON keeps the codes and adds `*_label` fields.
- CLI `dmlab:price:explain --quote|--order [--format=text|json] [--all-rules] [--all-collectors]`.
- Admin: Marketing → Promotions → Explain Prices (quote ID or order number, optional rules of other websites, JSON download), button on the order view, link per website cart in the customer's Shopping cart tab; ACL `DmLab_PriceExplain::explain`. Condition/action trees are collapsible, the failing one opens by default. The report runs on the storefront (signed single-use 60 s token, POST endpoint `dmlab_price_explain/report/json`, no-store, admin-origin CORS) and falls back to an admin-area run with an `area` warning.
- No writes: isolated quote, rolled-back transaction, stored shipping rates only.
- Integration test suite (Magento integration framework) and CI job on Magento 2.4.9.
