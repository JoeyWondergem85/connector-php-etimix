# Etimix Productsup connector

The import reads `/api/v2/products` and writes product and trade-product fields to Productsup. Set the `TRADE_PRODUCT_MODE` environment variable in the connector runtime to choose the output layout:

| Value | Output |
| --- | --- |
| `numbered` (default) | One row per product, with `tradeproduct_1_TradeProductId`, `tradeproduct_2_TradeProductId`, etc. |
| `repeated` | One row per trade product, with `tradeproduct_TradeProductId`; product columns repeat in each row. |

Both modes include one product-only row when `tradeProducts` is empty. Every existing product column, including technical descriptions and ETIM features, now begins with `product_` (for example `product_productId`); this changes the previous output column names. Trade-product columns include scalar values only; nested prices, media, packages, related trade products, custom fields, and multilingual descriptions are not exported.

The `repeated` mode is intended to test whether Productsup accepts multiple output rows with the same `product_productId`; this connector does not deduplicate them. An unsupported `TRADE_PRODUCT_MODE` value fails the import instead of silently choosing a layout.
