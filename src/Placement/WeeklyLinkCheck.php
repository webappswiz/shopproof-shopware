<?php declare(strict_types=1);

namespace Shopproof\Compliance\Placement;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Runs the weekly link check (see LinkCheck) on the home page and a product page of every active
 * storefront that has a withdrawal page. Opens only the shop's own storefront addresses.
 */
class WeeklyLinkCheck
{
    public function __construct(
        private readonly PlacementPages $pages,
        private readonly SystemConfigService $config,
        private readonly ClientInterface $client,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{time: int, found: int, missing: int, unchecked: int}|null null when no storefront has a withdrawal page yet
     */
    public function run(?int $now = null): ?array
    {
        $now ??= time();
        $collected = $this->pages->collect($now);
        $channels = array_values(array_filter($collected['channels'], static fn (array $c): bool => $c['paths'] !== []));
        if ($channels === []) {
            return null;
        }
        $result = ['time' => $now, 'found' => 0, 'missing' => 0, 'unchecked' => 0];
        foreach ($channels as $channel) {
            foreach ($channel['pages'] as $page) {
                if (!\in_array($page['key'], ['home', 'product'], true)) {
                    continue;
                }
                $html = StorefrontPage::get($this->client, $this->logger, $page['url']);
                $found = $html === null ? null : LinkCheck::hasLink($html, $channel['paths'], $collected['labels']);
                if ($found === null) {
                    ++$result['unchecked'];
                } elseif ($found) {
                    ++$result['found'];
                } else {
                    ++$result['missing'];
                }
            }
        }
        $this->config->set(LinkCheck::CONFIG, $result);

        return $result;
    }
}
