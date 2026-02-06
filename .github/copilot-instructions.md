# Copilot instructions for ErrorTag-laravel

## What is ErrorTag?

**ErrorTag** is a Laravel-first error monitoring SaaS platform with two components:

1. **ErrorTag Laravel Package** (this repo) — client SDK installed in user Laravel apps
2. **ErrorTag Dashboard** — SaaS web platform (separate repo: `errortag-dashboard`) for viewing/managing errors

This package automatically captures exceptions, provides rich context (stack traces, request data, user info), and sends structured error data to the ErrorTag API.

## Core architecture (SDK package)

- **Entry point**: `src/ErrorTag.php` — main SDK class for error capture and context enrichment
- **Service provider**: `src/ErrorTagServiceProvider.php` — registers exception handler, config, and API client
- **Package tooling**: Uses `spatie/laravel-package-tools` for config/migrations/views/commands
- **Namespace**: `ErrorTag\ErrorTag\` (PSR-4 from `composer.json`)

## What this package must do

### 1. Automatic error capture

- Hook into Laravel's exception handler to capture unhandled exceptions
- Capture HTTP errors (500, 404, 403), console failures, queue job failures, scheduled task failures
- Support manual error reporting via `ErrorTag::captureException($exception)`

### 2. Context enrichment

Each error payload sent to ErrorTag API includes:

- **Error core**: exception message, type, stack trace, file, line number
- **Request context**: URL, method, headers (sanitized), body (optional), route name, controller
- **App context**: Laravel version, PHP version, environment (prod/staging), server name
- **User context**: authenticated user ID, email, role (if available)
- **Custom metadata**: developers can attach via `ErrorTag::context(['order_id' => 123])`

### 3. API communication

- Send errors to `POST /api/errors` on ErrorTag dashboard
- Include project API key from config (`ERRORTAG_KEY`)
- Use queued jobs for async sending (avoid blocking app performance)
- Respect rate limits and retry failed sends

### 4. Configuration

`config/errortag-laravel.php` must support:

- `api_key` — project key from dashboard
- `environment` — prod/staging/dev (controls filtering)
- `enabled` — global on/off switch
- `ignored_exceptions` — array of exception classes to skip
- `sanitize_headers` — redact Authorization, Cookie, etc.
- `capture_request_body` — boolean (default false for privacy)
- `sample_rate` — percentage of errors to send (e.g., 0.1 = 10%)

## Key directories and files

- **Config**: `config/errortag-laravel.php` (published via `--tag="errortag-laravel-config"`)
- **Migrations**: `database/migrations/create_errortag_laravel_table.php.stub` (if local storage needed)
- **Facade**: `src/Facades/ErrorTag.php` — provides `ErrorTag::` static API
- **Commands**: `src/Commands/ErrorTagCommand.php` (e.g., test connection, view stats)
- **Tests**: `tests/` use Pest + Orchestra Testbench; `tests/TestCase.php` boots provider
- **Arch test**: `tests/ArchTest.php` forbids `dd`, `dump`, `ray` in production code

## Developer workflows

### Installation

```bash
composer require error-tag/errortag-laravel
php artisan vendor:publish --tag="errortag-laravel-config"
```

Add to `.env`:

```env
ERRORTAG_KEY=project_xxxxx
ERRORTAG_ENV=production
```

### Testing

- `composer test` — run Pest tests
- `composer test-coverage` — coverage report
- `composer format` — Laravel Pint formatting
- `composer analyse` — PHPStan static analysis

### Example usage

```php
// Manual error reporting
ErrorTag::captureException($e);

// Add custom context
ErrorTag::context(['order_id' => $order->id]);

// In exception handler (auto-registered by service provider)
// ErrorTag will automatically capture unhandled exceptions
```

## Conventions to follow

- **Service provider pattern**: Register exception handler, HTTP client, and queue jobs in `ErrorTagServiceProvider::boot()`
- **Configuration-driven**: Never hardcode API endpoints or keys; use `config('errortag-laravel.*')`
- **Queue by default**: Send errors asynchronously via `dispatch(new SendErrorJob($errorData))`
- **Privacy-first**: Sanitize sensitive headers, redact passwords, respect `capture_request_body` config
- **Error grouping**: Include a `fingerprint` (hash of exception class + file + line) for dashboard deduplication
- **Testing with Testbench**: Use Orchestra Testbench to simulate Laravel app; mock HTTP client for API calls
- **Pest conventions**: Write tests as `it('captures exceptions automatically', ...)` in `tests/`
- **No debug functions**: Arch test enforces no `dd`, `dump`, `ray` in src/

## Integration with ErrorTag Dashboard

The dashboard (separate Laravel app) provides:

- Project management and API key generation
- Error overview dashboard with metrics (total errors, new errors, trends)
- Error detail view (stack traces, request context, user impact, timeline)
- Team collaboration (comments, assignments, status changes)
- Alerts (email, Slack) for critical errors
- Intelligent error grouping by fingerprint

This SDK is the **client** that feeds data into that system.
