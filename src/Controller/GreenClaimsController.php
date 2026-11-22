<?php declare(strict_types=1);

namespace Shopproof\Compliance\Controller;

use Doctrine\DBAL\Connection;
use Shopproof\Compliance\Notes\GreenClaims;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin API for the green claims check (Directive (EU) 2024/825): the names and descriptions of
 * the shop's most recently changed active products, in the default language, checked for the
 * Directive's own example words. Reads only the shop's own database; nothing is stored and
 * nothing leaves the shop.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
final class GreenClaimsController extends AbstractController
{
    public function __construct(
        private readonly GreenClaims $claims,
        private readonly Connection $connection,
    ) {
    }

    #[Route(
        path: '/api/_action/shopproof/green-claims',
        name: 'api.action.shopproof.green_claims',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['product:read']],
        methods: ['GET']
    )]
    public function check(): JsonResponse
    {
        if (!$this->claims->ready()) {
            return new JsonResponse(['ready' => false]);
        }
        $params = [
            'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            'language' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
        ];
        $total = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM product p WHERE p.version_id = :version AND p.parent_id IS NULL AND p.active = 1',
            ['version' => $params['version']]
        );
        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT LOWER(HEX(p.id)) AS id, pt.name AS name, pt.description AS description
                 FROM product p
                 LEFT JOIN product_translation pt ON pt.product_id = p.id AND pt.product_version_id = p.version_id AND pt.language_id = :language
                 WHERE p.version_id = :version AND p.parent_id IS NULL AND p.active = 1
                 ORDER BY COALESCE(p.updated_at, p.created_at) DESC, p.id
                 LIMIT %d',
                $this->claims->maxProducts()
            ),
            $params
        );
        $products = [];
        foreach ($rows as $row) {
            $products[] = [
                'id' => \is_string($row['id'] ?? null) ? $row['id'] : '',
                'title' => \is_string($row['name'] ?? null) ? trim($row['name']) : '',
                'text' => \is_string($row['description'] ?? null) ? GreenClaims::plain($row['description']) : '',
            ];
        }

        return new JsonResponse([
            'ready' => true,
            'total' => is_numeric($total) ? (int) $total : \count($products),
            ...$this->claims->scanProducts($products),
        ]);
    }
}
