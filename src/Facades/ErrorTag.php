<?php

namespace ErrorTag\ErrorTag\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \ErrorTag\ErrorTag\ErrorTag
 */
class ErrorTag extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \ErrorTag\ErrorTag\ErrorTag::class;
    }
}
