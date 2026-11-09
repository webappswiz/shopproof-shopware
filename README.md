# Shopproof for Shopware

A free plugin for Shopware 6.7 shops that sell in the EU (self-hosted and PaaS). It runs on the shop's own server and contacts no server unless the shop asks for rule updates. Shopproof gives technical help to display what EU consumer rules ask for; it is not legal advice.

- Withdrawal button: completes Shopware's own button (6.7.9 and later). Every request is recorded in the shop's database and linked to its order, with the national button wording in English, German, French, Italian and Dutch, a status page for the customer and a deadline clock.
- Product safety (GPSR): manufacturer, EU responsible person, identifiers and warnings, per product, per manufacturer or once for the shop. CLP hazard labels for chemicals.
- The official EU legal guarantee notice and the EU durability label.
- Registration numbers (WEEE, batteries, packaging) and take-back text.
- Proof log: a dated, hash-chained record of what the shop showed and which withdrawals it received, with no personal details.

More: https://shopproof.app/shopware/ . Agencies: https://shopproof.app/agencies/#shopware

## Install

```
composer require shopproof/compliance
bin/console plugin:refresh
bin/console plugin:install --activate ShopproofCompliance
bin/console cache:clear
```

Or download the zip from https://shopproof.app/shopware/ and upload it under Extensions > My extensions > Upload extension.

## Set up many shops (version 0.6.1 and later)

```
# In a shop you have set up: save its Shopproof settings
bin/console shopproof:settings:export > shopproof.json

# In each other shop: check the file, then apply it (all or nothing)
bin/console shopproof:settings:import shopproof.json --dry-run
bin/console shopproof:settings:import shopproof.json

# See what still needs work (exit code 0 set up, 1 needs work, 2 not checked; --json for scripts)
bin/console shopproof:setup:check --shop=shop.example
```

Settings can also be set one by one with Shopware's own `bin/console system:config:set ShopproofCompliance.config.<name> <value>`, with `--salesChannelId` for one sales channel.

## About this repository

This repository holds the released versions only, one tag per version, copied from the zips on shopproof.app. Development happens elsewhere; please report problems at https://shopproof.app/help/ or hello@shopproof.app. Licence: MIT.
