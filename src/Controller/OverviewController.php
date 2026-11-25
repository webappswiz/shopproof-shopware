<?php declare(strict_types=1);

namespace Shopproof\Compliance\Controller;

use Shopproof\Compliance\Setup\Answers;
use Shopproof\Compliance\Setup\ExclusionHints;
use Shopproof\Compliance\Setup\OverviewData;
use Shopproof\Compliance\Setup\QuietEmailSender;
use Shopproof\Compliance\Setup\Recommended;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin API for the top of the Shopproof page: the setup checklist and the status cards
 * (Overview). Reads only the shop's own database; nothing leaves the shop.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
final class OverviewController extends AbstractController
{
    public function __construct(
        private readonly OverviewData $overview,
        private readonly Recommended $recommended,
        private readonly Answers $answers,
        private readonly ExclusionHints $hints,
        private readonly QuietEmailSender $quiet,
    ) {
    }

    #[Route(
        path: '/api/_action/shopproof/overview',
        name: 'api.action.shopproof.overview',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:read']],
        methods: ['GET']
    )]
    public function overview(Request $request): JsonResponse
    {
        $out = $this->overview->build(self::zone($request->query->get('timezone')));
        // Ready-made answers, with the words shoppers see in the admin language.
        $locale = $request->query->get('locale');
        $out['answers'] = $this->answers->offered(\is_string($locale) ? $locale : '');
        // Suggested withdrawal exclusions from the catalogue, nothing ticked.
        $out['exclusionHints'] = ExclusionHints::suggest($this->hints->facts());
        // The quiet email switch and where the email goes.
        $out['quietEmail'] = ['on' => $this->quiet->on(), 'to' => $this->quiet->recipient()];

        return new JsonResponse($out);
    }

    /**
     * The press: switches on what the shop's facts call for right now (read again here, never
     * taken from the request).
     */
    #[Route(
        path: '/api/_action/shopproof/recommended/apply',
        name: 'api.action.shopproof.recommended.apply',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:update']],
        methods: ['POST']
    )]
    public function applyRecommended(): JsonResponse
    {
        $done = $this->recommended->apply(Recommended::plan($this->recommended->facts()), time());

        return new JsonResponse(['switched' => $done]);
    }

    #[Route(
        path: '/api/_action/shopproof/recommended/undo',
        name: 'api.action.shopproof.recommended.undo',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:update']],
        methods: ['POST']
    )]
    public function undoRecommended(): JsonResponse
    {
        return new JsonResponse(['undone' => $this->recommended->undo()]);
    }

    /**
     * Saves the ticked ready-made answers. Only answers still offered are saved.
     */
    #[Route(
        path: '/api/_action/shopproof/answers/apply',
        name: 'api.action.shopproof.answers.apply',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:update']],
        methods: ['POST']
    )]
    public function applyAnswers(Request $request): JsonResponse
    {
        $keys = $request->request->all()['keys'] ?? [];
        $keys = \is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];

        return new JsonResponse(['saved' => $this->answers->apply($keys)]);
    }

    /**
     * Excludes the ticked suggestions. Only suggestions still offered are saved.
     */
    #[Route(
        path: '/api/_action/shopproof/exclusion-hints/apply',
        name: 'api.action.shopproof.exclusion_hints.apply',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:update']],
        methods: ['POST']
    )]
    public function applyExclusionHints(Request $request): JsonResponse
    {
        $keys = $request->request->all()['keys'] ?? [];
        $keys = \is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];

        return new JsonResponse(['excluded' => $this->hints->apply($keys)]);
    }

    /**
     * Hides the suggestions now shown, for good. Excludes nothing.
     */
    #[Route(
        path: '/api/_action/shopproof/exclusion-hints/hide',
        name: 'api.action.shopproof.exclusion_hints.hide',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:update']],
        methods: ['POST']
    )]
    public function hideExclusionHints(): JsonResponse
    {
        return new JsonResponse(['hidden' => $this->hints->hide()]);
    }

    /**
     * The quiet email switch: on or off, nothing else changes.
     */
    #[Route(
        path: '/api/_action/shopproof/quiet-email',
        name: 'api.action.shopproof.quiet_email',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:update']],
        methods: ['POST']
    )]
    public function quietEmail(Request $request): JsonResponse
    {
        $this->quiet->switchTo(($request->request->all()['on'] ?? null) === true);

        return new JsonResponse(['on' => $this->quiet->on()]);
    }

    private static function zone(mixed $name): \DateTimeZone
    {
        try {
            return new \DateTimeZone(\is_string($name) && \in_array($name, \DateTimeZone::listIdentifiers(), true) ? $name : 'UTC');
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }
}
