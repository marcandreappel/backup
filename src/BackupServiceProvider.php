<?php
declare(strict_types=1);

namespace MarcAndreAppel\Backup;

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
            ->hasViews()
            ->hasCommand(BackupCommand::class);
    }
}
