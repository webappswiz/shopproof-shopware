# 0.5.0
- Product safety sits as one line under the Add to cart button ("Product safety (manufacturer: Acme GmbH)") that opens to the details. The full block below the description is still available (setting "Where to show it").
- Hazard labelling for chemicals (CLP Article 48a): per product, pick the pictograms and the signal word and enter the hazard and precautionary statement codes. The product page shows the official texts in the shopper's language, always visible above the safety line. The Shopproof page lists codes that need your own words or are unknown.
- Registration numbers and take-back: enter your WEEE, battery, packaging, IDU or textiles numbers in the settings. They show as one small line in the footer, and products marked as electrical equipment or containing batteries explain how to return old devices and batteries.
- Order confirmation and shipping emails end with the withdrawal link: "Withdraw from contract here. Changed your mind? You can withdraw within 14 days.", linking to the page of Shopware's withdrawal button. New setting for the withdrawal period (14 to 365 days).
- Proof log: a dated, hash-chained record of what the shop showed (daily snapshot) and which withdrawals it received, with no customer names, email addresses or order numbers. Check the chain on the Shopproof page (it finds a changed entry, a removed newest or oldest entry and an emptied log) and download it as CSV. Kept 2 years by default.
- The durability label now shows as the small strip that opens the full label, as in the WooCommerce plugin and the Shopify app. Products set to "Full label" keep it. When Shopware's own durability label (6.7.14 and later) shows on a product, Shopproof leaves its label out, so there is never a second one.
- Rules list 0.10.0, which includes the second reviewed Dutch withdrawal wording ("hier de overeenkomst herroepen" with "herroeping bevestigen") from 0.9.1. The other 0.10.0 additions are Pro research and are not in this list.
- Fix: on older Shopware 6.7 versions the storefront showed text keys such as "shopproof.safety.heading" instead of the words, and the national withdrawal button wording did not apply. The texts now also ship for the usual locales (en-GB, de-DE, de-AT and others).
- 0.4.5 was never released; its changes are part of this version. Its changelog wrongly named rules 0.9.1.

# 0.4.4
- Rules list 0.9.0: new EU rule on hazard labelling for chemicals sold online (CLP Article 48a).
- Product safety: the manufacturer and the EU responsible person each come from one source, so a manufacturer's name is no longer shown with the shop-wide default address and email. The defaults apply to products without a Shopware manufacturer and to the manufacturer with the default name. A manufacturer without details shows its name only, and the Shopproof page lists such manufacturers. A product that names another manufacturer no longer borrows the Shopware manufacturer's address.
- Withdrawal requests that could not be recorded are counted, and the Shopproof page shows a red notice until you mark them as checked. The setup check confirms that the table for the records exists.
- Withdrawal requests match their order also when the shopper types "#", spaces or "Order" or "Bestellung" before the number.
- The setup check warns while the EU legal guarantee notice is off (required for goods from 27 September 2026).
- The rules list also leaves out rules waiting for a check, of low confidence or unverified, and rules without an https source link.

# 0.4.3
- Shopproof now ships with a copy of the rules list and contacts no server on install or activation, or when you open the Shopproof page.
- New setting "Download rule updates once a day" (Rule updates card), off by default. The daily rules update runs only when you turn it on.
- New button "Check for rule updates now" on the Shopproof page, for a one-off download.
- The description says the rules list is included and daily updates are optional.

