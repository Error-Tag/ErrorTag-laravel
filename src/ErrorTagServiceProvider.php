<?php

namespace ErrorTag\ErrorTag;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use ErrorTag\ErrorTag\Commands\ErrorTagCommand;

class ErrorTagServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('errortag-laravel')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_errortag_laravel_table')
            ->hasCommand(ErrorTagCommand::class);
    }
}
