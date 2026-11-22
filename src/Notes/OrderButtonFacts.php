<?php declare(strict_types=1);

namespace Shopproof\Compliance\Notes;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Translation\AbstractTranslator;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Reads the order button text of every language of every active storefront, the way the confirm
 * page shows it: Shopware's snippet checkout.confirmSubmit with the storefront's snippet set, so a
 * text the merchant changed under Settings > Snippets counts. A theme that writes its own button
 * text into the template is not seen. Only reads the shop's own data.
 */
class OrderButtonFacts
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AbstractTranslator $translator,
    ) {
    }

    /**
     * @return list<array{channel: string, locale: string, text: string}>
     */
    public function collect(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(sc.id)) AS channel, LOWER(HEX(l.id)) AS language, lo.code AS locale, COALESCE(sct.name, \'\') AS name
             FROM sales_channel sc
             INNER JOIN sales_channel_language scl ON scl.sales_channel_id = sc.id
             INNER JOIN language l ON l.id = scl.language_id
             INNER JOIN locale lo ON lo.id = l.locale_id
             LEFT JOIN sales_channel_translation sct ON sct.sales_channel_id = sc.id AND sct.language_id = sc.language_id
             WHERE sc.type_id = :type AND sc.active = 1
             ORDER BY sct.name, lo.code',
            ['type' => Uuid::fromHexToBytes(Defaults::SALES_CHANNEL_TYPE_STOREFRONT)]
        );
        $out = [];
        $context = new Context(new SystemSource(), [], Defaults::CURRENCY, [Defaults::LANGUAGE_SYSTEM]);
        foreach ($rows as $row) {
            $channel = \is_string($row['channel'] ?? null) ? $row['channel'] : '';
            $language = \is_string($row['language'] ?? null) ? $row['language'] : '';
            $locale = \is_string($row['locale'] ?? null) ? $row['locale'] : '';
            if ($channel === '' || $language === '' || $locale === '') {
                continue;
            }
            try {
                $this->translator->injectSettings($channel, $language, $locale, $context);
                $text = $this->translator->trans(OrderButtonCheck::SNIPPET);
            } finally {
                $this->translator->resetInjection();
            }
            $text = trim(html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5));
            if ($text === '' || $text === OrderButtonCheck::SNIPPET) {
                // No snippet in this language: nothing to compare, so the line is left out.
                continue;
            }
            $out[] = ['channel' => \is_string($row['name'] ?? null) ? $row['name'] : '', 'locale' => $locale, 'text' => $text];
        }

        return $out;
    }
}
