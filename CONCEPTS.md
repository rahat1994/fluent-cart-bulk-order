# Concepts

Shared domain vocabulary for this project — entities, named processes, and status concepts with project-specific meaning. Seeded with core domain vocabulary, then accretes as ce-compound and ce-compound-refresh process learnings; direct edits are fine. Glossary only, not a spec or catch-all.

## Catalog

### Product Variant
A single orderable version of a product — one size, colour, or configuration — carrying its own price, SKU, and stock. *Avoid:* variation, item.

The variant, not the product, is the unit almost everything here operates on: prices resolve per variant, an Integration Feed may be restricted to specific variants, an Order Rule constrains a variant, and a Saved Order stores variants. A product is a grouping that may hold one variant or many, and conflating the two is a recurring source of error — counting or paginating products when the surface actually displays variant rows yields the wrong totals, because the two quantities differ for any product with more than one variant.

## Access

### Wholesale Customer
A customer role granted access to the plugin's bulk-ordering surfaces, distinct from an ordinary store customer.

Along with administrators (and multisite super admins), a Wholesale Customer is permitted to reach the Bulk Order Form, the Product Table, and their backing REST endpoints; other roles are denied. The set of permitted roles is a single, extensible policy shared by both the on-page surfaces and the REST layer, so a change applies to both at once.

### Wholesale Application
A signed-in shopper's request to be made a Wholesale Customer, together with the answers they gave and the decision an administrator made on it. *Avoid:* wholesale request, signup.

Every applicant is an existing user account, because the outcome of an approval is a role granted to that account — there is no such thing as an application without a user. A user holds at most one application: applying again while one is waiting replaces it rather than queueing a second, and a rejected applicant may apply again on the same record. Only an administrator's decision moves an application, only a waiting one can be decided, and an approved one is final — the role can afterwards be taken away by editing the user, which is not the same act as reversing the decision.

The questions asked are the store's own: a company name and a tax identifier always, plus whatever else the owner has configured. Because the question list can change after an application was sent, an answer is kept under the key of the question that asked it, and an answer whose question has since been removed is still shown to the reviewer rather than hidden.

### Bulk Order Form
The access-gated surface where a permitted user assembles a multi-line order — searching products, choosing variants, and setting quantities — then sends the whole order to checkout in one action.

### Product Table
The access-gated surface that lists the catalog with per-row quantity entry for quick add-to-cart, as an alternative entry point to the Bulk Order Form.

## Configuration

### Integration Feed
A stored configuration record that enables and configures one integration for a given scope — either store-wide or for a single product. *Avoid:* feed settings, integration config.

A feed carries an enabled flag, a name, whatever settings its integration defines, and an optional restriction to specific product variants. A single store-wide feed applies as the default, while one product may hold several feeds for the same integration, each restricted to different variants; for a given variant the first feed whose restriction matches applies, and any matching product-scoped feed takes precedence over the store-wide one. A feed persists only the settings its integration has formally declared — any other value submitted alongside them is discarded when the feed is saved, with no error, so an undeclared setting appears to save and then silently disappears.

## Pricing

### Bulk Pricing Tier
A rule mapping a purchase-quantity range to a discount, so larger quantities cost less per unit. The discount is one of three types: a percentage off, a fixed per-unit price, or a flat amount off each unit. *Avoid:* discount tier.

Tiers are configured on an Integration Feed and resolve per product variant: the tiers of a matching product-scoped feed take precedence over those of the store-wide feed, and within the winning set the most specific matching quantity range wins. A tier set can additionally be scoped to a user role, so different roles can see different prices for the same variant; a shopper whose role has no scoped set falls back to the everyone set. Role scoping decides *which* prices a qualifying shopper sees; a separate access policy decides *whether* that shopper gets bulk pricing at all (see Wholesale Customer).

The same discount is resolved twice over — once by the ordering surfaces to quote a total before purchase, and once by the host store to price the cart line actually charged. These are separate computations that can silently disagree, so a quote holds only while two things do: both sides apply the same eligibility policy, and both resolve against the same quantity — the one the shopper will be billed for, not the one a particular request happened to carry. Where a surface cannot satisfy both, it shows undiscounted prices rather than a discount that will not be honoured.

## Reporting

### Bulk Order Attribution
The record, written once as an order is created, of what this plugin had to do with it: which Bulk Pricing Tier priced each line, and which surface the buyer came from. *Avoid:* tracking, order source, analytics data.

An attribution exists only for an order the plugin can account for — one whose price a tier changed, one that reached checkout from a marked surface, or one converted from a Quote Request. An order with no attribution is not an error; it is the definition of a normal checkout, and the reporting screen measures normal checkout as the store's total minus what is attributed. That is why an attribution stores no money the order itself owns: it is written on a draft order, before payment, so the total, the payment status and the date are read from the store's own order at report time and a refund issued later is reflected without the record being touched.

Attribution is forward-only and cannot be back-filled. A past order's charged price says what was charged, not which of several overlapping tiers produced it, and the feed that produced it may since have been edited or deleted — so a store installing the plugin today can report on the orders it takes from today, and any screen showing otherwise would be showing a guess dressed as history.

Knowing the *surface* is weaker than knowing the *tier*, and deliberately so. Both ordering surfaces add to the cart through the host store's own browser API, on a request that carries no server-side knowledge of the page the shopper was on, so only a surface that hands the shopper to checkout itself can mark the handoff — the Product Table does not, and its orders are recorded with no entry point rather than with a guessed one.

### Tier Utilization
How much each Bulk Pricing Tier is actually used, measured against every tier the store has configured. *Avoid:* tier stats, discount usage.

The answer an owner is looking for is a zero: the tier nobody has ever reached. So utilization is never a ranking of tiers that were used — it is a join of the configured set against the used set, which puts every tier into exactly one of three groups: reached, never reached, or no longer configured. Dropping the third group would lose real revenue from the report; dropping the second would lose the entire point of it.

A tier carries no id of its own — it is a row in an array on an Integration Feed — so its definition *is* its identity: where the feed lives, whose price list it belongs to, its quantity range and its discount. Editing a tier therefore creates a second one here rather than changing the first, and that is the honest answer: the orders under the old definition were charged the old price. The same property is what lets a deleted tier still be named, because its name is rebuilt from what was recorded and not looked up in a feed that no longer contains it.

## Orders

### Order Rule
A constraint on *how much* may be ordered, as opposed to what it costs. Two are per-variant — a minimum quantity and a quantity step (the case-pack multiple a quantity must be divisible by) — and one is store-wide: a minimum order total. *Avoid:* quantity limit, order minimum.

The per-variant rules are configured on the same Integration Feed as Bulk Pricing Tiers and resolve through the identical feed precedence, so the feed that prices a variant is always the feed that constrains it. Unset rules mean no constraint. The ordering surfaces round an out-of-rule quantity *up* to the nearest permitted value and say so, never downward and never silently; the server independently rejects a non-conforming quantity, so the surfaces are a convenience and the server is the authority. That authority is not total: enforcement is exercised through the host store's extension points, and a purchase path the host does not route through those points is unconstrained — so the guarantee is "every path the host offers for refusal is used", not "no order can ever violate a rule". The minimum order total applies only to an explicitly configured set of roles — unlike the bulk-pricing role policy, configuring no roles means it applies to nobody.

### Saved Order
A permitted user's named, stored order — a reusable basket assembled on the Bulk Order Form and kept for later. Each Saved Order belongs to exactly one user; another user can never see or act on it.

A Saved Order holds product variants and quantities, not prices. It is re-priced against the live catalog every time it is viewed or reordered, so a variant that has since been removed surfaces as unavailable and one whose price changed reflects the current price — never a stale snapshot.

### Reorder
The one-action process of putting a Saved Order's — or a past purchase's — still-available line items back into the cart. Items that no longer resolve to an available variant are skipped with notice rather than blocking the rest.

### PO Number
The buyer's own purchase-order reference, given at checkout and kept with the order. *Avoid:* purchase order, order reference, PO.

It is the buyer's number, not the store's: a store already has an order id and an invoice number, and neither is what a purchasing department pays against. So it is asked for rather than generated, stored verbatim on the order, and printed back on everything the buyer files — the receipt, the order in their account, and both export formats.

A store is in one of three states about it, and they are one setting rather than two: off, optional, or required. Off is the default and must stay so, because turning the field on changes a checkout every shopper sees. "Required" is enforced by refusing the checkout on the server, not by the browser — a field that only the page validates is not required. Which shoppers the state binds is a separate question with its own role list; unlike the minimum order total, an empty list there means *everyone*, which is safe only because the state above it starts off.

A PO number is one line of text with a length cap, and it is stored raw and escaped at each render site. That matters more than it sounds: the value is a buyer's free text that later lands in a spreadsheet cell, so the two places that print it — the printable receipt and the CSV — each defend themselves rather than trusting what was stored.

### Order Export
A single order rendered as a file the buyer files and the owner sends — a CSV for their accounts system, or a receipt for their records. *Avoid:* invoice, download, report.

The CSV is one flat table with one row per line item and the order's own facts repeated on each, because a two-section file reads better and cannot be sorted, filtered or imported. The receipt is a real PDF where the store already has FluentCart's PDF stack and a print-ready page everywhere else — this plugin does not carry a PDF engine, and the link says which one the buyer is about to get.

Either file is one customer's order, so an export URL is not a secret: it is refused unless the requester either holds the owner capability or *is* the customer the order belongs to, checked against the order's own customer record rather than against anything in the URL.

### Quote Request
A buyer's submitted bulk order sent to the store to be priced instead of bought, together with the prices the owner set on it and the decision they made. *Avoid:* RFQ, quotation, estimate.

Every quote belongs to exactly one buyer, and one buyer may hold many — unlike a Wholesale Application, of which a user holds at most one. A quote is therefore never re-opened: a declined or converted quote stays as it is and the buyer sends a new request, which is its own record with its own reference.

Where a Saved Order deliberately stores no prices and is re-priced against the live catalog every time it is read, a quote stores them and that is the point of it. Each line keeps two: the catalog price at the moment the buyer asked, and the price the owner decided. Both come from the store — a price the browser sends is never read — and an empty price box means "leave this line alone", which is a different instruction from a typed zero.

A quote moves in one direction only: *requested* → *quoted* → *accepted*, with *declined* reachable from either open state. Prices are editable only while it is requested, because once the buyer has been emailed a price the order they accept has to be the one they were quoted. Converting an accepted quote creates an ordinary FluentCart manual order, unpaid, at the quoted prices; the order is FluentCart's from that moment, and its own lifecycle is no longer this plugin's business.

A quote is also the one route around the cart's refusal to hold a subscription product and a one-time product together — a buyer can ask for both in a single request. The refusal is not gone, only moved: FluentCart will not put a subscription line into a manually created order either, so a mixed quote is priced and sent like any other and the subscription part is placed through FluentCart.
