<?php

namespace ErrorTag\ErrorTag\Collectors;

use ErrorTag\ErrorTag\DataTransferObjects\ApplicationData;
use Illuminate\Foundation\Application;

class ApplicationContextCollector
{
  public function collect(): ApplicationData
  {
    return new ApplicationData(
      laravelVersion: Application::VERSION,
      phpVersion: PHP_VERSION,
      environment: config('errortag-laravel.environment', config('app.env', 'production')),
      serverName: config('errortag-laravel.server_name', gethostname()),
      appName: config('app.name'),
    );
  }
}
