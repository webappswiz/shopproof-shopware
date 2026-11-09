<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Item picker for Shopware's withdrawal form, as in the WooCommerce plugin and the Shopify app:
 * the items of an order with their photos, so the customer ticks what goes back instead of
 * typing it. The order is found by its number and the order's email (the same check as the
 * withdrawal record) or by the signed link from the order emails, only in the same sales channel.
 * Only names, quantities and photos are sent, never prices or addresses. Items the shop excludes
 * from withdrawal (by category, or digital products) stay in the list, marked with the shop's note.
 */
class WithdrawalItems
{
    public const MAX_ITEMS = 100;
    public const RATE_LIMIT = 20;

    private const CONFIG = 'ShopproofCompliance.config.';

    private ?bool $hasType = null;

    /**
     * @param EntityRepository<OrderLineItemCollection> $lineItems
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $config,
        private readonly WithdrawalStore $store,
        private readonly OrderLink $link,
        private readonly EntityRepository $lineItems,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function pickerOn(?string $channel): bool
    {
        return $this->config->get(self::CONFIG . 'itemPicker', $channel) !== false;
    }

    /**
     * The order for a lookup: the signed link first, else number and email. '' when none fits.
     *
     * @param array<mixed> $input
     */
    public function orderFor(array $input, string $channel): string
    {
        $keys = OrderLink::fromQuery($input);
        if ($keys !== null) {
            $row = $this->connection->fetchAssociative(
                'SELECT LOWER(HEX(o.sales_channel_id)) AS channel, oc.email FROM `order` o
                 INNER JOIN order_customer oc ON oc.order_id = o.id AND oc.order_version_id = o.version_id
                 WHERE o.id = :id AND o.version_id = :version LIMIT 1',
                ['id' => Uuid::fromHexToBytes($keys['order']), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
            );
            if (\is_array($row) && ($row['channel'] ?? null) === strtolower($channel) && \is_string($row['email'] ?? null)
                && $this->link->verify($keys['order'], $row['email'], $keys['token'])) {
                return $keys['order'];
            }
        }
        $number = self::text($input['contractNumber'] ?? null, 255);
        $email = self::text($input['email'] ?? null, 320);
        if ($number === '' || filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            return '';
        }

        return $this->store->orderFor($number, $email, $channel);
    }

    /**
     * The product lines of an order.
     *
     * @return list<array{id: string, name: string, quantity: int, image: string, excluded: bool}>
     */
    public function items(string $orderId, string $channel, Context $context): array
    {
        if (!Uuid::isValid($orderId)) {
            return [];
        }
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('orderId', $orderId))
            ->addFilter(new EqualsFilter('type', LineItem::PRODUCT_LINE_ITEM_TYPE))
            ->addFilter(new RangeFilter('quantity', [RangeFilter::GT => 0]))
            ->addSorting(new FieldSorting('position'))
            ->addAssociation('cover.thumbnails')
            ->setLimit(self::MAX_ITEMS);
        $found = $this->lineItems->search($criteria, $context)->getEntities();
        $lines = [];
        foreach ($found as $line) {
            if ($line->getParentId() === null) {
                $lines[] = $line;
            }
        }
        $excluded = $this->excludedProducts(array_values(array_filter(array_map(static fn (OrderLineItemEntity $l): ?string => $l->getProductId(), $lines))), $channel);
        $out = [];
        foreach ($lines as $line) {
            $out[] = [
                'id' => $line->getId(),
                'name' => trim(strip_tags($line->getLabel())),
                'quantity' => $line->getQuantity(),
                'image' => self::image($line),
                'excluded' => $line->getProductId() !== null && \in_array($line->getProductId(), $excluded, true),
            ];
        }

        return $out;
    }

    /**
     * The shop's note next to excluded items, or '' for the default words from the snippets.
     */
    public function note(?string $channel): string
    {
        $note = $this->config->get(self::CONFIG . 'exclusionNote', $channel);

        return \is_string($note) ? mb_substr(trim($note), 0, 300) : '';
    }

    /**
     * Names of the order's items the shop excludes, for the shop's copy of the withdrawal.
     *
     * @return list<string>
     */
    public function excludedNames(string $orderId, string $channel, Context $context): array
    {
        $names = [];
        foreach ($this->items($orderId, $channel, $context) as $item) {
            if ($item['excluded']) {
                $names[] = $item['name'];
            }
        }

        return $names;
    }

    /**
     * Allows a few lookups per connection per hour, so order numbers cannot be tried in bulk. The
     * address is kept only as a keyed hash in the shop's cache, until the hour is over.
     */
    public function withinRateLimit(string $ip): bool
    {
        return LookupLimit::allow($this->cache, 'shopproof_items_' . substr(hash_hmac('sha256', $ip, $this->link->token('rate', 'limit')), 0, 32), time(), self::RATE_LIMIT);
    }

    /**
     * Products (hex ids) in an excluded category or its sub-categories, or digital when the shop
     * excludes digital products. A variant counts with its parent's categories.
     *
     * @param list<string> $productIds
     *
     * @return list<string>
     */
    private function excludedProducts(array $productIds, string $channel): array
    {
        $categories = $this->config->get(self::CONFIG . 'excludedCategories', $channel);
        $categories = \is_array($categories) ? array_values(array_filter($categories, static fn (mixed $id): bool => \is_string($id) && Uuid::isValid($id))) : [];
        $digital = $this->config->getBool(self::CONFIG . 'excludeDigital', $channel) && $this->hasType();
        $productIds = array_values(array_filter($productIds, static fn (string $id): bool => Uuid::isValid($id)));
        if ($productIds === [] || ($categories === [] && !$digital)) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(p.id)) AS id, COALESCE(p.category_tree, parent.category_tree) AS tree'
            . ($digital ? ', COALESCE(p.type, parent.type) AS type' : '')
            . ' FROM product p LEFT JOIN product parent ON parent.id = p.parent_id AND parent.version_id = p.version_id
             WHERE p.id IN (:ids) AND p.version_id = :version',
            ['ids' => array_map(Uuid::fromHexToBytes(...), $productIds), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
            ['ids' => ArrayParameterType::BINARY]
        );
        $wanted = array_map('strtolower', $categories);
        $out = [];
        foreach ($rows as $row) {
            $tree = \is_string($row['tree'] ?? null) ? json_decode($row['tree'], true) : null;
            $tree = \is_array($tree) ? array_map(static fn (mixed $id): string => \is_string($id) ? strtolower($id) : '', $tree) : [];
            if (($digital && ($row['type'] ?? null) === 'digital') || array_intersect($tree, $wanted) !== []) {
                $out[] = \is_string($row['id'] ?? null) ? $row['id'] : '';
            }
        }

        return $out;
    }

    /**
     * Whether this Shopware version has product types (physical, digital).
     */
    private function hasType(): bool
    {
        return $this->hasType ??= $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'product\' AND COLUMN_NAME = \'type\''
        ) > 0;
    }

    /**
     * The product photo: the smallest thumbnail at least 100 pixels wide, else the photo itself.
     */
    private static function image(OrderLineItemEntity $line): string
    {
        $cover = $line->getCover();
        if ($cover === null) {
            return '';
        }
        $best = null;
        foreach ($cover->getThumbnails() ?? [] as $thumbnail) {
            if ($thumbnail->getWidth() >= 100 && ($best === null || $thumbnail->getWidth() < $best->getWidth())) {
                $best = $thumbnail;
            }
        }
        $url = $best !== null ? $best->getUrl() : $cover->getUrl();

        return preg_match('#^https?://#i', $url) ? $url : '';
    }

    private static function text(mixed $value, int $max): string
    {
        return \is_string($value) ? mb_substr(trim($value), 0, $max) : '';
    }
}
