<?php
declare(strict_types=1);

namespace MarcAndreAppel\Backup\Helpers;

use Illuminate\Console\Command;

/**
 * @method info(string $string)
 * @method comment(string $string)
 * @method error(string $string)
 */
class ConsoleOutput
{
    protected ?Command $command = null;

    public function setCommand(Command $command)
    {
        $this->command = $command;
    }

    public function __call(string $method, array $arguments)
    {
        $consoleOutput = app(static::class);

        if (!$consoleOutput->command) {
            return;
        }

        $consoleOutput->command->$method($arguments[0]);
    }
}
