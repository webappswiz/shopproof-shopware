<?php declare(strict_types=1);

namespace Shopproof\Compliance\Command;

use Shopproof\Compliance\Withdrawal\SetupCheck;
use Shopproof\Compliance\Withdrawal\SetupFacts;
use Shopproof\Compliance\Setup\SetupReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The setup check from the Shopproof page, for the command line, so an agency can check every
 * client shop from its deploy scripts. Exit code 0 when everything checked is set up, 1 when
 * something needs work, 2 when a part could not be checked.
 */
#[AsCommand(name: 'shopproof:setup:check', description: 'Show what is set up and what still needs work, like the Shopproof page')]
final class SetupCheckCommand extends Command
{
    public function __construct(
        private readonly SetupFacts $facts,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON, with "shopName" and "exitCode" (0 set up, 1 needs work, 2 not checked)');
        $this->addOption('shop', null, InputOption::VALUE_REQUIRED, 'A name for this shop in the output, for example its domain', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shop = \is_string($input->getOption('shop')) ? $input->getOption('shop') : '';
        try {
            $facts = $this->facts->collect();
        } catch (\Throwable $e) {
            $report = SetupReport::notChecked($e->getMessage());
            if ($input->getOption('json') === true) {
                $output->writeln((string) json_encode(['shopName' => $shop, 'exitCode' => $report['code'], 'error' => $e->getMessage()], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
            } else {
                $output->writeln($report['lines'], OutputInterface::OUTPUT_RAW);
            }

            return $report['code'];
        }

        $result = SetupCheck::evaluate($facts);
        $report = SetupReport::render($result, $shop, $facts['manufacturersWithoutDetailsCount']);

        if ($input->getOption('json') === true) {
            $output->writeln((string) json_encode(
                ['shopName' => $shop, 'exitCode' => $report['code'], 'manufacturersWithoutDetailsCount' => $facts['manufacturersWithoutDetailsCount'], ...$result],
                \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
            ));
        } else {
            $output->writeln($report['lines'], OutputInterface::OUTPUT_RAW);
        }

        return $report['code'];
    }
}
