<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Command;

use Koertho\ChurchToolsBundle\Api\ApiException;
use Koertho\ChurchToolsBundle\Setup\TokenSetup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'church-tools:setup-token', description: 'Explicitly retrieve and verify the configured account token into a new private file.')]
final class SetupTokenCommand extends Command
{
    public function __construct(private readonly TokenSetup $setup)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('output', null, InputOption::VALUE_REQUIRED, 'Absolute path to a NEW local secret file (never overwritten).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $destination = $input->getOption('output');
        if (!is_string($destination) || $destination === '') {
            $output->writeln('<error>An explicit --output secret file is required.</error>');

            return self::INVALID;
        }
        try {
            $this->setup->save($destination);
        } catch (ApiException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return self::FAILURE;
        }
        $output->writeln('Verified token saved with mode 0600. Configure its file reference explicitly.');

        return self::SUCCESS;
    }
}
