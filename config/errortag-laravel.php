<?php

// config for ErrorTag/ErrorTag
return [

    /*
    |--------------------------------------------------------------------------
    | ErrorTag API Key
    |--------------------------------------------------------------------------
    |
    | Your unique project API key from the ErrorTag dashboard.
    | Get this from: https://errortag.com/projects/{your-project}/settings
    |
    */

    'api_key' => env('ERRORTAG_KEY'),

    /*
    |--------------------------------------------------------------------------
    | ErrorTag API Endpoint
    |--------------------------------------------------------------------------
    |
    | The ErrorTag API endpoint where errors will be sent.
    | You typically don't need to change this unless using self-hosted ErrorTag.
    |
    */

    'api_endpoint' => env('ERRORTAG_ENDPOINT', 'https://api.errortag.com/api/errors'),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | The environment name for this application (production, staging, local).
    | This helps you filter errors by environment in the ErrorTag dashboard.
    |
    */

    'environment' => env('ERRORTAG_ENV', env('APP_ENV', 'production')),

    /*
    |--------------------------------------------------------------------------
    | Enable Error Tracking
    |--------------------------------------------------------------------------
    |
    | Master switch to enable or disable ErrorTag error tracking.
    | Set to false in local development or during maintenance windows.
    |
    */

    'enabled' => env('ERRORTAG_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Ignored Exceptions
    |--------------------------------------------------------------------------
    |
    | Array of exception class names that should not be sent to ErrorTag.
    | Useful for ignoring common exceptions like validation errors or 404s.
    |
    */

    'ignored_exceptions' => [
        // Illuminate\Validation\ValidationException::class,
        // Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sample Rate
    |--------------------------------------------------------------------------
    |
    | The percentage of errors to capture (0.0 to 1.0).
    | Set to 1.0 to capture all errors, or 0.1 to capture 10% of errors.
    | Useful for high-traffic applications to reduce costs.
    |
    */

    'sample_rate' => env('ERRORTAG_SAMPLE_RATE', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Capture Request Body
    |--------------------------------------------------------------------------
    |
    | Whether to include the request body in error reports.
    | WARNING: May contain sensitive data (passwords, credit cards, etc.)
    | Only enable if you have sanitization rules in place.
    |
    */

    'capture_request_body' => env('ERRORTAG_CAPTURE_BODY', false),

    /*
    |--------------------------------------------------------------------------
    | Sanitize Headers
    |--------------------------------------------------------------------------
    |
    | Array of header names to redact before sending to ErrorTag.
    | These headers will be replaced with [REDACTED] in error reports.
    |
    */

    'sanitize_headers' => [
        'Authorization',
        'Cookie',
        'Set-Cookie',
        'X-CSRF-Token',
        'X-XSRF-Token',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sanitize Request Fields
    |--------------------------------------------------------------------------
    |
    | Array of request field names to redact from request body.
    | Use this to prevent sensitive data from being sent to ErrorTag.
    |
    */

    'sanitize_fields' => [
        'password',
        'password_confirmation',
        'token',
        'secret',
        'api_key',
        'credit_card',
        'card_number',
        'cvv',
        'ssn',
    ],

    /*
    |--------------------------------------------------------------------------
    | Capture User Information
    |--------------------------------------------------------------------------
    |
    | Whether to include authenticated user information in error reports.
    | Includes user ID, email, and other basic user attributes.
    |
    */

    'capture_user' => env('ERRORTAG_CAPTURE_USER', true),

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum time (in seconds) to wait when sending errors to ErrorTag API.
    | Errors will be queued for retry if the timeout is exceeded.
    |
    */

    'timeout' => env('ERRORTAG_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Queue Connection
    |--------------------------------------------------------------------------
    |
    | The queue connection to use for sending errors asynchronously.
    | Set to null to send errors synchronously (not recommended).
    |
    */

    'queue_connection' => env('ERRORTAG_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'sync')),

    /*
    |--------------------------------------------------------------------------
    | Queue Name
    |--------------------------------------------------------------------------
    |
    | The queue name to use for ErrorTag jobs.
    | Useful if you want to separate ErrorTag jobs from other background jobs.
    |
    */

    'queue_name' => env('ERRORTAG_QUEUE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Release Strategy
    |--------------------------------------------------------------------------
    |
    | Release/version identifier for your application.
    | Helps track which version of your code produced errors.
    |
    */

    'release' => env('ERRORTAG_RELEASE', null),

    /*
    |--------------------------------------------------------------------------
    | Server Name
    |--------------------------------------------------------------------------
    |
    | Identifier for the server/instance running this application.
    | Useful for identifying errors from specific servers in load-balanced setups.
    |
    */

    'server_name' => env('ERRORTAG_SERVER_NAME', gethostname()),

    /*
    |--------------------------------------------------------------------------
    | Capture Stack Trace Arguments
    |--------------------------------------------------------------------------
    |
    | Whether to include function arguments in stack traces.
    | WARNING: May expose sensitive data. Disable in production if concerned.
    |
    */

    'capture_stack_trace_args' => env('ERRORTAG_CAPTURE_ARGS', false),

    /*
    |--------------------------------------------------------------------------
    | Maximum Stack Trace Depth
    |--------------------------------------------------------------------------
    |
    | Maximum depth of stack traces to capture.
    | Deeper traces provide more context but increase payload size.
    |
    */

    'max_stack_trace_depth' => env('ERRORTAG_MAX_TRACE_DEPTH', 50),

];
