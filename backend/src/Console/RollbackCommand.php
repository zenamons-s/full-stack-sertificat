<?php

declare(strict_types=1);

namespace App\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RollbackCommand extends Command
{
    public function __construct()
    {
        parent::__construct('migrate:rollback');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $application = $this->getApplication();
        if ($application === null) {
            $output->writeln('<error>Console application is not available.</error>');
            return Command::FAILURE;
        }

        return $application->find('migrate')->run(new ArrayInput([
            'version' => 'prev',
            '--no-interaction' => true,
        ]), $output);
    }
}
