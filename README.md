# ErrorTag

[![Latest Version on Packagist](https://img.shields.io/packagist/v/error-tag/errortag-laravel.svg?style=flat-square)](https://packagist.org/packages/error-tag/errortag-laravel)
[![tests](https://github.com/Error-Tag/ErrorTag-laravel/actions/workflows/run-tests.yml/badge.svg)](https://github.com/Error-Tag/ErrorTag-laravel/actions/workflows/run-tests.yml)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/error-tag/errortag-laravel/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/error-tag/errortag-laravel/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/error-tag/errortag-laravel.svg?style=flat-square)](https://packagist.org/packages/error-tag/errortag-laravel)

**ErrorTag** is an error monitoring and observability platform. This package is the client SDK that captures errors from your Laravel application and sends them to the ErrorTag dashboard for analysis, alerting, and team collaboration.

## Features

- **Automatic Error Capture** - Hooks into Laravel's exception handler
- **Intelligent Error Grouping** - Groups similar errors using fingerprints
- **Privacy-First** - Sanitizes sensitive data (passwords, tokens, headers)
- **Async by Default** - Queues errors for background sending
- **Rich Context** - Captures request, user, and application data
- **Highly Configurable** - Sample rates, ignored exceptions, and more
- **Fully Tested** - Comprehensive test coverage with Pest
## Installation

Install the package via Composer:

```bash
composer require error-tag/errortag-laravel
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag="errortag-laravel-config"
```

Add your ErrorTag API key to `.env`:

```env
ERRORTAG_KEY=project_xxxxx
ERRORTAG_ENV=production
```

## Quick Start

Once installed, ErrorTag automatically captures all unhandled exceptions. Test your setup:

```bash
php artisan errortag:test --send-test-error
```

## Usage

### Automatic Capture

```php
// This exception is automatically captured
throw new Exception('Something went wrong!');
```

### Manual Reporting

```php
use ErrorTag\ErrorTag\Facades\ErrorTag;

try {
    processPayment($order);
} catch (Exception $e) {
    ErrorTag::captureException($e);
}
```

### Adding Context

```php
ErrorTag::context([
    'order_id' => $order->id,
    'payment_provider' => 'stripe',
]);
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [William Asaba](https://github.com/Error-Tag)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
