<?php

declare(strict_types=1);

namespace NestApi\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as HttpResponse;
use InvalidArgumentException;
use LogicException;
use NestApi\Exception\ApiException;
use NestApi\Exception\RateLimitException;
use NestApi\Exception\ServerException;
use NestApi\Exception\ServiceNotFoundException;
use NestApi\Exception\TransportException;
use NestApi\Exception\ValidationException;
use NestApi\Http\Response;
use NestApi\NestApi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionProperty;
use Throwable;

final class FakeClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    public function __construct(private ResponseInterface|Throwable $result)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ($this->result instanceof Throwable) {
            throw $this->result;
        }

        return $this->result;
    }
}

final class NestApiTest extends TestCase
{
    private const OK = '{"success":true,"data":{"bmi":22.86,"input":{"age":30,"note":null},"items":[{"id":7}]},"meta":{"page":2},"source":{"name":"NestAPI","version":"1.0.1"}}';

    private FakeClient $client;

    private function api(string $body = self::OK, int $status = 200, int $version = 1): NestApi
    {
        $this->client = new FakeClient(new HttpResponse($status, [], $body));

        return new NestApi(version: $version, client: $this->client);
    }

    private function lastUrl(): string
    {
        return (string) end($this->client->requests)->getUri();
    }

    private function queryOf(callable $send): string
    {
        $send($this->api());

        return end($this->client->requests)->getUri()->getQuery();
    }

    // Routing

    /** @return array<string, array{string, string}> */
    public static function routes(): array
    {
        return [
            'lower camel' => ['chatAi', 'chat-ai'],
            'upper camel' => ['ChatAi', 'chat-ai'],
            'acronym' => ['chatAI', 'chat-a-i'],
            'two words' => ['ipInfo', 'ip-info'],
            'search' => ['searchShazam', 'search-shazam'],
            'single word' => ['bmi', 'bmi'],
            'first letter only' => ['Bmi', 'bmi'],
            'digits' => ['num2words', 'num2words'],
            'digits then upper' => ['soundcloudDownloadV2', 'soundcloud-download-v2'],
        ];
    }

    #[DataProvider('routes')]
    public function testMethodNameBecomesRoute(string $method, string $route): void
    {
        $this->api()->$method();

        self::assertSame("https://open.nestapi.ir/apis-1/{$route}", $this->lastUrl());
        self::assertSame('GET', $this->client->requests[0]->getMethod());
    }

    public function testPrivateHelpersDoNotShadowServices(): void
    {
        $this->api()->buildUrl(a: 1);
        self::assertSame('https://open.nestapi.ir/apis-1/build-url?a=1', $this->lastUrl());

        $this->api()->parse();
        self::assertSame('https://open.nestapi.ir/apis-1/parse', $this->lastUrl());
    }

    // Parameters

    public function testNamedArguments(): void
    {
        $this->api()->bmi(age: 30, weight: 70, height: 175);

        self::assertSame('https://open.nestapi.ir/apis-1/bmi?age=30&weight=70&height=175', $this->lastUrl());
    }

    public function testArrayArgument(): void
    {
        $this->api()->bmi(['age' => 30, 'weight' => 70, 'height' => 175]);

        self::assertSame('https://open.nestapi.ir/apis-1/bmi?age=30&weight=70&height=175', $this->lastUrl());
    }

    public function testNamedArgumentsMayMatchInternalNames(): void
    {
        $query = $this->queryOf(fn (NestApi $api) => $api->foo(name: 'a', arguments: 'b', version: 3, key: 'c'));

        self::assertSame('name=a&arguments=b&version=3&key=c', $query);
    }

    /** @return array<string, array{list<mixed>}> */
    public static function unnamedArguments(): array
    {
        return [
            'scalars' => [[30, 70]],
            'one scalar' => [[30]],
            'null' => [[null]],
            'list array' => [[[30, 70]]],
            'two arrays' => [[['age' => 30], ['weight' => 70]]],
            'array then scalar' => [[['age' => 30], 70]],
        ];
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('unnamedArguments')]
    public function testUnnamedArgumentsAreRejected(array $arguments): void
    {
        $api = $this->api();

        try {
            $api->bmi(...$arguments);
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            self::assertSame([], $this->client->requests);
        }
    }

    public function testMixingArrayAndNamedArgumentsIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->api()->bmi(['age' => 30], weight: 70);
    }

    public function testBooleansAreSentAsWords(): void
    {
        $query = $this->queryOf(fn (NestApi $api) => $api->foo(on: true, off: false));

        self::assertSame('on=true&off=false', $query);
    }

    public function testListsAreSentAsRepeatedKeys(): void
    {
        $query = $this->queryOf(fn (NestApi $api) => $api->foo(ids: [1, 2], flags: [true, false]));

        self::assertSame('ids=1&ids=2&flags=true&flags=false', $query);
    }

    public function testValuesAreEncodedAndNullsDropped(): void
    {
        $query = $this->queryOf(fn (NestApi $api) => $api->foo(q: 'a b&c=[0]=+', fa: 'سلام', none: null, zero: 0, float: 1.5));

        self::assertSame('q=a%20b%26c%3D%5B0%5D%3D%2B&fa=' . rawurlencode('سلام') . '&zero=0&float=1.5', $query);
    }

    // Public methods

    public function testCallUsesExactRoute(): void
    {
        $this->api()->call('MobileSearch', ['q' => 'x']);
        self::assertSame('https://open.nestapi.ir/apis-1/MobileSearch?q=x', $this->lastUrl());

        $this->api()->call('version');
        self::assertSame('https://open.nestapi.ir/apis-1/version', $this->lastUrl());
    }

    public function testServiceNameCannotInjectIntoUrl(): void
    {
        $this->api()->call('a b?x=1#y', ['q' => 1]);

        self::assertSame('https://open.nestapi.ir/apis-1/a%20b%3Fx%3D1%23y?q=1', $this->lastUrl());
    }

    public function testRequestSendsMethodAndKeepsParamsInQuery(): void
    {
        $this->api()->request('post', 'upload-file', ['a' => 1]);

        $request = $this->client->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('a=1', $request->getUri()->getQuery());
        self::assertSame('', (string) $request->getBody());
    }

    public function testCallAndRequestRejectListParams(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->api()->call('bmi', [30, 70]);
    }

    // Version

    public function testDefaultVersionIsOne(): void
    {
        $this->api()->bmi();

        self::assertStringContainsString('/apis-1/bmi', $this->lastUrl());
    }

    public function testConstructorVersion(): void
    {
        $this->api(version: 2)->bmi();

        self::assertStringContainsString('/apis-2/bmi', $this->lastUrl());
    }

    public function testVersionReturnsCopyAndLeavesOriginalUnchanged(): void
    {
        $api = $this->api();
        $copy = $api->version(3);

        self::assertNotSame($api, $copy);

        $copy->bmi();
        self::assertStringContainsString('/apis-3/bmi', $this->lastUrl());

        $api->bmi();
        self::assertStringContainsString('/apis-1/bmi', $this->lastUrl());
    }

    /** @return array<string, array{int}> */
    public static function invalidVersions(): array
    {
        return ['zero' => [0], 'negative' => [-1]];
    }

    #[DataProvider('invalidVersions')]
    public function testInvalidVersionInConstructor(int $version): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NestApi(version: $version);
    }

    #[DataProvider('invalidVersions')]
    public function testInvalidVersionInVersionMethod(int $version): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->api()->version($version);
    }

    public function testSourceVersionDoesNotAffectUrl(): void
    {
        $api = $this->api();

        $api->bmi();
        $api->bmi();

        self::assertStringContainsString('/apis-1/bmi', $this->lastUrl());
    }

    // Configuration

    public function testBaseUrlIsConfigurable(): void
    {
        $client = new FakeClient(new HttpResponse(200, [], self::OK));

        (new NestApi(baseUrl: 'https://example.test/proxy/', client: $client))->bmi();

        self::assertSame('https://example.test/proxy/apis-1/bmi', (string) $client->requests[0]->getUri());
    }

    public function testRequestFactoryIsInjectable(): void
    {
        $factory = new class () implements RequestFactoryInterface {
            public int $calls = 0;

            public function createRequest(string $method, $uri): RequestInterface
            {
                $this->calls++;

                return (new HttpFactory())->createRequest($method, $uri);
            }
        };

        (new NestApi(client: new FakeClient(new HttpResponse(200, [], self::OK)), requestFactory: $factory))->bmi();

        self::assertSame(1, $factory->calls);
    }

    public function testDefaultClientUsesThirtySecondTimeoutAndVerifiesSsl(): void
    {
        $client = (new ReflectionProperty(NestApi::class, 'client'))->getValue(new NestApi());
        self::assertInstanceOf(Client::class, $client);

        $config = (new ReflectionProperty(Client::class, 'config'))->getValue($client);
        self::assertEquals(30, $config['timeout']);
        self::assertTrue($config['verify']);
    }

    // Response

    public function testResponseProperties(): void
    {
        $response = $this->api()->bmi();

        self::assertSame(22.86, $response->data['bmi']);
        self::assertSame(['page' => 2], $response->meta);
        self::assertSame('NestAPI', $response->source['name']);
    }

    public function testResponsePropertiesAreReadOnly(): void
    {
        $response = $this->api()->bmi();

        $this->expectException(\Error::class);

        $response->data = 'x'; // @phpstan-ignore-line
    }

    public function testMetaIsOptional(): void
    {
        self::assertNull(Response::fromArray(['success' => true, 'data' => 1, 'source' => []])->meta);
    }

    public function testGetReadsDotPaths(): void
    {
        $response = $this->api()->bmi();

        self::assertSame(30, $response->get('input.age'));
        self::assertSame(22.86, $response->get('bmi'));
        self::assertSame(7, $response->get('items.0.id'));
        self::assertSame('d', $response->get('input.missing', 'd'));
        self::assertSame('d', $response->get('bmi.deeper', 'd'));
        self::assertNull($response->get('missing'));
    }

    public function testGetReturnsExistingNullRatherThanDefault(): void
    {
        self::assertNull($this->api()->bmi()->get('input.note', 'd'));
    }

    /** @return array<string, array{string}> */
    public static function nonArrayData(): array
    {
        return ['string' => ['"hello"'], 'null' => ['null'], 'number' => ['5']];
    }

    #[DataProvider('nonArrayData')]
    public function testNonArrayData(string $json): void
    {
        $response = $this->api('{"success":true,"data":' . $json . ',"source":{}}')->foo();

        self::assertSame(json_decode($json), $response->data);
        self::assertSame('d', $response->get('a', 'd'));
        self::assertNull($response['a']);
        self::assertFalse(isset($response['a']));
    }

    public function testListData(): void
    {
        $response = $this->api('{"success":true,"data":[{"id":1}],"source":{}}')->foo();

        self::assertSame(1, $response->get('0.id'));
        self::assertSame(['id' => 1], $response[0]);
    }

    public function testArrayAccessReads(): void
    {
        $response = $this->api()->bmi();

        self::assertSame(22.86, $response['bmi']);
        self::assertTrue(isset($response['bmi']));
        self::assertFalse(isset($response['missing']));
        self::assertNull($response['missing']);
    }

    public function testArrayAccessWriteThrows(): void
    {
        $response = $this->api()->bmi();

        $this->expectException(LogicException::class);

        $response['bmi'] = 1;
    }

    public function testArrayAccessUnsetThrows(): void
    {
        $response = $this->api()->bmi();

        $this->expectException(LogicException::class);

        unset($response['bmi']);
    }

    // Errors

    /** @return array<string, array{string, int, class-string<ApiException>}> */
    public static function errorCodes(): array
    {
        return [
            'not found' => ['NOT_FOUND', 404, ServiceNotFoundException::class],
            'param not found' => ['PARAM_NOT_FOUND', 400, ValidationException::class],
            'validation' => ['VALIDATION_ERROR', 422, ValidationException::class],
            'rate limited' => ['RATE_LIMITED', 429, RateLimitException::class],
            'rate limit exceeded' => ['RATE_LIMIT_EXCEEDED', 429, RateLimitException::class],
            'known code wins over 5xx' => ['RATE_LIMITED', 503, RateLimitException::class],
            'other 500' => ['DATABASE_ERROR', 500, ServerException::class],
            'other 503' => ['SERVICE_UNAVAILABLE', 503, ServerException::class],
            'custom 5xx' => ['VOICES_FETCH_FAILED', 502, ServerException::class],
            'custom 4xx' => ['INVALID_IP', 400, ApiException::class],
            'unknown 4xx' => ['FORBIDDEN', 403, ApiException::class],
            'just below 500' => ['SOMETHING', 499, ApiException::class],
        ];
    }

    /** @param class-string<ApiException> $class */
    #[DataProvider('errorCodes')]
    public function testErrorMapping(string $code, int $status, string $class): void
    {
        $body = json_encode(['success' => false, 'error' => ['code' => $code, 'message' => 'm', 'details' => ['x']]]);

        try {
            $this->api($body, $status)->foo();
            self::fail('Expected an exception');
        } catch (ApiException $e) {
            self::assertSame($class, $e::class);
            self::assertSame($code, $e->errorCode);
            self::assertSame($status, $e->status);
            self::assertSame(['x'], $e->details);
            self::assertSame('m', $e->getMessage());
        }
    }

    public function testErrorDependsOnCodeNotMessage(): void
    {
        $this->expectException(ValidationException::class);

        $this->api('{"success":false,"error":{"code":"VALIDATION_ERROR","message":"Route not found"}}', 400)->foo();
    }

    public function testDetailsShapes(): void
    {
        $body = '{"success":false,"error":{"code":"VALIDATION_ERROR","message":"m","details":{"query.age":"too big"}}}';

        try {
            $this->api($body, 422)->bmi();
            self::fail('Expected an exception');
        } catch (ValidationException $e) {
            self::assertSame(['query.age' => 'too big'], $e->details);
        }
    }

    public function testDetailsAreOptional(): void
    {
        try {
            $this->api('{"success":false,"error":{"code":"NOT_FOUND","message":"m"}}', 404)->foo();
            self::fail('Expected an exception');
        } catch (ServiceNotFoundException $e) {
            self::assertNull($e->details);
        }
    }

    /** @return array<string, array{string, int, class-string<ApiException>}> */
    public static function failuresWithoutCode(): array
    {
        return [
            'no error' => ['{"success":false}', 400, ApiException::class],
            'error not an object' => ['{"success":false,"error":"boom"}', 400, ApiException::class],
            'no error on 5xx' => ['{"success":false}', 500, ServerException::class],
        ];
    }

    /** @param class-string<ApiException> $class */
    #[DataProvider('failuresWithoutCode')]
    public function testFailureWithoutErrorCode(string $body, int $status, string $class): void
    {
        try {
            $this->api($body, $status)->foo();
            self::fail('Expected an exception');
        } catch (ApiException $e) {
            self::assertSame($class, $e::class);
            self::assertSame('', $e->errorCode);
            self::assertSame($status, $e->status);
        }
    }

    // Transport

    /** @return array<string, array{string, int}> */
    public static function malformedBodies(): array
    {
        return [
            'html' => ['<html>502 Bad Gateway</html>', 502],
            'empty' => ['', 200],
            'truncated' => ['{"success":tr', 200],
            'no success field' => ['{"data":1}', 200],
            'empty object' => ['{}', 200],
            'json list' => ['[1,2]', 200],
            'json string' => ['"ok"', 200],
            'json null' => ['null', 200],
            'null success' => ['{"success":null}', 200],
            'non-boolean success' => ['{"success":"true","data":1}', 200],
        ];
    }

    #[DataProvider('malformedBodies')]
    public function testMalformedBodyThrowsTransportException(string $body, int $status): void
    {
        $this->expectException(TransportException::class);

        $this->api($body, $status)->foo();
    }

    public function testNetworkFailureThrowsTransportException(): void
    {
        $failure = new ConnectException('Connection refused', new Request('GET', 'https://x.test'));
        $api = new NestApi(client: new FakeClient($failure));

        try {
            $api->bmi();
            self::fail('Expected an exception');
        } catch (TransportException $e) {
            self::assertSame($failure, $e->getPrevious());
        }
    }

    public function testTransportExceptionIsNotApiException(): void
    {
        self::assertFalse(is_a(TransportException::class, ApiException::class, true));
    }
}
