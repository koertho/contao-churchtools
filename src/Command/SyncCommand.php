<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Command;

use Koertho\ChurchToolsBundle\Sync\Synchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'church-tools:sync', description: 'Synchronize ChurchTools archives (read-only remote access).')]
final class SyncCommand extends Command
{
    public function __construct(private readonly Synchronizer $synchronizer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('archive', null, InputOption::VALUE_REQUIRED, 'Only this archive ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getOption('archive');
        if ($id !== null && (!ctype_digit($id) || (int) $id < 1 || (int) $id > 4294967295)) {
            $output->writeln('Invalid archive ID.');

            return self::INVALID;
        }
        try {
            $results = $this->synchronizer->synchronize($id === null ? null : (int) $id);
        } catch (\Throwable) {
            $output->writeln('Synchronization could not start. Check local configuration and database access.');

            return self::FAILURE;
        }
        $success = true;
        foreach ($results as $archive => $result) {
            $output->writeln(json_encode(['archive' => $archive] + $result, JSON_THROW_ON_ERROR));
            $success = $success && $result['status'] === 'success';
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }
}
