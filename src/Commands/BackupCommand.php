<?php
declare(strict_types=1);

namespace MarcAndreAppel\Backup\Commands;

use Exception;
use MarcAndreAppel\Backup\Backup;
use Illuminate\Console\Command;
use MarcAndreAppel\Backup\Exceptions\ZipCommandFailed;
use MarcAndreAppel\Backup\Helpers\ConsoleOutput;

class BackupCommand extends Command
{
    public $signature = 'backup:run';

    public $description = 'Back it up now!';

    /**
     * @throws ZipCommandFailed
     */
    public function handle()
    {
        app(ConsoleOutput::class)->setCommand($this);

        console_output()->comment('Starting backup');

        Backup::run();

        $this->comment('All done');
    }
}
