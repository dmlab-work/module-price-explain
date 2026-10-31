# Integration tests

Run on Magento's integration framework against a dedicated database. The module must sit at
`<magento>/m2-modules/module-price-explain` (the bootstrap path in `phpunit.xml` is relative).

## One-time setup (magento.loc docker stack)

1. Create the database:
   ```
   docker exec magento-mysql mysql -uroot -p<MYSQL_ROOT_PASSWORD> -e "CREATE DATABASE magento_integration_tests"
   ```
2. Copy `src/dev/tests/integration/etc/install-config-mysql.php.dist` to `install-config-mysql.php` and set:
   `db-host` `mysql`, `db-user` `root`, `db-password` from `.env`, `opensearch-host` `opensearch`,
   `opensearch-index-prefix` `magento_integration` (keeps the dev store's indices intact), remove the `amqp-*` keys,
   and `disable-modules` = all `*SampleData` modules, `Magento_TwoFactorAuth` and the other `DmLab_*` modules.

The first run installs the sandbox into `dev/tests/integration/tmp/sandbox-*` (a few minutes). `TESTS_CLEANUP` is
disabled, so later runs reuse it; delete the sandbox directory to reinstall after schema changes.

## Run

```
docker exec -w /var/www/html/dev/tests/integration magento-app \
  php ../../../vendor/bin/phpunit -c /var/www/html/m2-modules/module-price-explain/Test/Integration/phpunit.xml
```

Magento 2.4.7 ships PHPUnit 9 and needs MySQL 8.0 / OpenSearch 2 and libxml2 < 2.13 (newer versions reject its
`esconfig.xsd`): use `phpunit9.xml` there.

## Fixtures

`_files/*.php` (each with a `_rollback.php`), applied with `#[DataFixture('DmLab_PriceExplain::Test/Integration/_files/…')]`.
Tests run with `DbIsolation(false)`: the explainer's own transaction rollback is what is under test.

| File | Creates |
|---|---|
| `catalog.php` | category `PE Sale` + 20% catalog rule; `pe-simple` $100, `pe-untaxed` $20 (tax class None), `pe-tier` $50 (qty 2: $35), `pe-special` $80 (special $60 from +10 days), configurable `pe-conf` (`pe_color` Red $30 / Blue $35); sets `catalog/seo/generate_category_product_rewrites` to 1 (restored on rollback) |
| `cart_rules.php` | `PE Coupon once` (`PE_ONCE`, 1 use per customer), `PE Free shipping address` (`PE_FS_ADDR`), `PE Free shipping items` (`PE_FS_ITEMS`), `PE Subtotal 200+`, `PE Step 3` (`pe-tier`, `discount_step` 3), `PE Stop A` (`pe-simple`, stops processing), `PE Stop B`, `PE Found blue` (Found: SKU is `pe-conf-blue`) |
| `tax_us_tx.php` | 8.25% rate + rule for US-TX |
| `quote.php` | guest quote `pe_quote`: `pe-simple` ×1 + `pe-conf` Red ×1 to Austin, TX, Flat Rate (core default $5 per item) |

Scenario tests extend `ScenarioTestCase`, which builds a guest quote per test (`pe_scenario`, deleted in `tearDown`)
and checks that the explained rule IDs per item equal core's `quote_item.applied_rule_ids`.
