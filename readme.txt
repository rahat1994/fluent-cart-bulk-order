=== Fluent Cart Bulk Order ===
Contributors: rahatbaksh
Tags: wholesale, b2b, bulk order, fluentcart, quote
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: fluent-cart
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Wholesale and B2B ordering for FluentCart: bulk order forms, quantity pricing, order rules, quote requests, PO numbers and trade applications.

== Description ==

Fluent Cart Bulk Order is an add-on for [FluentCart](https://wordpress.org/plugins/fluent-cart/). It gives a FluentCart store the things a trade buyer needs and a retail checkout does not: a way to order twenty products in one go, a price that drops as the quantity rises, and a set of rules about how much may be ordered at all.

Everything is gated by user role, so your retail shop stays exactly as it is. Only the roles you allow ever see a bulk ordering surface or a wholesale price.

= Ordering surfaces =

* **Bulk order form** — one table where a buyer adds line after line, searching by product name or SKU, picking a variant, and setting a quantity. The running total updates as they type, then the whole order goes to checkout in one action.
* **Quick order by paste or CSV** — paste "SKU, quantity" lines, or upload a CSV, and the form fills itself. Rows that do not match a SKU are reported rather than silently dropped.
* **Product table** — the catalog as a list with a quantity box on every row, for buyers who prefer to browse. Choose which columns show, filter to one category, page it, and open variant rows by default or on click.
* **Saved orders and reorder** — a buyer can name and keep a basket, then reorder it later in one click. A saved order stores products and quantities, never prices, so it is re-priced against your live catalog every time it is opened. It also lists the buyer's own past orders, so any of them can be reordered too.

= Pricing =

* **Bulk pricing tiers** — map a quantity range to a discount: a percentage off, a fixed per-unit price, or a flat amount off each unit. Set them store-wide or per product, and restrict a set to specific variants.
* **Role-scoped price lists** — give different roles different tiers for the same product, with an "everyone" set as the fallback.
* **Savings messaging** — the form, the cart and the checkout summary tell the buyer what the discount was worth, and nudge them toward the next tier ("add 4 more to unlock 10% off").
* **Tiers on the product page** — a single product can show its tier table plus a small order table, so a shopper who lands there sees the quantity breaks without leaving.
* **One price, twice checked** — the price the surface quotes and the price the cart charges are resolved by the same rules against the same quantity. Where a surface cannot be sure the two agree, it shows retail prices rather than a discount that will not be honoured.

= Order rules =

* **Minimum quantity** and **case-pack step** per product variant — an out-of-rule quantity is rounded up and the buyer is told, never rounded down and never silently.
* **Minimum order total** for the roles you choose.
* Rules are enforced on the server, not only in the browser, so a hand-crafted request is refused too.

= Trade accounts =

* **Wholesale application form** — a shortcode a signed-in shopper can use to apply for a trade account. Ask for a company name and a tax or VAT ID plus any questions you add yourself: single line, paragraph, choose-one or tick box, required or not.
* **Review screen** — applications land under Users with a pending count in the menu. Approving one grants the wholesale role; the applicant and the store owner are emailed either way.
* **FluentCRM tagging** — if FluentCRM is active, apply a tag when an application is received and another when it is approved, so your email automations can pick it up.

= Quotes, PO numbers and paperwork =

* **Request a quote** — a buyer can send their assembled order to be priced instead of bought. You set a price per line, accept or decline, and an accepted quote is converted into an ordinary unpaid FluentCart order at the prices you quoted.
* **PO number at checkout** — off, optional or required, for all shoppers or only the roles you pick. Required means the server refuses the checkout without one, not just the browser. The number is printed back on the receipt, in the buyer's account, and in both export formats.
* **Order export** — any single order as a CSV for an accounts system, or as a receipt to file. Spreadsheet formula injection is defended against in every cell. An export link is refused unless the requester owns the order or holds the store's own capability.

= Reporting =

* **Bulk order analytics** — how much revenue came through bulk ordering versus normal checkout, which buyers are behind it, and which quantity tiers are actually being reached. Tier utilisation is a join of every tier you have configured against the ones that were used, so the tier nobody has ever reached shows up as a zero instead of being missing from the list.

= Placing the surfaces =

Four shortcodes:

* `[fluent_cart_bulk_order]`
* `[fluent_cart_product_table]`
* `[fluent_cart_saved_orders]`
* `[fluent_cart_wholesale_application]`

The bulk order form and the product table are also a **Gutenberg block** each and an **Elementor widget** each, with the same options as controls. Both wrappers render through the shortcodes, so they behave identically and inherit the same role gates and store-wide defaults.

= Privacy =

The plugin makes no external HTTP requests, sends nothing to any third-party service, and adds no tracking. All data it stores stays in your own WordPress database.

== Installation ==

1. Install and activate [FluentCart](https://wordpress.org/plugins/fluent-cart/) first. This plugin does nothing without it.
2. Install Fluent Cart Bulk Order from Plugins → Add New, or upload the plugin folder to `/wp-content/plugins/`.
3. Activate the plugin through the Plugins screen.
4. Go to **Settings → Bulk Order** and choose which user roles may use the bulk ordering surfaces and which roles receive bulk pricing.
5. Add `[fluent_cart_bulk_order]` to a page, or drop the **Bulk Order Form** block on it.
6. Set up your quantity tiers in FluentCart under the Bulk Pricing integration — store-wide, or on a single product.

The plugin's admin screens are:

* **Settings → Bulk Order** — roles, store-wide surface defaults, order rules, PO number, quotes and the wholesale application form.
* **Settings → Bulk Order Analytics** — bulk revenue, top buyers, tier utilisation.
* **Settings → Quote Requests** — price, accept or decline a quote.
* **Settings → Order Exports** — export a single order.
* **Users → Wholesale Applications** — approve or reject trade account applications.

== Frequently Asked Questions ==

= Do I need FluentCart? =

Yes. This is an add-on and it will not activate without FluentCart. Every surface it adds reads FluentCart's catalog and hands off to FluentCart's cart and checkout.

= Who can see the bulk order form? =

Administrators and the roles you allow under Settings → Bulk Order. Everyone else sees nothing on the page. The REST endpoints behind the surfaces apply the same rule, so the gate is not just the page.

The wholesale application form is the one exception: it is meant for shoppers who do *not* have access yet, so any signed-in user can see it. Someone who is still waiting, or who was turned down, sees the form again so they can correct an answer or reapply — a second submission replaces the first rather than queueing another. Someone already approved sees a confirmation and no form.

= How do I set up quantity discounts? =

Tiers live on FluentCart's integration settings, either store-wide or on one product. Each tier is a quantity range plus a discount — a percentage, a fixed per-unit price, or an amount off each unit. A product's own tiers take precedence over the store-wide set, and inside the winning set the most specific matching range wins.

= Will the discount the form shows match what the buyer is charged? =

Yes. The discount is applied again on the cart line itself, priced against the quantity the buyer will actually be billed for. If a surface cannot be certain the two will agree — for example when the shopper's role does not qualify for bulk pricing at all — it shows retail prices instead of promising a discount that checkout would not honour.

= Can I force case packs or a minimum order? =

Yes. Set a minimum quantity and a quantity step per product variant, and a minimum order total for the roles you choose. The ordering surfaces round an out-of-rule quantity up and say so; the server independently refuses a non-conforming quantity, so the browser is a convenience and the server is the authority.

= Can buyers pay by purchase order? =

The plugin collects the buyer's PO number at checkout and prints it back on their receipt, in their account and in both export formats. It does not add a "pay later on invoice" payment method — that is FluentCart's side of the checkout.

= Can a buyer ask for a price instead of buying? =

Yes, if you turn on "Request a quote". The buyer sends their assembled order, you set a price per line, and an accepted quote is converted into an ordinary unpaid FluentCart order at those prices. A quote is also the one way to ask for a subscription product and a one-time product in the same request.

= Does it work with the block editor and Elementor? =

Yes. The bulk order form and the product table each ship as a block and as an Elementor widget, with the same settings as visual controls. Both render through the shortcode, so nothing behaves differently depending on how you placed it.

= Does it work with FluentCRM? =

If FluentCRM is active you can apply one tag when a wholesale application arrives and another when it is approved. Nothing else about the plugin depends on FluentCRM.

= What happens to my data if I delete the plugin? =

Deactivating removes nothing at all.

Deleting removes the plugin's own scaffolding: its settings, the wholesale role it created, stored wholesale applications, quote records and the analytics table.

Three things are kept on purpose. Your per-product bulk pricing tiers stay, so reinstalling picks them straight back up. Your customers' saved orders stay, because deleting another person's data on an admin's click is not this plugin's call. PO numbers stay on the orders they belong to, because they are part of a completed sale. Orders, customers and products are FluentCart's and are never touched.

= Can I report analytics on orders taken before I installed this? =

No, and deliberately so. A past order's price records what was charged, not which of several overlapping tiers produced it, and the tier that produced it may since have been edited. Reporting starts from the day the plugin is installed rather than showing a guess dressed as history.

== Screenshots ==

1. The bulk order form: SKU search, per-line quantities, tier savings and a running total.
2. Quick order — pasting "SKU, quantity" lines or uploading a CSV.
3. The product table with per-row quantity entry and expandable variant rows.
4. Bulk pricing tiers on a single product page, with the quantity order table.
5. Settings → Bulk Order: role access, bulk pricing roles and store-wide defaults.
6. Settings → Bulk Order Analytics: bulk revenue split, top buyers and tier utilisation.
7. Users → Wholesale Applications: reviewing and deciding an application.
8. Settings → Quote Requests: pricing a buyer's quote line by line.

== Changelog ==

= 1.1.0 =
* First release on the WordPress.org plugin directory.
* Bulk order form with SKU search, per-line quantities and a running total.
* Quick order by pasted "SKU, quantity" lines or CSV upload.
* Product table with configurable columns, category filter, paging and expandable variant rows.
* Saved orders, plus reorder from a saved order or from a past purchase.
* Bulk pricing tiers — percentage, fixed per-unit price or amount off — store-wide or per product, optionally scoped to a user role and restricted to specific variants.
* Savings messaging on the form, in the cart and on the checkout summary, including a nudge toward the next tier.
* Bulk pricing tiers shown on the single product page.
* Order rules: per-variant minimum quantity and case-pack step, plus a store-wide minimum order total for chosen roles, enforced on the server.
* Role-based access gating for every surface and every REST endpoint.
* Wholesale application flow with a configurable question set, an admin review screen, notification emails and optional FluentCRM tagging.
* Request a quote, with owner-set line prices and conversion to an unpaid FluentCart order.
* PO number at checkout — off, optional or required, per role.
* Single-order export as CSV or a printable/PDF receipt, with formula-injection defence and an ownership check on every export URL.
* Bulk order analytics: revenue split, top buyers and tier utilisation.
* Settings screen for roles, store-wide surface defaults, order rules, PO numbers, quotes and the application form.
* Gutenberg blocks and Elementor widgets for the bulk order form and the product table.
* Full translation template shipped in `/languages`.

= 1.0.1 =
* Pre-directory release. Bulk order shortcode and the first bulk pricing integration. Never published to WordPress.org.

== Upgrade Notice ==

= 1.1.0 =
First WordPress.org release. If you installed an earlier build by hand, review Settings → Bulk Order after updating: role access and bulk pricing roles are now set there rather than assumed.
