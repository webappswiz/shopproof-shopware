<?php declare(strict_types=1);

namespace Shopproof\Compliance\Command;

use Shopproof\Compliance\Rules\RulesFile;
use Shopproof\Compliance\Rules\RulesStore;
use Shopproof\Compliance\Rules\RulesUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'shopproof:rules:refresh', description: 'Download the Shopproof rules file now')]
final class RefreshRulesCommand extends Command
{
    public function __construct(
        private readonly RulesUpdater $updater,
        private readonly RulesStore $store,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stored = $this->updater->refresh();
        $file = $this->store->get();

        if ($file === null) {
            $output->writeln('<error>No rules file yet: the download failed and there is no earlier copy.</error>');

            return self::FAILURE;
        }

        $output->writeln(\sprintf(
            '%s rules version %s, %d rules shown to merchants.',
            $stored ? 'Downloaded' : 'Download failed, kept',
            RulesFile::text($file, 'version'),
            \count(RulesFile::visible($file))
        ));

        return $stored ? self::SUCCESS : self::FAILURE;
    }
}
