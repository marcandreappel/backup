<?php
declare(strict_types=1);

namespace MarcAndreAppel\Backup;

use Illuminate\Support\Facades\Facade;

/**
 * @see \MarcAndreAppel\Backup\Backup
 */
class BackupFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'backup';
    }
}
