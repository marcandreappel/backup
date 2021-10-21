<?php

use MarcAndreAppel\Backup\Helpers\ConsoleOutput;

if (!function_exists('console_output')) {
    function console_output(): ConsoleOutput
    {
        return app(ConsoleOutput::class);
    }
}
