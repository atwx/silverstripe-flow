<?php

namespace Atwx\Flow\Api;

use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;

/**
 * OpenAPI 3.1 document of the gateway operations and the read API
 * (ApiResources), limited to what the calling client may use.
 */
class OpenApi
{
    use Injectable;
    use Configurable;
    use Extensible;

    private static string $title = 'API';

    private static string $version = '1';

    /**
     * @param array<int, ApiOperation> $operations
     * @return array<string, mixed>
     */
    public function document(array $operations, ApiClient $client): array
    {
        $paths = [];
        foreach ($operations as $operation) {
            if (!$client->hasScope($operation->getScope())) {
                continue;
            }
            $paths['/api/' . $operation->getPath()][strtolower($operation->getMethod())] = $this->operation($operation);
        }
        foreach ($this->resourcePaths($client) as $path => $item) {
            $paths[$path] = $item;
        }
        ksort($paths);
        $document = [
            'openapi' => '3.1.0',
            'info' => [
                'title' => (string) static::config()->get('title'),
                'version' => (string) static::config()->get('version'),
            ],
            'servers' => [['url' => rtrim(Director::absoluteBaseURL(), '/')]],
            'security' => [['bearer' => []]],
            'components' => [
                'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']],
                'schemas' => ['Error' => [
                    'type' => 'object',
                    'required' => ['error'],
                    'properties' => [
                        'error' => ['type' => 'string'],
                        'fields' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                    ],
                ]],
            ],
            'paths' => (object) $paths,
        ];
        $this->extend('updateOpenApi', $document, $client);
        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(ApiOperation $operation): array
    {
        $item = ['operationId' => $operation->getId(), 'summary' => $operation->getSummary()];
        if ($operation->getDescription() !== '') {
            $item['description'] = $operation->getDescription();
        }
        $item['x-scope'] = $operation->getScope();
        preg_match_all('/\{(\w+)\}/', $operation->getPath(), $names);
        foreach ($names[1] as $name) {
            $item['parameters'][] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
            ];
        }
        $schema = $operation->getRequestSchema();
        if ($schema !== null) {
            $content = [];
            foreach ($operation->getRequestTypes() as $type) {
                $content[$type] = ['schema' => $schema];
            }
            $item['requestBody'] = ['required' => true, 'content' => $content];
        }
        $success = ['description' => 'OK'];
        if ($operation->getResponseSchema() !== null) {
            $success['content'] = ['application/json' => ['schema' => $operation->getResponseSchema()]];
        }
        $item['responses'] = [$operation->getStatus() => $success] + $this->errorResponses();
        return $item;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function errorResponses(): array
    {
        $error = ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]];
        $responses = [];
        $statuses = [
            401 => 'Invalid key',
            403 => 'Not allowed for this key',
            413 => 'Request too large',
            422 => 'Validation failed (fields)',
            429 => 'Too many requests',
        ];
        foreach ($statuses as $status => $description) {
            $responses[$status] = ['description' => $description, 'content' => $error];
        }
        return $responses;
    }

    /**
     * Paths of the read API (flow-api/…), scope "resources:<name>".
     *
     * @return array<string, array<string, mixed>>
     */
    private function resourcePaths(ApiClient $client): array
    {
        $paths = [];
        $resources = ApiResources::singleton();
        foreach (array_keys((array) ApiResources::config()->get('resources')) as $name) {
            $name = (string) $name;
            $definition = $resources->definition($name);
            if (!$definition || !$client->hasScope('resources:' . $name)) {
                continue;
            }
            $schema = ['type' => 'object', 'properties' => (object) array_fill_keys(
                array_keys((array) ($definition['fields'] ?? [])),
                new \stdClass()
            )];
            $responses = ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => $schema]]]];
            if (!empty($definition['get'])) {
                $key = (string) ($definition['key'] ?? 'ID');
                $paths['/flow-api/' . $name . '/{' . $key . '}']['get'] = [
                    'operationId' => 'resources:' . $name . ':get',
                    'x-scope' => 'resources:' . $name,
                    'parameters' => [
                        ['name' => $key, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => $responses + $this->errorResponses(),
                ];
            }
            if (!empty($definition['list'])) {
                $parameters = [];
                foreach (array_merge((array) ($definition['params'] ?? []), ['limit', 'offset']) as $param) {
                    $parameters[] = ['name' => (string) $param, 'in' => 'query', 'schema' => ['type' => 'string']];
                }
                $paths['/flow-api/' . $name]['get'] = [
                    'operationId' => 'resources:' . $name . ':list',
                    'x-scope' => 'resources:' . $name,
                    'parameters' => $parameters,
                    'responses' => ['200' => ['description' => 'OK']] + $this->errorResponses(),
                ];
            }
        }
        return $paths;
    }
}
