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

## Orders

### Order Rule
A constraint on *how much* may be ordered, as opposed to what it costs. Two are per-variant — a minimum quantity and a quantity step (the case-pack multiple a quantity must be divisible by) — and one is store-wide: a minimum order total. *Avoid:* quantity limit, order minimum.

The per-variant rules are configured on the same Integration Feed as Bulk Pricing Tiers and resolve through the identical feed precedence, so the feed that prices a variant is always the feed that constrains it. Unset rules mean no constraint. The ordering surfaces round an out-of-rule quantity *up* to the nearest permitted value and say so, never downward and never silently; the server independently rejects a non-conforming quantity, so the surfaces are a convenience and the server is the authority. That authority is not total: enforcement is exercised through the host store's extension points, and a purchase path the host does not route through those points is unconstrained — so the guarantee is "every path the host offers for refusal is used", not "no order can ever violate a rule". The minimum order total applies only to an explicitly configured set of roles — unlike the bulk-pricing role policy, configuring no roles means it applies to nobody.

### Saved Order
A permitted user's named, stored order — a reusable basket assembled on the Bulk Order Form and kept for later. Each Saved Order belongs to exactly one user; another user can never see or act on it.

A Saved Order holds product variants and quantities, not prices. It is re-priced against the live catalog every time it is viewed or reordered, so a variant that has since been removed surfaces as unavailable and one whose price changed reflects the current price — never a stale snapshot.

### Reorder
The one-action process of putting a Saved Order's — or a past purchase's — still-available line items back into the cart. Items that no longer resolve to an available variant are skipped with notice rather than blocking the rest.
