# Concepts

Shared domain vocabulary for this project — entities, named processes, and status concepts with project-specific meaning. Seeded with core domain vocabulary, then accretes as ce-compound and ce-compound-refresh process learnings; direct edits are fine. Glossary only, not a spec or catch-all.

## Access

### Wholesale Customer
A customer role granted access to the plugin's bulk-ordering surfaces, distinct from an ordinary store customer.

Along with administrators (and multisite super admins), a Wholesale Customer is permitted to reach the Bulk Order Form, the Product Table, and their backing REST endpoints; other roles are denied. The set of permitted roles is a single, extensible policy shared by both the on-page surfaces and the REST layer, so a change applies to both at once.

### Bulk Order Form
The access-gated surface where a permitted user assembles a multi-line order — searching products, choosing variants, and setting quantities — then sends the whole order to checkout in one action.

### Product Table
The access-gated surface that lists the catalog with per-row quantity entry for quick add-to-cart, as an alternative entry point to the Bulk Order Form.

## Pricing

### Bulk Pricing Tier
A rule mapping a purchase-quantity range to a discount, so larger quantities cost less per unit. *Avoid:* discount tier.

Tiers resolve per product variant: a product-level tier set takes precedence over a store-wide default set, and the first matching quantity range wins. The resolved discount applies both to prices shown on the ordering surfaces and to the actual cart line price.

## Orders

### Saved Order
A permitted user's named, stored order — a reusable basket assembled on the Bulk Order Form and kept for later. Each Saved Order belongs to exactly one user; another user can never see or act on it.

A Saved Order holds product variants and quantities, not prices. It is re-priced against the live catalog every time it is viewed or reordered, so a variant that has since been removed surfaces as unavailable and one whose price changed reflects the current price — never a stale snapshot.

### Reorder
The one-action process of putting a Saved Order's — or a past purchase's — still-available line items back into the cart. Items that no longer resolve to an available variant are skipped with notice rather than blocking the rest.
