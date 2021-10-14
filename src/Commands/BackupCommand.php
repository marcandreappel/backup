<?php
declare(strict_types=1);

namespace MarcAndreAppel\Backup\Commands;

use Exception;
use MarcAndreAppel\Backup\Backup;
use Illuminate\Console\Command;

class BackupCommand extends Command
{
    public $signature = 'backup:run';

    public $description = 'Back it up now!';

    public function handle()
    {
        try {
            Backup::run();

            $this->comment('All done');
        } catch (Exception) {
            $this->error('Nope, something went wrong');
        }
    }
}
