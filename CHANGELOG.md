# 0.4.1
- New page designer block "Shopproof product information" (category Commerce) for product page layouts of your own. The default product page needs nothing: the blocks appear in the buy box and below the description as before.
- Each block shows only once per page, wherever the theme or layout places it.
- The settings page now lists each product page layout and says whether it has a place for the Shopproof blocks, with a link to open it.
- Withdrawal requests are now recorded on Shopware 6.7.9 to 6.7.11 too.
- Changing a manufacturer now refreshes its product pages in the page cache, so new safety details show without waiting for the cache to expire.
- Needs Shopware 6.7.2 or later (the oldest version we test on).

# 0.4.0
- Completes Shopware's own withdrawal button (Shopware 6.7.9 or later). Each request sent through it is recorded in the shop's own database and linked to its order when the order number and email match, so you can show when it arrived. Records are listed under Settings > Extensions > Shopproof, with search and delete.
- The footer button and the form's submit button use the wording each country's law names, in English, German, French, Italian and Dutch.
- A setup check shows whether the button is on, links to a page with the withdrawal form, and whether the flow emails the shopper a confirmation.

# 0.3.0
- EU durability label (GARAN, Regulation (EU) 2025/1960) on product pages for products with a producer guarantee of durability of 3 years or more. Set the years per product; the brand and model fall back to the Shopware manufacturer and its product number. Full label or a small strip that opens it. The official artwork and the Inter font are served by the shop itself, no JavaScript.

# 0.2.0
- Product safety information (GPSR) on product pages: manufacturer, EU responsible person, product identifiers and warnings. Each field comes from the product, then its Shopware manufacturer, then shop-wide defaults in the settings. Server-rendered, no JavaScript, takes the theme's styles.
- Official EU legal guarantee notice on product pages (off by default), in the page language, with a text version. Images are served by the shop itself.

# 0.1.0
- First version: daily download of the Shopproof rules file (the last good copy is kept), an overview of the rules for the countries you sell to under Settings > Extensions > Shopproof, with rules still under review left out. Technical help, not legal advice.
