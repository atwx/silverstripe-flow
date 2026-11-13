<?php

namespace Atwx\Flow\Api;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;

/**
 * Entry point of the operations registered by endpoints (route "api"):
 *
 *   <METHOD> api/<path>     an operation (ApiOperation)
 *   GET api/openapi.json    OpenAPI document of the operations the key may call
 *
 * Endpoints are classes implementing ApiEndpoint, registered by name:
 *
 *   Atwx\Flow\Api\ApiGateway:
 *     endpoints:
 *       intake: Atwx\Flow\Intake\IntakeEndpoint
 */
class ApiGateway extends Controller
{
    /**
     * @var array<string, string|null> Name => class implementing ApiEndpoint (null removes it)
     */
    private static array $endpoints = [];

    private static array $url_handlers = [
        'openapi.json' => 'openapi',
        '$P1!/$P2/$P3/$P4/$P5/$P6' => 'dispatch',
    ];

    private static array $allowed_actions = [
        'openapi',
        'dispatch',
    ];

    /**
     * All registered operations.
     *
     * @return array<int, ApiOperation>
     */
    public function operations(): array
    {
        $operations = [];
        foreach ((array) static::config()->get('endpoints') as $class) {
            if (!$class) {
                continue;
            }
            $endpoint = Injector::inst()->get((string) $class);
            if ($endpoint instanceof ApiEndpoint) {
                array_push($operations, ...$endpoint->operations());
            }
        }
        return $operations;
    }

    public function dispatch(HTTPRequest $request): HTTPResponse
    {
        $segments = [];
        foreach (['P1', 'P2', 'P3', 'P4', 'P5', 'P6'] as $name) {
            $segment = (string) $request->param($name);
            if ($segment === '') {
                break;
            }
            $segments[] = rawurlencode(rawurldecode($segment));
        }
        $path = implode('/', $segments);
        $runtime = ApiRuntime::singleton();
        $method = $request->httpMethod();
        $known = false;
        foreach ($this->operations() as $operation) {
            $params = $operation->match($method, $path);
            if ($params === null) {
                $known = $known || $operation->match($operation->getMethod(), $path) !== null;
                continue;
            }
            return $runtime->run(
                $request,
                $operation->getId(),
                $operation->getScope(),
                fn (ApiClient $client) => $operation->handle($request, $params, $client),
                $operation->getStatus(),
                $operation->getMaxBodyBytes()
            );
        }
        $status = $known ? 405 : 404;
        return $runtime->run(
            $request,
            $known ? 'method-not-allowed' : 'not-found',
            null,
            fn () => throw new ApiException($status, $known ? 'Method not allowed' : 'Not found')
        );
    }

    public function openapi(HTTPRequest $request): HTTPResponse
    {
        return ApiRuntime::singleton()->run(
            $request,
            'openapi',
            null,
            fn (ApiClient $client) => OpenApi::create()->document($this->operations(), $client)
        );
    }
}
