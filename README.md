# NestAPI PHP Client

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

A small, fully dynamic PHP client for the [NestAPI](https://nestapi.ir/api-usage) web services.

The library contains no service names: any method you call becomes a route. New services work the moment they go live, and removed ones need no code change.

## Features

- **Dynamic routing:** `$api->ipInfo()` calls `ip-info` with no per-service classes or DTOs
- **Named or array parameters**, with validation left to the server
- **Immutable version switching** through `version()`
- **Read-only responses** with dot-path access and `ArrayAccess`
- **Typed exceptions** mapped from the API's `error.code`
- **PSR-18 and PSR-17 support:** works out of the box with Guzzle, or with your own client

## Requirements

- PHP 8.1 or higher
- Composer

## Installation

```bash
composer require nestapi/nestapi
```

## Quick start

```php
<?php

require 'vendor/autoload.php';

use NestApi\NestApi;

$api = new NestApi();

$result = $api->bmi(age: 30, weight: 70, height: 175);

echo $result['bmi'];             // 22.86
echo $result->get('input.age');  // 30
```

## Routing

The route is built from the method name. Every uppercase letter except the first starts a new `-` segment, and the result is lowercase.

| Method | Route |
|---|---|
| `chatAi`, `ChatAi` | `chat-ai` |
| `chatAI` | `chat-a-i` |
| `ipInfo` | `ip-info` |
| `searchShazam` | `search-shazam` |

Pass parameters as named arguments or as a single associative array. Positional arguments throw `InvalidArgumentException`.

```php
$api->bmi(age: 30, weight: 70, height: 175);
$api->bmi(['age' => 30, 'weight' => 70, 'height' => 175]);

$api->bmi(30, 70); // InvalidArgumentException
```

### Explicit calls

```php
// GET with the exact route name, no conversion
$api->call('ip-info', ['ip' => '8.8.8.8']);

// Any other HTTP method; parameters still go in the query string
$api->request('POST', 'some-service', ['a' => 1]);
```

> [!NOTE]
> Services named `call`, `request` or `version` collide with the methods above, in any letter case, because PHP method names are case-insensitive. Reach them through `call()`, for example `$api->call('version')`.

### Parameter values

| PHP value | Sent as | Notes |
|---|---|---|
| `true` / `false` | `x=true` / `x=false` | The server rejects `1` and `0` |
| `null` | Not sent | |
| Array | `x=1&x=2` | Repeated keys. Not yet verified against the live API, as no documented service takes an array input |

## Versions

The default version is `1`, and the `apis-` prefix is added for you. `version()` returns a copy and leaves the original instance unchanged. A version below `1` throws `InvalidArgumentException`.

```php
$api = new NestApi(version: 2);

$v3 = $api->version(3);
```

There is no automatic "latest version" mode. The `source.version` field in responses is unrelated to the version in the URL.

## Responses

A successful call returns a read-only `NestApi\Http\Response`.

```php
$result = $api->bmi(age: 30, weight: 70, height: 175);

$result->data;                  // Array, string or null
$result->meta;                  // Optional, service-defined
$result->source;                // ['name' => 'NestAPI', ...]

$result->get('input.age');      // 30
$result->get('input.nope', 0);  // 0
$result['bmi'];                 // 22.86

$result['bmi'] = 1;             // LogicException
```

If `data` is not an array, `get()` returns the default value.

## Error handling

Branch on `errorCode`, not on the message, because message text can change.

| Exception | Thrown for |
|---|---|
| `ServiceNotFoundException` | `NOT_FOUND`: unknown service or API version |
| `ValidationException` | `PARAM_NOT_FOUND`, `VALIDATION_ERROR` |
| `RateLimitException` | `RATE_LIMITED`, `RATE_LIMIT_EXCEEDED` |
| `ServerException` | Any other code with HTTP status 500 or above |
| `ApiException` | Base class of the above, and any other code such as `INVALID_IP` |
| `TransportException` | Network failure, non-JSON body, or a body without `success` |

`TransportException` is separate from `ApiException`, so catch it as well if you want to handle every failure.

```php
use NestApi\Exception\ApiException;
use NestApi\Exception\TransportException;
use NestApi\Exception\ValidationException;

try {
    $api->bmi(age: 500, weight: 70, height: 175);
} catch (ValidationException $e) {
    $e->errorCode; // 'VALIDATION_ERROR'
    $e->status;    // 422
    $e->details;   // ['query.age' => 'Must be less than or equal to 120']
} catch (ApiException $e) {
    // Any other error reported by the API
} catch (TransportException $e) {
    // No usable response arrived
}
```

## Configuration

`new NestApi()` works without any setup. Every option can be set through the constructor:

```php
$api = new NestApi(
    version: 1,
    baseUrl: 'https://open.nestapi.ir',
    timeout: 30,                     // Seconds, default client only
    client: $psr18Client,            // Psr\Http\Client\ClientInterface
    requestFactory: $psr17Factory,   // Psr\Http\Message\RequestFactoryInterface
);
```

## Troubleshooting

### `cURL error 60`

PHP cannot find a CA certificate bundle. SSL verification is always on in the default client and cannot be turned off, so point PHP at a bundle in `php.ini`:

```ini
curl.cainfo = "/path/to/cacert.pem"
```

You can download a bundle from [curl.se/docs/caextract.html](https://curl.se/docs/caextract.html).

## Testing

```bash
composer install
composer test
```

## License

Released under the [MIT License](LICENSE).
