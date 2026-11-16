<?php declare(strict_types=1);

namespace Shopproof\Compliance\Placement;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A storefront page opened with a valid preview key (PreviewToken) may be shown in a frame on the
 * shop's own address, so the placement check on the Shopproof page can look at it. Every other
 * request keeps Shopware's "X-Frame-Options: deny". The answer is private and no-store, so the
 * framable copy never sits in Shopware's page cache or a proxy. This runs after Shopware sets its
 * own security and cache headers.
 */
final class PreviewSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly PreviewToken $token)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -2100]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $scopes = $request->attributes->get('_routeScope', []);
        if (!\is_array($scopes) || !\in_array('storefront', $scopes, true)) {
            return;
        }
        self::apply($this->token, $request->query->get(PreviewToken::PARAM), $event->getResponse(), time());
    }

    public static function apply(PreviewToken $token, mixed $key, Response $response, int $now): bool
    {
        if (!$token->verify($key, $now)) {
            return false;
        }
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $policy = $response->headers->get('Content-Security-Policy');
        if (\is_string($policy) && str_contains($policy, 'frame-ancestors')) {
            $response->headers->set('Content-Security-Policy', (string) preg_replace('/frame-ancestors[^;]*/', "frame-ancestors 'self'", $policy));
        }
        $response->headers->remove('Cache-Control');
        $response->headers->remove('Expires');
        $response->headers->remove('sw-invalidation-states');
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->addCacheControlDirective('must-revalidate');
        $response->setMaxAge(0);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return true;
    }
}
