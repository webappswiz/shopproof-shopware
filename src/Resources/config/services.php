<?php declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use GuzzleHttp\Client;
use Shopproof\Compliance\Cache\ManufacturerCacheSubscriber;
use Shopproof\Compliance\Command\RefreshRulesCommand;
use Shopproof\Compliance\Controller\RulesController;
use Shopproof\Compliance\Controller\WithdrawalController;
use Shopproof\Compliance\Rules\RulesStore;
use Shopproof\Compliance\Rules\RulesUpdater;
use Shopproof\Compliance\ScheduledTask\RefreshRulesTask;
use Shopproof\Compliance\ScheduledTask\RefreshRulesTaskHandler;
use Shopproof\Compliance\Storefront\DurabilityLabel;
use Shopproof\Compliance\Storefront\GuaranteeNotice;
use Shopproof\Compliance\Twig\ShopproofExtension;
use Shopproof\Compliance\Withdrawal\SetupFacts;
use Shopproof\Compliance\Withdrawal\WithdrawalStore;
use Shopproof\Compliance\Withdrawal\WithdrawalSubscriber;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set('shopproof.http_client', Client::class)
        ->args([['timeout' => 10, 'connect_timeout' => 5]]);

    $services->set(RulesStore::class);

    $services->set(RulesUpdater::class)
        ->arg('$client', service('shopproof.http_client'));

    $services->set(RefreshRulesTask::class)
        ->tag('shopware.scheduled.task');

    $services->set(RefreshRulesTaskHandler::class)
        ->arg('$scheduledTaskRepository', service('scheduled_task.repository'));

    $services->set(RefreshRulesCommand::class);

    $services->set(GuaranteeNotice::class)
        ->args([__DIR__ . '/guarantee-notice.json']);

    $services->set(DurabilityLabel::class)
        ->args([__DIR__ . '/durability-label.json']);

    $services->set(ShopproofExtension::class)
        ->arg('$packages', service('assets.packages'))
        ->tag('twig.extension');

    $services->set(RulesController::class)
        ->public()
        ->call('setContainer', [service('service_container')]);

    $services->set(WithdrawalStore::class);

    $services->set(SetupFacts::class);

    $services->set(WithdrawalSubscriber::class)
        ->arg('$logger', service('logger'))
        ->tag('kernel.event_subscriber');

    $services->set(ManufacturerCacheSubscriber::class)
        ->arg('$logger', service('logger'))
        ->tag('kernel.event_subscriber');

    $services->set(WithdrawalController::class)
        ->public()
        ->call('setContainer', [service('service_container')]);
};
