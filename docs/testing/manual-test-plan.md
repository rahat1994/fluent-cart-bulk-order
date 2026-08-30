# Manual Test Plan — Fluent Cart Bulk Order

Every feature the plugin ships, with steps you can follow by hand and the result
you should see. Work top to bottom the first time: section 0 builds the test data
that later sections need.

Mark each case **Pass**, **Fail** or **N/A**. When a case fails, note the user
role, the browser, and the exact screen — most bugs here are role- or
surface-specific.

---

## 0. Setup

### 0.1 Environment

| Thing | Value |
|---|---|
| WordPress | 6.7 or newer |
| PHP | 7.4 or newer |
| FluentCart | installed and active |
| FluentCRM | installed (needed only for §8.6); test once without it too |
| Elementor | installed (needed only for §14.2) |

Turn on `WP_DEBUG` and `WP_DEBUG_LOG`. **No PHP notice, warning or fatal may
appear in the log during any case below.** Keep the browser console open for the
same reason.

### 0.2 Test users

Create one user per row and keep them signed in in separate browsers or profiles.

| User | Role |
|---|---|
| `admin_u` | Administrator |
| `wholesale_u` | Wholesale Customer (created by the plugin on activation) |
| `retail_u` | Customer / Subscriber |
| `extra_u` | Editor (used to test the "also allow these roles" setting) |
| logged out | no account |

### 0.3 Test catalog

In FluentCart create:

1. **P1 — Simple product**, one variant, SKU `P1-A`, price 10.00.
2. **P2 — Variable product**, three variants: `P2-S` 20.00, `P2-M` 25.00, `P2-L` 30.00.
3. **P3 — Out of stock product**, stock 0, SKU `P3-A`.
4. **P4 — Subscription product** (for §11.8).
5. Put P1 and P2 in a category called **Trade**; leave P3 out of it.

### 0.4 Test pages

Create one page per shortcode and note the URLs:

| Page | Content |
|---|---|
| `/bulk-order` | `[fluent_cart_bulk_order]` |
| `/product-table` | `[fluent_cart_product_table]` |
| `/saved-orders` | `[fluent_cart_saved_orders]` |
| `/apply` | `[fluent_cart_wholesale_application]` |

### 0.5 Build the zip you will test

Test the **zip a store would upload**, not your working checkout — the checkout
carries `vendor/`, `tests/` and `docs/`, and a bug that only shows up when those
are missing is exactly the bug this catches.

```bash
composer build          # same thing: bin/build-zip.sh
```

It writes `build/fluent-cart-bulk-order-<version>.zip` and prints the top level
of the archive so you can check it before uploading.

*Expect the archive to hold only:* `fluent-cart-bulk-order.php`, `uninstall.php`,
`includes/`, `assets/`, `blocks/`, `languages/`, `readme.txt`, `LICENSE` — all
inside a single `fluent-cart-bulk-order/` folder. Nothing else. If you see
`vendor/`, `tests/`, `docs/`, `composer.json` or `bin/`, stop and fix
`.distignore` before testing anything.

The script refuses to build when the `Version:` header, `FCBO_VERSION` and
readme.txt's `Stable tag:` do not all match. That refusal is a real failure —
fix the versions, do not work around it.

Upload it at **Plugins → Add New → Upload Plugin**.

---

## 1. Install, activate, deactivate, delete

**1.1 Install from the zip**
Build with `composer build` (§0.5) and upload `build/fluent-cart-bulk-order-<version>.zip`
at Plugins → Add New → Upload Plugin.
*Expect:* WordPress accepts it, the folder installs as `fluent-cart-bulk-order`,
and the Plugins row shows the right name, version and description. No "the
package could not be installed" error.

**1.2 Upgrade over an existing copy**
With an older version already installed, upload a newer zip.
*Expect:* it replaces the old one, keeps every saved setting, and the version in
the Plugins row is the new one.

**1.3 Missing dependency**
Deactivate FluentCart. Go to Plugins.
*Expect:* an admin error notice saying FluentCart is required. No fatal error.

Then open each of the four pages holding a plugin shortcode — twice, because
they are supposed to show two different things.
*Expect signed out, or signed in as `wholesale_u`:* the page renders normally
with the surface region simply empty. Never a raw `[fluent_cart_bulk_order]`
tag in the content, never a PHP error.
*Expect as `admin_u`:* one sentence in place of each surface saying FluentCart
must be installed and active. This is the only place that message appears on the
front end, and only administrators get it.

**1.4 Activation**
Activate FluentCart, then this plugin.
*Expect:* no error. Users → Add New shows a **Wholesale Customer** role in the
role dropdown. **Bulk Order → Settings** exists.

**1.4a The admin menu**
Look at the sidebar as `admin_u`.
*Expect:* ONE top-level **Bulk Order** entry, under FluentCart, with exactly
five submenus in this order — Settings, Quote Requests, Wholesale Applications,
Analytics, Order Exports. No second "Bulk Order" row above Settings, and nothing
of ours left under Settings or Users. Open each one; each loads its own screen.
With a waiting quote or application, the count shows on both the submenu row and
the **Bulk Order** row itself.
*Also:* `/wp-admin/options-general.php?page=fcbo-settings` and
`/wp-admin/users.php?page=fcbo-wholesale-applications&status=pending&paged=2` —
the addresses the screens used to have — redirect to the new ones instead of
failing, **and the second one still arrives on page 2 of the pending list**.
Losing `status` and `paged` here would look like the redirect worked while
showing a different list than the link promised — and the review emails this
plugin has already sent carry exactly that kind of URL.

**1.5 Deactivate**
Deactivate the plugin, then reactivate it.
*Expect:* every setting you saved is still there. The Wholesale Customer role is
still there and users still hold it.

**1.6 Delete (do this LAST, on a throwaway site)**
Delete the plugin from the Plugins screen.
*Expect, removed:* `fcbo_apply_to_roles`, `fcbo_min_order_total`,
`fcbo_min_order_total_roles`, `fcbo_store_defaults`, the Wholesale Customer role,
the two wholesale application user meta keys, every `fcbo_quote` post, the
analytics attribution option.
*Expect, kept:* per-product tier meta (`fcbo_bulk_pricing`), customers' saved
orders (`fcbo_saved_lists`), PO numbers stored on past orders.
Check with a database client; this is the case most easily got wrong.

---

## 2. Settings page — Bulk Order → Settings

**2.1 Page loads**
Sign in as `admin_u`, open Bulk Order → Settings.
*Expect:* nine sections render — surface access, bulk pricing access, minimum
order total, product table, checkout, PO number, quotes, wholesale application.
No notice in the log.

**2.2 Save round-trip**
Change one field in every section. Save.
*Expect:* "Settings saved." Every value you set is shown back after the reload.

**2.3 Non-admin cannot reach it**
As `retail_u`, open `/wp-admin/admin.php?page=fcbo-settings` directly.
*Expect:* refused ("You do not have sufficient permissions" or similar). No
settings shown.

**2.4 Bad input is cleaned**
Put `-5` in Rows per page, `abc` in Minimum order total, and a script tag in the
checkout redirect URL. Save.
*Expect:* values come back sane (a positive number, 0, a stripped URL). Nothing
is echoed back unescaped.

**2.5 Live summary text**
Tick no roles under "Apply bulk pricing to".
*Expect:* the "Who sees tier prices" line reads that **everyone including
logged-out visitors** sees tier prices.
Now tick Wholesale Customer and save.
*Expect:* the line names only that role, and says administrators can see the tier
table but are not given the discount at checkout.

---

## 3. Access gates

Four gates behave differently on purpose. Test each.

**3.1 Gate 1 — who may use the surfaces**
With no extra roles configured, open `/bulk-order` and `/product-table` as each user.
*Expect:* `admin_u` and `wholesale_u` see the surface. `retail_u` and the logged-out
visitor see a polite "you do not have access" message, never the form.

**3.2 Gate 1 widened by settings**
Settings → "Also allow these roles" → tick Editor. Save. Reload as `extra_u`.
*Expect:* `extra_u` now sees both surfaces. `retail_u` still does not.

**3.3 Gate 1 widened per placement**
Untick Editor in settings. Change the page to `[fluent_cart_bulk_order roles="editor"]`.
*Expect:* `extra_u` sees the form on that page only. The product table page still refuses them.

**3.4 Gate 2 — who gets bulk pricing**
Set "Apply bulk pricing to" = Wholesale Customer. Add a tier to P1 (see §8).
*Expect:* `wholesale_u` sees the discounted price on the form, in the cart and at
checkout. `retail_u` and the logged-out visitor see retail prices everywhere.
`admin_u` can see the tier table on the product page but is charged the retail
price at checkout.

**3.5 Gate 3 — minimum order total**
Leave "Require it of" empty and set a minimum of 100.00.
*Expect:* nobody is blocked — an empty role list here means **nobody**.
Now tick Wholesale Customer.
*Expect:* only `wholesale_u` is blocked below 100.00.

**3.6 Gate 4 — PO number roles**
Set PO mode to Optional and leave the role list empty.
*Expect:* the field shows for **everyone** — an empty list here means everyone.
This is the opposite of Gate 3 and is intentional.

---

## 4. Bulk order form — `[fluent_cart_bulk_order]`

Sign in as `wholesale_u` for this whole section.

**4.1 First render**
*Expect:* a table with one empty row, an "Add row" button, a running total, and a
checkout button. Quick-order and (if enabled) quote panels are collapsed.

**4.2 Search by product name**
Type `P2` in the product box.
*Expect:* a dropdown appears with matching products. Results arrive within a
second or two, without a page reload.

**4.3 Search by SKU**
Type `P2-M`.
*Expect:* the P2 product appears and the M variant is matched.

**4.4 No result**
Type `zzzzzz`.
*Expect:* a clear "no products found" line, not an empty silent box and not an error.

**4.5 Keyboard use**
Use ↓ ↑ to move in the dropdown and Enter to pick.
*Expect:* the active row is visibly highlighted; Enter selects it; Escape closes
the dropdown. Tab order is sensible.

**4.6 Variant picking**
Select P2.
*Expect:* a variant dropdown offers S, M, L with their own prices. Picking one
sets the row's unit price.

**4.7 Quantity and row total**
Set quantity 3 on a 25.00 variant.
*Expect:* row total is 75.00. The grand total updates as you type, with no reload.

**4.8 Tier discount on the form**
With a 10% tier at quantity 10+ on P1, set quantity 10.
*Expect:* the unit price drops to 9.00, the row total is 90.00, and a saving line
says what was saved.

**4.9 Next-tier nudge**
Set quantity 6 when the tier starts at 10.
*Expect:* a message like "add 4 more to unlock 10% off". At quantity 10 the nudge
disappears and the saving message replaces it.

**4.10 Minimum quantity rounding**
Give P1 a min quantity of 5. Type 2.
*Expect:* the box is rounded **up** to 5, and a visible message says so. It is
never rounded down and never changed silently.

**4.11 Case-pack step rounding**
Set the step to 6. Type 7.
*Expect:* rounded up to 12, with a message.

**4.12 Add and remove rows**
Add three rows, remove the middle one.
*Expect:* the remaining rows stay correct, the grand total recalculates, and one
empty trailing row always remains.

**4.13 Checkout**
Fill two rows, press Checkout.
*Expect:* both lines land in the FluentCart cart with the right quantities, and
you are sent to checkout. Prices in the cart match what the form quoted.

**4.13a Checkout handoff — read the URL**
Repeat 4.13 and look at the address bar on the checkout page.
*Expect:* `fcbo_src=bulk_order_form` is present and **`fct_cart_hash` is
absent**. Other query arguments may legitimately be there — check for those two
specifically rather than requiring a bare URL. Adding the hash by hand — copy
the `fct_cart_hash` cookie value and append `&fct_cart_hash=…` to the existing
query string (an `&`, not a second `?`) — empties the page to "Your cart is
empty." That is the failure this check exists to catch (issue #41): the page
must render your lines with the parameter absent, and the same cart must come
back when you remove it again.

**4.14 Checkout redirect setting**
Settings → "Send bulk orders to" → set a custom page. Repeat 4.13.
*Expect:* you land on the page you set. Clear the setting and confirm it goes
back to the FluentCart default. Check 4.13a again on the custom page — the
redirect must still carry no `fct_cart_hash`.

**4.15 Empty checkout**
Press Checkout with no rows filled.
*Expect:* a clear message. No empty cart, no redirect, no JS error.

**4.16 Quick order — paste**
Open the quick-order panel and paste:
```
P1-A, 5
P2-M, 3
NOPE-1, 2
```
*Expect:* two rows fill in with the right variants and quantities. `NOPE-1` is
**reported** in a small result panel as not found — it is not silently dropped.

**4.17 Quick order — separators and messy input**
Try tab-separated, semicolon-separated, blank lines, and a line with no quantity.
*Expect:* the parser handles them or reports the line. No crash.

**4.18 Quick order — CSV upload**
Upload `docs/testing/quick-order-sample.csv`.
*Expect:* the same behaviour as pasting.

**4.19 Quick order — non-CSV file**
Upload a `.jpg`.
*Expect:* refused with a message, not a broken table.

**4.20 Save order**
Fill two rows, press Save, name it `Monthly restock`.
*Expect:* confirmation. The order appears on `/saved-orders`.

**4.21 Save with an empty name**
*Expect:* refused with a message.

**4.22 Request a quote (only when quotes are on — §11)**
Fill rows, open the quote panel, add a note, send.
*Expect:* confirmation with a reference. The quote shows in Bulk Order → Quote Requests.

**4.23 Shortcode attributes**
Test each on a page: `roles`, `redirect`, `quotes="no"`.
*Expect:* each attribute overrides the store default on that page only.

---

## 5. Product table — `[fluent_cart_product_table]`

**5.1 First render**
*Expect:* products list with price, quantity box and Add button per row. Rows per
page matches the store default (5 unless changed).

**5.2 Variant rows**
*Expect:* P2 shows a summary row with a caret. Clicking it opens the three
variant rows. Clicking again closes them.

**5.3 Expand by default**
Use `[fluent_cart_product_table expand_variants="yes"]`.
*Expect:* variant rows are open on load.

**5.4 Pagination counts variant rows correctly**
Set `per_page="3"` with P2 expanded.
*Expect:* paging is consistent — the page does not show 3 products where it
promised 3 rows, and the total/page count matches what is actually displayed.
(This one has regressed before; check the last page too.)

**5.5 Search box**
Type a product name; then use `search="no"`.
*Expect:* filtering works; with `search="no"` the box is gone.

**5.6 Category filter**
`[fluent_cart_product_table category="trade"]`.
*Expect:* P1 and P2 show, P3 does not.

**5.7 Columns**
`columns="image,name,sku,price,stock"` and then a subset.
*Expect:* only the named columns render, in that order. An unknown column name is
ignored, not printed.

**5.8 Store default vs placement**
Set Rows per page = 12 in settings and use a bare `[fluent_cart_product_table]`.
*Expect:* 12 rows. Now use `per_page="4"`.
*Expect:* 4 rows on that page only. A bare shortcode must never fall back past
the store default to the built-in 5.

**5.9 Add to cart**
Set quantity 2 on a row, press Add.
*Expect:* a success message and the FluentCart cart count rises. The cart line
has quantity 2 at the right price.

**5.10 Out of stock**
*Expect:* P3's quantity box and Add button are disabled with an out-of-stock label.

**5.11 Order rules in the table**
With min quantity 5 on P1, type 2 and press Add.
*Expect:* rounded up to 5 with a message, and the rule is shown on the row.

**5.12 Access**
As `retail_u` and logged out.
*Expect:* refused, same as §3.1.

---

## 6. Saved orders and reorder — `[fluent_cart_saved_orders]`

As `wholesale_u`, after §4.20.

**6.1 List**
*Expect:* `Monthly restock` with its date, item count and current subtotal.

**6.2 Expand**
Click the name.
*Expect:* the line items open, each with product, variant, quantity and current price.

**6.3 Re-pricing, not a snapshot**
Change P1's price in FluentCart. Reload the page.
*Expect:* the saved order shows the **new** price. Saved orders never store prices.

**6.4 Removed variant**
Delete or disable a variant used by the saved order. Reload.
*Expect:* that line is marked unavailable. The rest of the order still shows.

**6.5 Reorder**
Press Reorder.
*Expect:* every still-available line goes into the cart at current prices.
Unavailable lines are skipped with a notice, and do not block the rest.

**6.5a Reorder checkout handoff — read the URL**
Stay on the checkout page you were sent to in 6.5 and look at the address bar.
*Expect:* `fcbo_src=saved_orders` is present and **`fct_cart_hash` is absent**
— check for those two specifically, since other query arguments may legitimately
be there — and the reordered lines are on screen. This is the same
issue-#41 check as 4.13a on the other surface; run both, because the two
redirects are separate pieces of code that have gone wrong together before.

**6.6 Past orders**
Complete a real order as `wholesale_u`, then reload the page.
*Expect:* a "past orders" group lists it, and it can be reordered too.

**6.7 Delete**
Delete the saved order.
*Expect:* it disappears. Past orders are not deletable from here.

**6.8 One user's orders are their own**
Sign in as `admin_u` (or another wholesale user) and open the page.
*Expect:* `wholesale_u`'s saved orders are **not** listed. Try the REST call in
§15.4 as well.

---

## 7. Single product page tiers

**7.1 Collapsed by default**
With tiers on P1, open the P1 product page as `wholesale_u`.
*Expect:* one line under the quantity block — "Bulk pricing — save up to N%",
where N is the largest percentage any of this product's tiers offers. Nothing
else of the block is visible until it is opened.

**7.2 The summary degrades for money tiers**
Change P1's feed to a fixed per-unit price or a flat amount off, or mix one of
those in with a percentage tier.
*Expect:* the line reads "Bulk pricing — buy more, pay less". A money discount
cannot be stated as a percentage without mislabelling the unit, and a mixed set
cannot claim a percentage ceiling it may not hold.

**7.3 Open it, by mouse and by keyboard**
Click the summary line. Then reload, Tab to it, and press Enter (and Space).
*Expect:* both open it, revealing the tier table and the order table. The caret
turns. Tabbing to it shows a focus ring. A screen reader announces it as a
collapsed/expanded item.

**7.4 Tier table**
With the block open.
*Expect:* a tier table — quantity range, discount, and the resulting unit price
— above a small order table with one quantity row per variant.

**7.5 Live price on the product page**
Change a quantity to hit a tier.
*Expect:* the shown price updates to the tier price, with the saving beside it.

**7.6 Add to Cart stays put**
Type quantities on two rows and press **Add to Cart**.
*Expect:* both lines go in the cart, the cart drawer opens, and the page does
not navigate.

**7.7 Bulk order now goes to checkout**
Type quantities on two rows and press **Bulk order now**.
*Expect:* the same lines go in the cart and the browser lands on the store's
checkout page, showing both lines at their tier prices. The cart drawer does not
flash on the way out.

**7.8 Gate 2 hides it**
As `retail_u` (with Gate 2 set to Wholesale Customer only).
*Expect:* no accordion at all, retail price only.

**7.9 Product with no tiers**
Open P3.
*Expect:* nothing extra renders — not even the summary line, and no empty table
shell behind it.

---

## 8. Bulk pricing tiers (the FluentCart integration feed)

**8.1 Store-wide feed**
FluentCart → integrations → Bulk Pricing → new feed, enabled, one tier: 10–49 → 10% off.
*Expect:* it saves, and it applies to every product with no product feed of its own.

**8.2 Per-product feed wins**
Add a feed on P1 with 10–49 → 20% off.
*Expect:* P1 uses 20%; P2 still uses the store-wide 10%.

**8.3 Three discount types**
Test one tier of each: percentage off, fixed per-unit price, flat amount off per unit.
*Expect:* each computes correctly on the form, the cart and the checkout summary.

**8.4 Overlapping ranges**
Configure 10–49 and 20–100.
*Expect:* at quantity 25 the more specific matching range wins, consistently on
every surface. Whatever the form quotes is what the cart charges.

**8.5 Variant restriction**
Restrict a P2 feed to the M variant only.
*Expect:* M gets the discount; S and L do not.

**8.6 Role-scoped price lists**
Add an "everyone" set and a Wholesale Customer set with different tiers.
*Expect:* `wholesale_u` gets the wholesale set. A different allowed role falls
back to the everyone set.

**8.7 Feed disabled**
Untick enabled.
*Expect:* no discount anywhere, and the surfaces show retail prices.

**8.8 Price parity — the important one**
For each of 8.1–8.6: add the line to the cart and go all the way to the checkout
summary. Compare three numbers — the price the form quoted, the price in the
cart, and the price at checkout.
*Expect:* all three agree. If a surface cannot be sure, it must show the retail
price rather than a discount that will not be honoured.

**8.9 Priced on the settled quantity**
Add quantity 6 to the cart, then add 6 more of the same line so the cart settles
at 12, with a tier at 10+.
*Expect:* the whole line is priced at the 10+ tier, not as two 6-unit orders.

---

## 9. Order rules

**9.1 Minimum quantity**
Set min qty 5 on a P1 variant. Try 3 on both surfaces.
*Expect:* rounded up to 5 with a message (§4.10, §5.11).

**9.2 Case-pack step**
Set step 6. Try 7.
*Expect:* rounded up to 12.

**9.3 Both together**
Min 5, step 4. Try 2.
*Expect:* lands on the first value that satisfies both (8), with a message.

**9.4 Server refuses a crafted request**
With the browser devtools, change the quantity input's value to 3 after the page
has loaded, or POST directly to FluentCart's add-to-cart with quantity 3.
*Expect:* the server refuses with the plugin's own message, not a generic
out-of-stock text. The item is not added.

**9.5 Minimum order total**
Set 100.00 for Wholesale Customer. Build a 50.00 cart as `wholesale_u` and try to
check out.
*Expect:* refused at checkout with a clear message naming the minimum. Above
100.00 it goes through. `retail_u` is never blocked.

**9.6 Known gap — order bump**
If FluentCart offers an order bump or a variation upgrade at checkout, add a
rule-violating item that way.
*Known limitation:* it is **not** blocked. This is a documented host-side gap
(see `docs/solutions/architecture-patterns/fluentcart-veto-capable-hooks-for-cart-and-checkout.md`).
Record it as expected behaviour, not a bug.

---

## 10. PO number at checkout

**10.1 Off (default)**
*Expect:* no PO field at checkout for anyone. This must be true on a fresh install.

**10.2 Optional**
*Expect:* the field shows and checkout succeeds with it empty and with it filled.

**10.3 Required — browser**
*Expect:* the page asks for it before submitting.

**10.4 Required — server**
Remove the field with devtools, or submit the checkout request without the
`po_number` value.
*Expect:* the server refuses the checkout. A browser-only check is not enough.

**10.5 Role scoping**
Set the role list to Wholesale Customer.
*Expect:* `wholesale_u` sees it; `retail_u` does not, and is never blocked by it.

**10.6 Where the number appears**
Place an order with PO `PO-2026/001`.
*Expect:* it shows on the order receipt, in the buyer's account order view, in
the admin order screen, and in both export formats.

**10.7 Escaping**
Use `<b>bold</b>` and `=1+1` as the PO number.
*Expect:* the receipt prints the text literally, no bold rendering. The CSV cell
is neutralised (see §12.4).

**10.8 Length cap**
Paste 500 characters.
*Expect:* refused or trimmed to the cap, never a database error.

---

## 11. Quotes

**11.1 Off by default**
*Expect:* on a fresh install there is no quote button on the bulk order form and
no Quotes admin screen.

**11.2 Turn on**
Bulk Order → Settings → Quotes → enable.
*Expect:* the quote panel appears on the form and **Bulk Order → Quote Requests**
appears in the admin menu.

**11.3 Send a request**
As `wholesale_u`, assemble three lines and send with a note.
*Expect:* confirmation with a reference. Status is **requested** in the admin list.

**11.4 Owner notification**
With "notify admin" on.
*Expect:* the store admin email arrives with the lines and the buyer's details.
Turn notification off and send another; expect no admin email.

**11.5 Price it**
Open the quote, set a price on line 1, leave line 2 empty, type `0` on line 3. Press Quote.
*Expect:* line 1 takes the new price, line 2 keeps its catalog price (empty means
"leave alone"), line 3 becomes free. Status moves to **quoted**. The buyer is emailed.

**11.6 Prices lock after quoting**
Reopen the quoted record.
*Expect:* the price boxes are gone or read-only. Only Convert and Decline remain.

**11.7 Convert**
Press Convert.
*Expect:* a real FluentCart order is created, **unpaid**, at the quoted prices,
for the right customer. The quote shows as accepted/converted and cannot be
converted twice.

**11.8 Mixed subscription request**
Ask for P4 (subscription) and P1 in one quote.
*Expect:* the request is accepted and can be priced — the cart's refusal to mix
them does not apply here. On convert, FluentCart will not put the subscription
line in a manual order; expect that part to be reported clearly, not to fail
silently or half-create an order.

**11.9 Decline**
Decline a requested quote and a quoted one.
*Expect:* both move to declined, the buyer is emailed, and no further action
buttons remain.

**11.10 Status filter and paging**
Use the status tabs (all / requested / quoted / accepted / declined) and page
through with more than one page of quotes.
*Expect:* counts and rows match the filter; paging keeps the filter.

**11.11 Direct URL and nonce**
Open `/wp-admin/admin.php?page=fcbo-quotes` as `retail_u`.
*Expect:* refused. Then re-submit a decision form with a stale/absent nonce.
*Expect:* refused with "This action must be submitted from the quote review screen."

**11.12 One buyer, many quotes**
Send three quotes as the same user.
*Expect:* three separate records, each with its own reference. A declined one is
never re-opened.

---

## 12. Order export

**12.1 Owner screen**
Bulk Order → Order Exports as `admin_u`.
*Expect:* a searchable, paged list of orders with CSV and receipt links.

**12.2 CSV**
Download one.
*Expect:* one flat table, **one row per line item**, with the order's own facts
(order id, date, customer, PO number, totals) repeated on every row. It opens
cleanly in a spreadsheet and can be sorted.

**12.3 Receipt**
Download one.
*Expect:* a PDF where FluentCart's PDF stack exists, otherwise a print-ready
page. The link says which one you are about to get.

**12.4 Formula injection**
Set a customer name or PO number to `=cmd|' /C calc'!A0` and export.
*Expect:* the cell is neutralised (leading `'` or equivalent) in **every** cell,
not just the first column.

**12.5 Permission on the URL**
Copy an export URL. Open it as: the order's own customer, a different customer,
and logged out.
*Expect:* only the owning customer and a user with the store capability get the
file. Everyone else is refused. Changing the order id in the URL does not work.

**12.6 Buyer's own links**
As the buying customer, open the receipt and the account order view.
*Expect:* export links are present and work for their own orders only.

---

## 13. Analytics

Place several orders first: some through the bulk order form, some through the
product table, some through normal checkout, at least one from a converted quote.

**13.1 Screen loads**
Bulk Order → Analytics as `admin_u`.
*Expect:* four blocks — revenue split, entry points, top customers, tier utilization.

**13.2 Periods**
Switch between 30 days, 90 days, 12 months, all time.
*Expect:* numbers change sensibly, 90 days is the default, and an unknown
`period` value in the URL falls back to the default instead of erroring.

**13.3 Revenue split**
*Expect:* bulk revenue plus normal-checkout revenue equals the store total for
the period. Shares add up to 100%.

**13.4 Entry points**
*Expect:* orders from the bulk order form are attributed to it. Orders from the
**product table** show as **no entry point** — that is correct, not a bug, and
must not be guessed at.

**13.5 Top customers**
*Expect:* the right buyers, with order count, spend and last order date.

**13.6 Tier utilization — the point of the screen**
*Expect:* every configured tier appears, including ones **nobody has reached**,
shown as zero. A tier that was used and has since been **deleted** still appears,
named from what was recorded.

**13.7 Editing a tier**
Change a tier's quantity range after orders were placed on it.
*Expect:* the old definition stays as its own row with its old orders; the edited
one starts at zero. Old orders are not re-labelled.

**13.8 Refund**
Refund an attributed order.
*Expect:* the report reflects it without any record being edited by hand.

**13.9 No back-fill**
Orders placed before the plugin was installed.
*Expect:* they count as normal checkout. The screen never invents attribution for them.

**13.10 Empty state**
On a store with no bulk orders yet.
*Expect:* a clear "nothing yet" message, not empty tables or division-by-zero errors.

**13.11 Permission**
Open the analytics URL as `retail_u`.
*Expect:* "You do not have permission to view bulk order analytics."

---

## 14. Blocks and Elementor widgets

**14.1 Gutenberg**
Add the **Bulk Order Form** block and the **Product Table** block to a page.
*Expect:* both appear in the inserter, render a preview in the editor, and expose
the same controls as the shortcode attributes. On the front end they behave
exactly like the shortcode, including role gates.

**14.2 Elementor**
Same two widgets.
*Expect:* the same result.

**14.3 Store-default precedence (regression-prone)**
Set Rows per page = 12 in settings. Drop a Product Table block and **touch none of
its controls**. View the page.
*Expect:* **12 rows.** If you see 5, the wrapper is passing an empty attribute and
overriding the store default — see
`docs/solutions/architecture-patterns/wrapper-must-omit-unset-shortcode-attributes.md`.
Repeat for every control on both blocks and both Elementor widgets: columns,
search, expand variants, category, roles, redirect, quotes.

**14.4 Explicit control wins**
Now set Rows per page = 4 on the block.
*Expect:* 4 rows on that page, and the store default is unchanged elsewhere.

---

## 15. REST API security

Use the browser console (`fetch`) or curl with each user's cookies.

**15.1 Every route needs a permission check**
Call each route logged out:
`/wp-json/fcbo/v1/products`, `/catalog`, `/resolve-skus`, `/saved-lists`,
`/past-orders`, `/quotes`.
*Expect:* every one returns 401/403. None returns data.

**15.2 As a disallowed role**
Repeat as `retail_u`.
*Expect:* all refused.

**15.3 As an allowed role**
Repeat as `wholesale_u`.
*Expect:* all succeed and return only that user's data.

**15.4 Cross-user access**
As `wholesale_u`, try to read, update, or delete another user's saved list by id,
and to read another buyer's quote.
*Expect:* refused. Ownership is checked against the record, not against anything
in the request.

**15.5 Nonce**
Send a write request with a missing or wrong nonce.
*Expect:* refused.

**15.6 Bad input**
Send a negative quantity, a huge quantity, a non-numeric variant id, and a
10,000-character SKU string.
*Expect:* a clean 400-type error. No PHP warning, no 500.

---

## 16. Wholesale application

**16.1 Form for a signed-in shopper**
As `retail_u`, open `/apply`.
*Expect:* company name and tax/VAT ID fields, plus any extra questions.

**16.2 Logged out**
*Expect:* a prompt to sign in, not a form that will fail on submit.

**16.3 Extra question types**
In settings add one of each: single line, paragraph, choose-one (with options),
tick box. Mark two required.
*Expect:* all four render correctly; required ones block submit when empty; a
choose-one with no options is rejected at settings-save time.

**16.4 Submit**
Fill and submit.
*Expect:* confirmation. The user cannot submit a second one while it waits — a
new submit replaces the waiting one, it does not queue a second.

**16.5 Review screen**
As `admin_u`, Bulk Order → Wholesale Applications.
*Expect:* a pending count badge in the menu, and the application with every
answer, including the built-in company name and tax ID.

**16.6 Removed question**
Delete an extra question in settings, then reopen the application.
*Expect:* the old answer is still shown to the reviewer, not hidden.

**16.7 Approve**
*Expect:* the user gains the Wholesale Customer role. The applicant is emailed.
The store owner is emailed if admin notification is on. The application is final
and cannot be decided again.

**16.8 Reject**
*Expect:* the applicant is emailed. They **can** apply again on the same record.

**16.9 Only an admin decides**
Open the review URL as `retail_u`, and post a decision without a valid nonce.
*Expect:* refused both times.

**16.10 FluentCRM tags**
With FluentCRM active, set an "applied" tag and an "approved" tag.
*Expect:* the applied tag lands on the contact on submit; the approved tag on
approval. With the tag ids left at 0, nothing is tagged.

**16.11 No FluentCRM**
Deactivate FluentCRM and repeat 16.4 and 16.7.
*Expect:* everything still works, no error, no tagging attempted.

---

## 17. Cross-cutting checks

Run these against the surfaces you have already tested.

**17.1 Escaping / XSS**
Name a product `<img src=x onerror=alert(1)>`, a saved order `"><script>alert(1)</script>`,
and a quote note the same. Visit every screen that prints them.
*Expect:* text prints literally. No alert anywhere, admin or front end.

**17.2 Translation readiness**
*Expect:* no hard-coded English string in the UI outside a translation function.
Load a test translation and confirm strings change.

**17.3 Accessibility**
Tab through the bulk order form and the product table.
*Expect:* every control is reachable, dropdowns announce their state
(`aria-expanded`), messages about rounding are announced to a screen reader, and
focus is never trapped.

**17.4 Mobile**
Open both surfaces at 375px wide.
*Expect:* tables stay usable and buttons stay tappable.

**17.5 Browsers**
Run §4 and §5 in Chrome, Firefox and Safari.
*Expect:* the same behaviour, no console error.

**17.6 Multisite**
On a multisite network, activate per-site and network-wide. Check the role exists
where expected, and that a super admin can reach the surfaces.

**17.7 Caching**
With a page cache active, confirm no gated surface is served from cache to the
wrong role, and no price is cached across users.

**17.8 Concurrency**
In two tabs as the same user, add to the cart from both surfaces at once.
*Expect:* the cart is consistent, no duplicated or lost lines.

---

## Sign-off

| Section | Result | Notes |
|---|---|---|
| 1 Install / uninstall | | |
| 2 Settings page | | |
| 3 Access gates | | |
| 4 Bulk order form | | |
| 5 Product table | | |
| 6 Saved orders | | |
| 7 Single product tiers | | |
| 8 Pricing tiers | | |
| 9 Order rules | | |
| 10 PO number | | |
| 11 Quotes | | |
| 12 Order export | | |
| 13 Analytics | | |
| 14 Blocks / Elementor | | |
| 15 REST security | | |
| 16 Wholesale application | | |
| 17 Cross-cutting | | |
