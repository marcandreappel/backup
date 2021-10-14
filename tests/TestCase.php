<?php
declare(strict_types=1);

namespace MarcAndreAppel\Backup\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use MarcAndreAppel\Backup\BackupServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app)
    {
        return [
            BackupServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
    }
}
