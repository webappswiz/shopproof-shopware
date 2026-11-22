<?php declare(strict_types=1);

namespace Shopproof\Compliance\Twig;

use Shopproof\Compliance\Storefront\EnergyLabel;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * shopproof_energy(product, context): the energy label arrow, the product information sheet link
 * and the French index for the price on the product page and in listings, as HTML, or ''.
 * shopproof_energy_css(): the styles, once per page. Variants inherit the parent's custom fields
 * in Shopware, so a variant with its own class (another screen size) shows its own arrow.
 */
final class EnergyExtension extends AbstractExtension implements ResetInterface
{
    /** @var array<string, string> media id to address, for this request */
    private array $media = [];

    /**
     * @param EntityRepository<MediaCollection> $mediaRepository
     */
    public function __construct(
        private readonly EnergyLabel $energy,
        private readonly EntityRepository $mediaRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('shopproof_energy', $this->energyHtml(...), ['is_safe' => ['html']]),
            new TwigFunction('shopproof_energy_css', $this->energy->css(...)),
        ];
    }

    public function energyHtml(mixed $product, mixed $context = null): string
    {
        if (!$product instanceof ProductEntity) {
            return '';
        }
        $fields = $product->getTranslation('customFields');
        $fields = \is_array($fields) ? $fields : $product->getCustomFields();
        $shopware = $context instanceof SalesChannelContext ? $context->getContext() : null;
        $raw = EnergyLabel::fromCustomFields(
            \is_array($fields) ? $fields : null,
            $shopware !== null ? fn (string $id): string => $this->mediaUrl($id, $shopware) : null
        );
        $locale = $context instanceof SalesChannelContext ? (string) $context->getLanguageInfo()->localeCode : 'en-GB';

        return $this->energy->html($this->energy->values($raw), $this->energy->texts($locale));
    }

    private function mediaUrl(string $id, Context $context): string
    {
        if (!isset($this->media[$id])) {
            $media = $this->mediaRepository->search(new Criteria([$id]), $context)->getEntities()->get($id);
            $this->media[$id] = $media instanceof MediaEntity ? $media->getUrl() : '';
        }

        return $this->media[$id];
    }

    public function reset(): void
    {
        $this->media = [];
    }
}
