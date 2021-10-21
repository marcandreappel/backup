<?php
declare(strict_types=1);

namespace MarcAndreAppel\Backup\Commands;

use Illuminate\Console\Command;
use MarcAndreAppel\Backup\Backup;
use MarcAndreAppel\Backup\Exceptions\ZipCommandFailed;
use MarcAndreAppel\Backup\Helpers\ConsoleOutput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BackupCommand extends Command
{
    public $signature = 'backup:run';
    public $description = 'Runs the backup process';

    public function run(InputInterface $input, OutputInterface $output): int
    {
        app(ConsoleOutput::class)->setCommand($this);

        return parent::run($input, $output);
    }

    /**
     * @throws ZipCommandFailed
     */
    public function handle()
    {
        app(ConsoleOutput::class)->setCommand($this);

        console_output()->comment('Starting backup');

        Backup::run();

        console_output()->comment('All done');
    }
}
