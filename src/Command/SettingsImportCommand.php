<?php declare(strict_types=1);

namespace Shopproof\Compliance\Command;

use Doctrine\DBAL\Connection;
use Shopproof\Compliance\Setup\SettingsFile;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes a settings file from shopproof:settings:export into this shop. Every value is checked
 * against the plugin's settings first; when anything is wrong nothing is written.
 */
#[AsCommand(name: 'shopproof:settings:import', description: 'Apply a Shopproof settings file to this shop (checked first, all or nothing)')]
final class SettingsImportCommand extends Command
{
    private const PREFIX = 'ShopproofCompliance.config.';

    public function __construct(
        private readonly SystemConfigService $config,
        private readonly Connection $connection,
        private readonly string $schemaFile,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'The settings file, or - to read it from standard input');
        $this->addOption('sales-channel', null, InputOption::VALUE_REQUIRED, 'Sales channel id: write the values for that channel only (default: shop-wide)');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Check the file and show what would change, without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('file');
        $path = \is_string($path) ? $path : '';
        $json = $path === '-' ? stream_get_contents(\STDIN) : (is_readable($path) ? file_get_contents($path) : false);
        if (!\is_string($json)) {
            $output->writeln('Nothing was changed: the file could not be read: ' . $path, OutputInterface::OUTPUT_RAW);

            return self::FAILURE;
        }

        $channel = $input->getOption('sales-channel');
        $channel = \is_string($channel) && $channel !== '' ? strtolower($channel) : null;
        if ($channel !== null && (!Uuid::isValid($channel) || $this->connection->fetchOne('SELECT 1 FROM sales_channel WHERE id = :id', ['id' => Uuid::fromHexToBytes($channel)]) === false)) {
            $output->writeln('Nothing was changed: there is no sales channel with the id ' . $channel . '.', OutputInterface::OUTPUT_RAW);

            return self::FAILURE;
        }

        $parsed = (new SettingsFile((string) file_get_contents($this->schemaFile)))->parse($json);
        if ($parsed['errors'] !== []) {
            $output->writeln('Nothing was changed. Fix these in the file first:', OutputInterface::OUTPUT_RAW);
            foreach ($parsed['errors'] as $error) {
                $output->writeln('- ' . $error, OutputInterface::OUTPUT_RAW);
            }

            return self::FAILURE;
        }

        $dryRun = $input->getOption('dry-run') === true;
        $changed = 0;
        foreach ($parsed['values'] as $name => $value) {
            $current = $this->config->get(self::PREFIX . $name, $channel);
            if ($current === $value) {
                continue;
            }
            ++$changed;
            $output->writeln(\sprintf('%s %s: %s', $dryRun ? 'Would set' : 'Set', $name, self::show($value)), OutputInterface::OUTPUT_RAW);
            if (!$dryRun) {
                $this->config->set(self::PREFIX . $name, $value, $channel);
            }
        }

        $output->writeln(\sprintf(
            '%d of %d settings %s%s.',
            $changed,
            \count($parsed['values']),
            $dryRun ? 'would change' : 'changed',
            $channel === null ? ' (shop-wide)' : ' (sales channel ' . $channel . ')'
        ), OutputInterface::OUTPUT_RAW);
        if (($parsed['values']['customWordingAck'] ?? false) === true) {
            $output->writeln('Note: this file uses your own withdrawal button wording. Read the warning on the settings page in this shop; the law names the wording for each country.', OutputInterface::OUTPUT_RAW);
        }
        $output->writeln('Run bin/console shopproof:setup:check to see what still needs work. Technical help, not legal advice.', OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    private static function show(mixed $value): string
    {
        return match (true) {
            \is_bool($value) => $value ? 'on' : 'off',
            \is_array($value) => implode(', ', array_map(static fn (mixed $v): string => \is_scalar($v) ? (string) $v : '', $value)),
            \is_scalar($value) => str_replace("\n", ' / ', (string) $value),
            default => '',
        };
    }
}
