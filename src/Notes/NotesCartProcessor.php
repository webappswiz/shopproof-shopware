<?php declare(strict_types=1);

namespace Shopproof\Compliance\Notes;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Runs after Shopware has priced the products of the cart:
 *
 * - writes each product's short cart description into its line item, so the cart, the checkout,
 *   the order and its emails show it under the product name;
 * - writes each product's deposit into its line item, and adds the deposit as its own cart line
 *   (one per VAT rate, so the deposit carries the VAT of its drinks, or none if the shop chose
 *   so). The line is part of the totals, the order, the invoice and the emails. Shops whose
 *   prices already hold the deposit get no line; the cart summary says "Included deposit" instead.
 *
 * The deposit counts only for shoppers whose shipping country is the deposit type's country.
 */
final class NotesCartProcessor implements CartProcessorInterface
{
    public const TYPE = 'shopproof-deposit';

    public const PAYLOAD_DEPOSIT = 'shopproofDeposit';

    public const PAYLOAD_DESCRIPTION = 'shopproofCartDescription';

    public const DESCRIPTION_FIELD = 'shopproof_cart_description';

    public function __construct(
        private readonly NotesSettings $settings,
        private readonly QuantityPriceCalculator $calculator,
    ) {
    }

    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        $channel = $context->getSalesChannelId();
        $types = $this->settings->depositTypes($channel)['types'];
        $country = (string) $context->getShippingLocation()->getCountry()->getIso();
        $descriptions = $this->settings->bool('cartDescriptions', $channel);

        /** @var array<string, array{gross: float, rules: TaxRuleCollection}> $sums */
        $sums = [];
        foreach ($toCalculate->getLineItems()->filterFlatByType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $item) {
            $fields = $item->getPayloadValue('customFields');
            $fields = \is_array($fields) ? $fields : null;

            $description = $descriptions ? FoodInformation::plain($fields[self::DESCRIPTION_FIELD] ?? null, 200) : '';
            if ($description !== '') {
                $item->setPayloadValue(self::PAYLOAD_DESCRIPTION, $description);
            } elseif ($item->hasPayloadValue(self::PAYLOAD_DESCRIPTION)) {
                $item->removePayloadValue(self::PAYLOAD_DESCRIPTION);
            }

            $deposit = $types === [] ? 0.0 : Deposit::forProduct($fields, $types, $country)['total'];
            if ($deposit <= 0) {
                if ($item->hasPayloadValue(self::PAYLOAD_DEPOSIT)) {
                    $item->removePayloadValue(self::PAYLOAD_DEPOSIT);
                }
                continue;
            }
            $deposit = round($deposit * $context->getCurrency()->getFactor(), 2);
            $item->setPayloadValue(self::PAYLOAD_DEPOSIT, $deposit);

            $rules = $this->settings->depositTax($channel) === 'none' || $item->getPrice() === null
                ? new TaxRuleCollection()
                : $item->getPrice()->getTaxRules();
            $key = self::rulesKey($rules);
            $sums[$key] ??= ['gross' => 0.0, 'rules' => $rules];
            $sums[$key]['gross'] += $deposit * $item->getQuantity();
        }

        if ($sums === [] || $this->settings->depositInPrice($channel)) {
            return;
        }

        $lang = NotesTexts::language((string) $context->getLanguageInfo()->localeCode, $this->settings->shopCountry($context->getSalesChannelId()));
        $label = Deposit::LINE_NAME[$lang] ?? Deposit::LINE_NAME['en'];
        $count = 0;
        ksort($sums);
        foreach ($sums as $key => $sum) {
            ++$count;
            $price = round($sum['gross'], 2);
            if ($context->getTaxState() !== 'gross') {
                // The amounts in the settings are what consumers pay; net prices leave out the VAT.
                $price = $price / (1 + self::rate($sum['rules']) / 100);
            }
            $line = new LineItem(self::TYPE . '-' . substr(md5($key), 0, 12), self::TYPE, null, 1);
            $line->setLabel(\count($sums) === 1 ? $label : $label . ' (' . $count . ')');
            $line->setGood(false);
            $line->setRemovable(false);
            $line->setStackable(false);
            $line->setPayloadValue('shopproofDepositTax', $key);
            $definition = new QuantityPriceDefinition($price, $sum['rules'], 1);
            $line->setPriceDefinition($definition);
            $line->setPrice($this->calculator->calculate($definition, $context));
            $toCalculate->add($line);
        }
    }

    private static function rulesKey(TaxRuleCollection $rules): string
    {
        $parts = [];
        foreach ($rules as $rule) {
            $parts[] = $rule->getTaxRate() . ':' . $rule->getPercentage();
        }
        sort($parts);

        return $parts === [] ? 'none' : implode(',', $parts);
    }

    private static function rate(TaxRuleCollection $rules): float
    {
        $rate = 0.0;
        foreach ($rules as $rule) {
            $rate += $rule->getTaxRate() * $rule->getPercentage() / 100;
        }

        return $rate;
    }
}
