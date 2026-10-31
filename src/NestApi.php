<?php

declare(strict_types=1);

namespace NestApi;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use InvalidArgumentException;
use JsonException;
use NestApi\Exception\ApiException;
use NestApi\Exception\RateLimitException;
use NestApi\Exception\ServerException;
use NestApi\Exception\ServiceNotFoundException;
use NestApi\Exception\TransportException;
use NestApi\Exception\ValidationException;
use NestApi\Http\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Dynamic NestAPI client. Any method name is a service: the name is turned into
 * a route, so services can come and go without changes here.
 *
 *     $api->ipInfo(ip: '8.8.8.8');          // GET /apis-1/ip-info?ip=8.8.8.8
 *     $api->bmi(['age' => 30]);             // GET /apis-1/bmi?age=30
 *
 * Services named `call`, `request` or `version` collide with the public methods
 * below (in any letter case, since PHP method names are case-insensitive) and
 * must be reached through call(), e.g. `$api->call('version')`.
 */
class NestApi
{
    public const DEFAULT_BASE_URL = 'https://open.nestapi.ir';

    private int $version;
    private readonly string $baseUrl;
    private readonly ClientInterface $client;
    private readonly RequestFactoryInterface $requestFactory;

    /**
     * @param float $timeout Seconds; applies to the default client only. An
     *                       injected client brings its own timeout.
     */
    public function __construct(
        int $version = 1,
        string $baseUrl = self::DEFAULT_BASE_URL,
        float $timeout = 30.0,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
    ) {
        $this->version = self::checkVersion($version);
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->client = $client ?? new Client(['timeout' => $timeout, 'verify' => true]);
        $this->requestFactory = $requestFactory ?? new HttpFactory();
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    public function __call(string $name, array $arguments): Response
    {
        $params = array_keys($arguments) === [0] && is_array($arguments[0]) ? $arguments[0] : $arguments;

        return $this->call(self::toRoute($name), $params);
    }

    /**
     * GET a service by its exact route name, without any conversion.
     *
     * @param array<string, mixed> $params
     */
    public function call(string $service, array $params = []): Response
    {
        return $this->request('GET', $service, $params);
    }

    /**
     * Send any HTTP method. Parameters still go in the query string.
     *
     * @param array<string, mixed> $params
     */
    public function request(string $method, string $service, array $params = []): Response
    {
        $request = $this->requestFactory
            ->createRequest(strtoupper($method), $this->buildUrl($service, $params))
            ->withHeader('Accept', 'application/json');

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException('Request failed: ' . $e->getMessage(), 0, $e);
        }

        return self::parse((string) $response->getBody(), $response->getStatusCode());
    }

    /**
     * Return a copy bound to another API version; this instance is unchanged.
     */
    public function version(int $version): static
    {
        $copy = clone $this;
        $copy->version = self::checkVersion($version);

        return $copy;
    }

    private static function checkVersion(int $version): int
    {
        if ($version < 1) {
            throw new InvalidArgumentException("API version must be a positive integer, got {$version}.");
        }

        return $version;
    }

    private static function toRoute(string $method): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $method));
    }

    /**
     * @param array<int|string, mixed> $params
     */
    private function buildUrl(string $service, array $params): string
    {
        $url = sprintf('%s/apis-%d/%s', $this->baseUrl, $this->version, str_replace('%2F', '/', rawurlencode($service)));
        $query = self::buildQuery($params);

        return $query === '' ? $url : "{$url}?{$query}";
    }

    /**
     * Booleans become `true`/`false`, the only form the server accepts (PHP
     * would send 1/0). Lists become repeated keys (`a=1&a=2`), and null values
     * are left out.
     *
     * @param array<int|string, mixed> $params
     */
    private static function buildQuery(array $params): string
    {
        foreach (array_keys($params) as $key) {
            if (is_int($key)) {
                throw new InvalidArgumentException('Parameters must be named: use name: value or an associative array.');
            }
        }

        array_walk_recursive($params, static function (mixed &$value): void {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
        });

        return (string) preg_replace('/%5B\d+%5D=/', '=', http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    }

    private static function parse(string $body, int $status): Response
    {
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new TransportException("Response is not valid JSON (HTTP {$status}).", 0, $e);
        }

        if (!is_array($payload) || !is_bool($payload['success'] ?? null)) {
            throw new TransportException("Response has no \"success\" field (HTTP {$status}).");
        }

        if ($payload['success']) {
            return Response::fromArray($payload);
        }

        throw self::toException($payload['error'] ?? null, $status);
    }

    private static function toException(mixed $error, int $status): ApiException
    {
        $error = is_array($error) ? $error : [];
        $code = is_string($error['code'] ?? null) ? $error['code'] : '';
        $message = is_string($error['message'] ?? null) ? $error['message'] : "API error (HTTP {$status}).";

        $class = match (true) {
            $code === 'NOT_FOUND' => ServiceNotFoundException::class,
            $code === 'PARAM_NOT_FOUND', $code === 'VALIDATION_ERROR' => ValidationException::class,
            $code === 'RATE_LIMITED', $code === 'RATE_LIMIT_EXCEEDED' => RateLimitException::class,
            $status >= 500 => ServerException::class,
            default => ApiException::class,
        };

        return new $class($message, $code, $status, $error['details'] ?? null);
    }
}
