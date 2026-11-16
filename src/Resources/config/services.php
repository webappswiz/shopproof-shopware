<?php declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use GuzzleHttp\Client;
use Shopproof\Compliance\Cache\ManufacturerCacheSubscriber;
use Shopproof\Compliance\Command\RefreshRulesCommand;
use Shopproof\Compliance\Command\SettingsExportCommand;
use Shopproof\Compliance\Command\SettingsImportCommand;
use Shopproof\Compliance\Command\SetupCheckCommand;
use Shopproof\Compliance\Controller\OverviewController;
use Shopproof\Compliance\Controller\PlacementController;
use Shopproof\Compliance\Controller\ProofLogController;
use Shopproof\Compliance\Controller\RulesController;
use Shopproof\Compliance\Controller\SafetyController;
use Shopproof\Compliance\Setup\ShopDetails;
use Shopproof\Compliance\Controller\WithdrawalController;
use Shopproof\Compliance\Controller\WithdrawalStatusController;
use Shopproof\Compliance\Controller\ReturnsController;
use Shopproof\Compliance\Controller\WithdrawalItemsController;
use Shopproof\Compliance\Notes\CheckoutBoxesSubscriber;
use Shopproof\Compliance\Notes\CartFieldsSubscriber;
use Shopproof\Compliance\Notes\DepositCacheSubscriber;
use Shopproof\Compliance\Notes\NotesCartProcessor;
use Shopproof\Compliance\Notes\NotesFacts;
use Shopproof\Compliance\Notes\NotesSettings;
use Shopproof\Compliance\Notes\NotesTexts;
use Shopproof\Compliance\ProofLog\ProofLog;
use Shopproof\Compliance\ProofLog\ProofSnapshot;
use Shopproof\Compliance\Placement\PlacementPages;
use Shopproof\Compliance\Placement\PreviewSubscriber;
use Shopproof\Compliance\Placement\PreviewToken;
use Shopproof\Compliance\ProofLog\RetentionSubscriber;
use Shopproof\Compliance\Rules\RulesStore;
use Shopproof\Compliance\Rules\RulesUpdater;
use Shopproof\Compliance\ScheduledTask\ProofSnapshotTask;
use Shopproof\Compliance\ScheduledTask\ProofSnapshotTaskHandler;
use Shopproof\Compliance\ScheduledTask\RefreshRulesTask;
use Shopproof\Compliance\ScheduledTask\RefreshRulesTaskHandler;
use Shopproof\Compliance\Storefront\DurabilityLabel;
use Shopproof\Compliance\Storefront\GuaranteeNotice;
use Shopproof\Compliance\Storefront\HazardLabel;
use Shopproof\Compliance\Storefront\Registrations;
use Shopproof\Compliance\Checkout\DigitalConsentSubscriber;
use Shopproof\Compliance\Twig\CheckoutExtension;
use Shopproof\Compliance\Twig\ModelFormExtension;
use Shopproof\Compliance\Twig\NotesExtension;
use Shopproof\Compliance\Twig\ShopproofExtension;
use Shopproof\Compliance\Withdrawal\ModelForm;
use Shopproof\Compliance\Twig\WithdrawalExtension;
use Shopproof\Compliance\Withdrawal\OrderEmailLine;
use Shopproof\Compliance\Withdrawal\OrderEmailSubscriber;
use Shopproof\Compliance\Withdrawal\OrderLink;
use Shopproof\Compliance\Withdrawal\WithdrawalFormValidator;
use Shopproof\Compliance\Withdrawal\WithdrawalPrefill;
use Shopproof\Compliance\Withdrawal\Wording;
use Shopproof\Compliance\Withdrawal\SetupFacts;
use Shopproof\Compliance\Withdrawal\WithdrawalStore;
use Shopproof\Compliance\Withdrawal\WithdrawalSubscriber;
use Shopproof\Compliance\Withdrawal\WithdrawalStatus;
use Shopproof\Compliance\Withdrawal\PrefillCacheSubscriber;
use Shopproof\Compliance\Withdrawal\RefundSubscriber;
use Shopproof\Compliance\Withdrawal\ReceiptEmailSubscriber;
use Shopproof\Compliance\Withdrawal\ReturnsWebhook;
use Shopproof\Compliance\Withdrawal\ReturnsWebhookHandler;
use Shopproof\Compliance\Withdrawal\WithdrawalItems;

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

    $services->set(HazardLabel::class)
        ->args([__DIR__ . '/hazards.json']);

    $services->set(Registrations::class)
        ->args([__DIR__ . '/takeback.json']);

    $services->set(OrderEmailLine::class)
        ->args([__DIR__ . '/email-line.json']);

    // The shop's own secret keys the signed withdrawal links, so nothing is stored for them.
    $services->set(OrderLink::class)
        ->args([param('kernel.secret')]);

    $services->set(Wording::class)
        ->arg('$file', __DIR__ . '/withdrawal-alternatives.json');

    // Placement check (decision 0024): a key made with the shop's own secret lets the Shopproof
    // page frame the shop's own pages; nothing is stored for it.
    $services->set(PreviewToken::class)
        ->args([param('kernel.secret')]);

    $services->set(PreviewSubscriber::class)
        ->tag('kernel.event_subscriber');

    $services->set(PlacementPages::class)
        ->arg('$snippetDir', __DIR__ . '/../snippet');

    $services->set(PlacementController::class)
        ->public()
        ->call('setContainer', [service('service_container')]);

    $services->set(OverviewController::class)
        ->public()
        ->call('setContainer', [service('service_container')]);

    $services->set(WithdrawalPrefill::class);

    $services->set(WithdrawalFormValidator::class)
        ->tag('kernel.event_subscriber');

    $services->set(WithdrawalExtension::class)
        ->arg('$requests', service('request_stack'))
        ->tag('twig.extension');

    $services->set(ShopDetails::class);

    $services->set(SafetyController::class)
        ->public()
        ->call('setContainer', [service('service_container')]);

    $services->set(ModelForm::class)
        ->args([__DIR__ . '/model-form.json']);

    $services->set(ModelFormExtension::class)
        ->tag('twig.extension');

    $services->set(CheckoutExtension::class)
        ->tag('twig.extension');

    $services->set(DigitalConsentSubscriber::class)
        ->arg('$requests', service('request_stack'))
        ->arg('$translator', service('translator'))
        ->arg('$logger', service('logger'))
        ->tag('kernel.event_subscriber');

    $services->set(ShopproofExtension::class)
        ->arg('$packages', service('assets.packages'))
        ->tag('twig.extension');

    $services->set(RulesController::class)
        ->public()
        ->call('setContainer', [service('service_container')]);

    $services->set(WithdrawalStore::class);

    $services->set(SetupFacts::class);

    $services->set(SetupCheckCommand::class);

    // Agencies copy one shop's settings to their other client shops; config.xml says what is allowed.
    $services->set(SettingsExportCommand::class)
        ->arg('$schemaFile', __DIR__ . '/config.xml');

    $services->set(SettingsImportCommand::class)
        ->arg('$schemaFile', __DIR__ . '/config.xml');

    $services->set(WithdrawalSubscriber::class)
        ->arg('$logger', service('logger'))
        ->tag('kernel.event_subscriber');

    $services->set(ManufacturerCacheSubscriber::class)
        ->arg('$logger', service('logger'))
        ->tag('kernel.event_subscriber');

    $services->set(OrderEmailSubscriber::class)
        ->arg('$logger', service('logger'))
        ->tag('kernel.event_subscriber');

    $services->set(ProofLog::class);

    $services->set(ProofSnapshot::class);

    $services->set(ProofSnapshotTask::class)
        ->tag('shopware.scheduled.task');

    $services->set(ProofSnapshotTaskHandler::class)
        ->arg('$scheduledTaskRepository', service('scheduled_task.repository'));

    $services->set(RetentionSubscriber::class)
        ->arg('$logger', service('logger'))
        ->tag('kernel.event_subscriber');

    $services->set(ProofLogController::class)
        ->public()
        ->call('setContainer', [service('service_container')]);

    $services->set(WithdrawalController::class)
        ->public()
        ->call('setContainer', [service('service_container')]);

    // Status emails go through Shopware's own mail service, so the shop's mail setup sends them.
    $services->set(WithdrawalStatus::class)
        ->arg('$mail', service('Shopware\\Core\\Content\\Mail\\Service\\MailService'))
        ->arg('$logger', service('logger'));

    $services->set(ReceiptEmailSubscriber::class)
        ->arg('$logger', service('logger'))
        ->tag('kernel.event_subscriber');

    $services->set(PrefillCacheSubscriber::class)
        ->tag('kernel.event_subscriber');

    $services->set(RefundSubscriber::class)
        ->arg('$logger', service('logger'))
        ->tag('kernel.event_subscriber');

    $services->set(WithdrawalStatusController::class)
        ->arg('$pages', service('Shopware\\Storefront\\Page\\GenericPageLoader'))
        ->public()
        ->call('setContainer', [service('service_container')]);

    // Each withdrawal on to the shop's returns tool, from the message queue (off until an address is set).
    $composer = json_decode((string) file_get_contents(__DIR__ . '/../../../composer.json'), true);
    $services->set(ReturnsWebhook::class)
        ->arg('$client', service('shopproof.http_client'))
        ->arg('$mail', service('Shopware\\Core\\Content\\Mail\\Service\\MailService'))
        ->arg('$logger', service('logger'))
        ->arg('$version', \is_array($composer) && \is_string($composer['version'] ?? null) ? $composer['version'] : '');

    $services->set(ReturnsWebhookHandler::class)
        ->arg('$logger', service('logger'));

    $services->set(ReturnsController::class)
        ->public()
        ->call('setContainer', [service('service_container')]);

    // Item picker on Shopware's withdrawal form.
    $services->set(WithdrawalItems::class)
        ->arg('$lineItems', service('order_line_item.repository'))
        ->arg('$cache', service('cache.object'));

    $services->set(WithdrawalItemsController::class)
        ->public();

    // Shop notes ported from the Shopify app: food information, price notes, deposit, cart
    // descriptions, checkout boxes and the dispute statement.
    $services->set(NotesTexts::class)
        ->args([__DIR__ . '/notes-texts.json']);

    $services->set(NotesSettings::class)
        ->tag('kernel.reset', ['method' => 'reset']);

    // After Shopware's product processor (5000) has priced the products.
    $services->set(NotesCartProcessor::class)
        ->arg('$calculator', service('Shopware\\Core\\Checkout\\Cart\\Price\\QuantityPriceCalculator'))
        ->tag('shopware.cart.processor', ['priority' => 4950]);

    $services->set(CheckoutBoxesSubscriber::class)
        ->arg('$requests', service('request_stack'))
        ->arg('$carts', service('Shopware\\Core\\Checkout\\Cart\\SalesChannel\\CartService'))
        ->tag('kernel.event_subscriber');

    $services->set(NotesFacts::class);

    $services->set(DepositCacheSubscriber::class)
        ->tag('kernel.event_subscriber');

    $services->set(CartFieldsSubscriber::class)
        ->tag('kernel.event_subscriber');

    $services->set(NotesExtension::class)
        ->arg('$currency', service('Shopware\\Core\\System\\Currency\\CurrencyFormatter'))
        ->tag('twig.extension');
};
