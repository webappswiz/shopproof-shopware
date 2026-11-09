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

