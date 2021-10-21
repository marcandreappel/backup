<?php
declare(strict_types=1);

namespace MarcAndreAppel\Backup;

use MarcAndreAppel\Backup\Helpers\ConsoleOutput;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use MarcAndreAppel\Backup\Commands\BackupCommand;

class BackupServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('backup')
            ->hasConfigFile()
            ->hasCommand(BackupCommand::class);
    }

    public function packageRegistered()
    {
        $this->app->singleton(ConsoleOutput::class);
    }
}
