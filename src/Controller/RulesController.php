<?php declare(strict_types=1);

namespace Shopproof\Compliance\Controller;

use Shopproof\Compliance\Rules\RulesFile;
use Shopproof\Compliance\Rules\RulesStore;
use Shopproof\Compliance\Rules\RulesUpdater;
use Shopproof\Compliance\ScheduledTask\RefreshRulesTaskHandler;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin API for the Shopproof overview: the rules for the countries the shop sells to, with rules
 * held for review left out.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
final class RulesController extends AbstractController
{
    /** Fields an overview needs. Internal review fields stay out of the answer. */
    private const FIELDS = [
        'id', 'jurisdiction', 'area', 'requirement', 'shop_must', 'where_in_shop', 'applies_to',
        'exemptions', 'effective_from', 'status', 'legal_basis', 'source_url', 'notes',
    ];

    public function __construct(
        private readonly RulesUpdater $updater,
        private readonly RulesStore $store,
        private readonly SystemConfigService $config,
    ) {
    }

    #[Route(
        path: '/api/_action/shopproof/rules',
        name: 'api.action.shopproof.rules',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:read']],
        methods: ['GET']
    )]
    public function rules(): JsonResponse
    {
        ['file' => $file, 'downloaded' => $downloaded] = $this->updater->current();
        $countries = $this->countries();
        $rules = array_map(
            static fn (array $rule): array => array_intersect_key($rule, array_flip(self::FIELDS)),
            RulesFile::forCountries($file, $countries)
        );

        return new JsonResponse([
            'available' => $file !== null,
            'version' => RulesFile::text($file, 'version'),
            'disclaimer' => RulesFile::text($file, 'disclaimer', RulesFile::DEFAULT_DISCLAIMER),
            'downloaded' => $downloaded,
            'fetchedAt' => $downloaded ? $this->store->fetchedAt()?->format(\DATE_ATOM) : null,
            'autoUpdate' => $this->config->getBool(RefreshRulesTaskHandler::SETTING),
            'countries' => $countries,
            'counts' => RulesFile::summarize($file),
            'rules' => $rules,
        ]);
    }

    /**
     * "Check for rule updates now" in the Shopproof overview: the merchant asks for a download.
     */
    #[Route(
        path: '/api/_action/shopproof/rules/refresh',
        name: 'api.action.shopproof.rules.refresh',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:update']],
        methods: ['POST']
    )]
    public function refresh(): JsonResponse
    {
        return new JsonResponse(['updated' => $this->updater->refresh()]);
    }

    /**
     * Countries chosen in the plugin settings, limited to the launch countries. All of them when
     * nothing usable is set.
     *
     * @return list<string>
     */
    private function countries(): array
    {
        $chosen = $this->config->get('ShopproofCompliance.config.countries');
        $chosen = \is_array($chosen) ? $chosen : [];
        $valid = array_values(array_filter(
            array_map(static fn ($code): string => \is_string($code) ? strtoupper($code) : '', $chosen),
            static fn (string $code): bool => $code !== 'EU' && \in_array($code, RulesFile::LAUNCH_COUNTRIES, true)
        ));

        return $valid !== [] ? array_values(array_unique($valid)) : array_values(array_diff(RulesFile::LAUNCH_COUNTRIES, ['EU']));
    }
}
