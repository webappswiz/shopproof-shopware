<?php declare(strict_types=1);

namespace Shopproof\Compliance;

use Doctrine\DBAL\Connection;
use Shopproof\Compliance\Installer\CustomFieldInstaller;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;

/**
 * Shopproof EU Compliance for Shopware 6.7.
 *
 * Runs entirely on the shop's own server. The only outside request is the daily download of the
 * public Shopproof rules file, and only when the merchant asks for it. Withdrawal records and the
 * proof log stay in the shop's own database.
 */
class ShopproofCompliance extends Plugin
{
    /**
     * Tables this plugin owns. Removed on uninstall unless the merchant keeps the data.
     */
    public const TABLES = ['shopproof_rules_cache', 'shopproof_withdrawal_request', 'shopproof_proof_log'];

    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
        $this->customFields()?->install($installContext->getContext());
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);
        $this->customFields()?->install($updateContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->container?->get(Connection::class);
        if (!$connection instanceof Connection) {
            return;
        }

        $customFields = $this->customFields();
        $customFields?->removeValues();
        $customFields?->uninstall($uninstallContext->getContext());

        foreach (self::TABLES as $table) {
            $connection->executeStatement(\sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }
        $connection->executeStatement(
            'DELETE FROM system_config WHERE configuration_key LIKE :prefix',
            ['prefix' => 'ShopproofCompliance.%']
        );
    }

    /**
     * Built by hand because the plugin's own services are not in the container during install and
     * uninstall.
     */
    private function customFields(): ?CustomFieldInstaller
    {
        $repository = $this->container?->get('custom_field_set.repository');
        $connection = $this->container?->get(Connection::class);
        if (!$repository instanceof EntityRepository || !$connection instanceof Connection) {
            return null;
        }

        return new CustomFieldInstaller($repository, $connection);
    }
}
