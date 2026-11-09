<?php declare(strict_types=1);

namespace Shopproof\Compliance\Command;

use Shopproof\Compliance\Setup\SettingsFile;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints this shop's Shopproof settings as a settings file, to import into other shops with
 * shopproof:settings:import. Holds only what the settings page holds (no orders, no customers).
 */
#[AsCommand(name: 'shopproof:settings:export', description: 'Print the Shopproof settings as a file to import into other shops')]
final class SettingsExportCommand extends Command
{
    private const PREFIX = 'ShopproofCompliance.config.';

    public function __construct(
        private readonly SystemConfigService $config,
        private readonly string $schemaFile,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('sales-channel', null, InputOption::VALUE_REQUIRED, 'Sales channel id: the values that channel uses (its own, else the shop-wide ones)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channel = $input->getOption('sales-channel');
        $channel = \is_string($channel) && $channel !== '' ? $channel : null;
        $file = new SettingsFile((string) file_get_contents($this->schemaFile));

        $values = [];
        foreach ($file->names() as $name) {
            $values[$name] = $this->config->get(self::PREFIX . $name, $channel);
        }
        $output->write($file->export($values), false, OutputInterface::OUTPUT_RAW);
        if ($file->skipped() !== []) {
            $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $errors->writeln('Not in the file: ' . implode(', ', $file->skipped()) . '. These hold ids of this shop\'s own records (such as categories), so set them again in each shop.');
        }

        return self::SUCCESS;
    }
}
