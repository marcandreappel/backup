<?php
declare(strict_types=1);

namespace MarcAndreAppel\Backup\Exceptions;

use Exception;

class CannotCreateDbDumper extends Exception
{
    public static function unsupportedDriver(string $driver): self
    {
        return new static("Cannot create a dumper for db driver `{$driver}`. Use `mysql`, `pgsql`, `mongodb` or `sqlite`.");
    }
}
