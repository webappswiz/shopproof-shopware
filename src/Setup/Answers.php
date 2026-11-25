<?php declare(strict_types=1);

namespace Shopproof\Compliance\Setup;

use Shopproof\Compliance\Notes\NotesSettings;
use Shopproof\Compliance\Notes\NotesTexts;
use Shopproof\Compliance\Notes\ReviewNotice;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Ready-made answers (zero-thought plan item 10), as on WooCommerce and Shopify: the merchant
 * choices most shops answer the same way, offered on the Overview with the answer written out.
 * The shop owner ticks the ones that are true for the shop and saves. Nothing is ticked or saved
 * for them, because only the owner knows whether the answer is true.
 *
 * - Dispute statement: "We do not take part". Not offered to shops in France, where every shop
 *   must offer a consumer mediator (Code de la consommation, Art. L612-1).
 * - Review check notice: "We do not check", because Shopware does not check purchases for
 *   reviews by itself. Only while the storefront shows reviews.
 *
 * Both are saved for all sales channels; a sales channel with its own answer keeps it.
 */
final class Answers
{
    /** Answer key => [Shopproof setting, ready-made choice, shopper text key]. */
    public const ANSWERS = [
        'dispute' => ['disputeChoice', 'no', 'notices.disputeNo'],
        'reviews' => ['reviewsChoice', 'none', 'notices.reviewsNone'],
    ];

    public const DISPUTE_NO_COUNTRIES = ['FR'];

    private const CONFIG = 'ShopproofCompliance.config.';

    public function __construct(
        private readonly SystemConfigService $config,
        private readonly NotesSettings $settings,
        private readonly NotesTexts $texts,
    ) {
    }

    /**
     * What the shop has answered so far. Read only.
     *
     * @return array{country: string, dispute: string, reviews: string, reviewsShown: bool}
     */
    public function facts(): array
    {
        $shown = $this->config->get('core.listing.showReview');
        $reviews = $this->config->getString(self::CONFIG . 'reviewsChoice');

        return [
            'country' => $this->settings->shopCountry(null),
            'dispute' => $this->settings->disputeChoice(null),
            'reviews' => \in_array($reviews, ReviewNotice::CHOICES, true) ? $reviews : '',
            // Shopware shows reviews unless the shop switched them off.
            'reviewsShown' => $shown === null || (bool) $shown,
        ];
    }

    /**
     * The answer keys for the questions the shop has not answered yet. Pure.
     *
     * @param array{country?: string, dispute?: string, reviews?: string, reviewsShown?: bool} $facts
     *
     * @return list<string>
     */
    public static function offer(array $facts): array
    {
        $out = [];
        if (($facts['dispute'] ?? '') === '' && !\in_array(strtoupper($facts['country'] ?? ''), self::DISPUTE_NO_COUNTRIES, true)) {
            $out[] = 'dispute';
        }
        if (($facts['reviews'] ?? '') === '' && !empty($facts['reviewsShown'])) {
            $out[] = 'reviews';
        }

        return $out;
    }

    /**
     * The offered answers with the words shoppers see, in the admin language.
     *
     * @return list<array{key: string, text: string}>
     */
    public function offered(string $locale): array
    {
        $lang = NotesTexts::language($locale, $this->settings->shopCountry(null));
        $out = [];
        foreach (self::offer($this->facts()) as $key) {
            $out[] = ['key' => $key, 'text' => $this->texts->text(self::ANSWERS[$key][2], $lang)];
        }

        return $out;
    }

    /**
     * Saves the ticked answers that are still offered, so an answer the shop gave in the settings
     * in the meantime is kept.
     *
     * @param list<string> $keys
     *
     * @return list<string> the keys that were saved
     */
    public function apply(array $keys): array
    {
        $done = [];
        foreach (self::offer($this->facts()) as $key) {
            if (!\in_array($key, $keys, true)) {
                continue;
            }
            [$field, $choice] = self::ANSWERS[$key];
            $this->config->set(self::CONFIG . $field, $choice);
            $done[] = $key;
        }

        return $done;
    }
}
