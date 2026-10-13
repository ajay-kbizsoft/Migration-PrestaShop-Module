# Kbizsoft MigrationPro: Migrate & Upgrade

This Kbizsoft-branded PrestaShop 8/9 module is a reusable starting point for connecting a
destination store to a source PrestaShop store. The current version saves the
source URL and Webservice API key, tests the connection, checks source read
permissions, and imports categories in resumable batches. Other data types are
also imported in resumable batches for products, combinations, images, and
stock. Customer profiles and addresses can also be imported in resumable
batches. The Configure page separates each importer into its own tab, with a
remaining-record progress bar in each tab. Voucher-code cart rules can also be
migrated in resumable batches.

## Install

1. Keep the `kbizmigration` directory together with its subdirectories.
2. For a ZIP upload, zip the `kbizmigration` directory so that
   `kbizmigration/kbizmigration.php` is at the root of the archive.
3. In the destination store's Back Office, go to **Modules > Module Manager** and
   upload/install the module. During local development, the directory can instead
   be copied into the store's `modules` directory and installed from Module
   Manager.
4. Open the module's **Configure** page.

## Prepare the source store

1. On the source store, go to **Advanced Parameters > Webservice** and enable
   the Webservice API.
2. Create a new API key. Use HTTPS for a remote source store.
3. Grant only the resource permissions needed for the migration. The product
   importer reads categories, products, combinations, product options, product
   option values, stock availables, and languages. Customer migration reads
   customers, addresses, countries, and states. Order migration reads orders,
   order details, order histories, order states, currencies, and languages.
   Cart-rule migration reads cart rules, currencies, and languages.
   Grant read-only (`GET`) access for resources used in each stage.
4. Copy the source store's base URL and API key into the module configuration on
   the destination store, then select **Test connection**.

Use the source store's final HTTPS URL. If the entered HTTP URL redirects to
HTTPS on the same host, the connection test follows that redirect without
sending the API key over HTTP. Redirects to another host or back to HTTP are
rejected; enter the final HTTPS host directly in that case.

After testing the connection, use **Check migration permissions** to verify
GET access for categories, products, combinations, product options, product
option values, stock availables, customers, addresses, countries, states,
orders, order details, order histories, order states, cart rules, currencies,
and languages. This preflight does not import or modify any store data.

Use **Preview and compare categories** to see the total source category count,
the matched/new totals, and a paginated list of source categories compared with
the destination by translated name and mapped parent. Preview does not create or
change categories.

The **Categories** tab shows category-specific details and a progress bar. The
source category count is calculated from the Webservice API and cached when
first needed; the bar reports processed and remaining source records. The
category migration panel imports categories ten at a time. Save the source URL
and API key first, then select **Import next category batch** until the progress
status is complete. Source root and Home categories are mapped to
the destination store's own root and Home categories; a category with the same
name under the mapped parent is skipped. Imported categories are recorded by
source store and source ID so batches can resume safely. The module also lists
the saved imported-category mappings, including categories missing from or not
associated with the currently selected destination shop. Available category
names link to their Back Office pages.
Top-level source categories are placed below the destination shop's Home
category, rather than beside it under the global root, so they remain inside
the shop's category tree.

If categories saved in the migration mapping have since been deleted or are no
longer associated with the current shop, selecting **Import next category
batch** automatically resumes or rewinds the source scan as needed and restores
the missing categories before the migration can be considered complete.
Existing valid mappings are reused, so this recovery does not create duplicate
categories.

The **Products** tab shows its own source total and remaining-record progress
bar. The **Product migration** panel imports products five at a time and checks
for unfinished category batches first. It maps source categories, product option
groups and values, combinations, images, and stock to the destination store.
Products with a reference already present in the destination are skipped; only
products previously mapped as imported by this module are updated on retry.
Prices are copied as-is, so the source and destination must use the same
currency. The destination's most-used tax rule group is applied; source tax
rules are not copied. Stock quantities from the source replace destination
quantities for imported products and combinations.

The **Customers** tab imports customer profiles five at a time, including
addresses. Customers whose email already exists in the destination are skipped;
only customers previously mapped as imported by this module are updated on
retry. Address countries are matched by ISO code. Address states are matched by
ISO code within the mapped country; if a source state is missing in the
destination, it is created using the source state name and the destination
country's zone. Customer group membership is not transferred; imported
accounts use the destination store's default customer group.

Passwords cannot be read through the source Webservice API. Each newly imported
customer receives a generated password unknown to them and must use **Forgot
password** on the destination store to set one. Existing mapped customer
passwords are not changed. New destination customers start unsubscribed from
newsletter and marketing opt-in; source consent flags are not transferred.
Account active status is copied from the source.

The **Orders** tab imports orders three at a time after customer and product
migration. It uses this module's customer, address, product, and combination
mappings; finish those earlier stages first. It copies order totals without
currency conversion. If the matching currency exists and is active in the
destination, the importer automatically associates it with the current shop
when needed. Add missing currencies or activate disabled currencies under
**International > Localization > Currencies** before retrying. Source order
states are matched to non-deleted destination states by translated name; add
any missing matching state before retrying.

Order lines, source references, payment-method description, totals, and order
status history are copied. Destination orders use the destination shop's
customer, address, currency, and product IDs. The importer does not create
payment records, capture or refund money, transfer payment credentials, create
invoices or shipping labels, or send order emails/status notifications.
The source payment-method description is saved in the destination order note.
No source carrier is assigned to the destination order. Historical invoice and
delivery numbers are not recreated. An order and its
lines/history are written transactionally, and source-to-destination mappings
make retries safe.
Historical line tax names and rates are retained, but source tax-ID
associations are not recreated; destination order lines use the mapped
product's local tax rule group.

The **Cart rules** tab imports voucher-code rules five at a time. It copies
translated names, validity dates, quantities, minimum amounts, discounts, free
shipping, and active status without currency conversion. Product or gift
references are resolved through product and combination mappings. A voucher
code already present in the destination is not overwritten. Rules without a
code, customer-specific rules, rules with country, carrier, group, product,
shop, or other cart-rule restrictions, and rules using custom discount types
are skipped because the source Webservice does not expose enough information to
recreate their eligibility conditions safely. Applied coupon usage, historical
redemption counts, and order-to-coupon associations are not migrated.

Select **Re-import/update mapped categories** to refresh previously imported
categories from the source in resumable batches. This updates their translated
names, descriptions, URL slugs, active status, and mapped parent. It does not
delete categories or update destination categories that were skipped because a
matching category already existed. Select the button again after completion to
start a fresh refresh. If a previously imported destination category was
deleted, re-import recreates it and updates its saved mapping. Re-import also
associates existing mapped categories with the currently selected shop.

The API key is stored in the destination store's PrestaShop configuration table
and is never rendered back into the settings form. Give the key only to trusted
employees and revoke it on the source store when it is no longer needed.

## Reuse on another store

Install the same module ZIP on each destination store and configure that
destination with the appropriate source URL and key. Do not put store URLs,
credentials, or data mappings into the module source code.

## Implementation map

- `kbizmigration.php` defines the `Kbizmigration` module and its Back
  Office settings.
- `classes/KbizMigrationSourceWebserviceClient.php` defines
  `KbizMigrationSourceWebserviceClient`, which validates the source URL,
  tests the authenticated connection, checks source resource read access, and
  fetches XML resources.
- `classes/KbizMigrationCategoryImporter.php` imports category batches through
  PrestaShop's `Category` model and stores source-to-destination mappings and
  progress in module-owned tables.
- `classes/KbizMigrationProductImporter.php` imports product batches,
  combinations, option values, images, and stock with source-to-destination
  mappings.
- `classes/KbizMigrationCustomerImporter.php` imports customer profiles and
  addresses in resumable batches, matching destination countries/states by ISO
  code and tracking mappings separately.
- `classes/KbizMigrationOrderImporter.php` imports orders, mapped order lines,
  and status history in resumable, transactional batches without invoking
  payment or notification workflows.
- `classes/KbizMigrationCartRuleImporter.php` imports supported voucher-code
  cart rules in resumable batches and skips rules whose eligibility
  restrictions cannot be safely mapped.
