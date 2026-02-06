<?php

namespace ErrorTag\ErrorTag\Commands;

use Illuminate\Console\Command;

class ErrorTagCommand extends Command
{
    public $signature = 'errortag-laravel';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
