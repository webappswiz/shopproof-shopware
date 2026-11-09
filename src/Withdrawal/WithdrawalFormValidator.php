<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

use Shopware\Core\Framework\Validation\BuildValidationEvent;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\Constraints\Date;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Adds the order date to the checks of Shopware's withdrawal form when the shop asks for it:
 * a real date, not in the future, and not empty when the setting says "Required". Also the
 * comment when the shop made it required.
 */
final class WithdrawalFormValidator implements EventSubscriberInterface
{
    /** BuildValidationEvent name for Shopware's withdrawal form (revocation_request_form.create). */
    public const EVENT = 'framework.validation.revocation_request_form.create';

    public function __construct(private readonly SystemConfigService $config)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [self::EVENT => 'onBuild'];
    }

    public function onBuild(BuildValidationEvent $event): void
    {
        $source = $event->getContext()->getSource();
        $channel = $source instanceof SalesChannelApiSource ? $source->getSalesChannelId() : null;
        $mode = $this->config->getString('ShopproofCompliance.config.withdrawalOrderDate', $channel);
        $tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
        if ($this->config->getString('ShopproofCompliance.config.withdrawalComment', $channel) === 'required') {
            $event->getDefinition()->add('comment', new NotBlank());
        }
        if ($mode === 'required') {
            $event->getDefinition()->add('orderDate', new NotBlank(), new Date(), new LessThanOrEqual(value: $tomorrow));
        } elseif ($mode === 'optional') {
            $event->getDefinition()->add('orderDate', new Date(), new LessThanOrEqual(value: $tomorrow));
        }
    }
}
