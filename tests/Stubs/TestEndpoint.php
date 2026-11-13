<?php

namespace Atwx\Flow\Tests\Stubs;

use Atwx\Flow\Api\ApiClient;
use Atwx\Flow\Api\ApiEndpoint;
use Atwx\Flow\Api\ApiException;
use Atwx\Flow\Api\ApiOperation;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\TestOnly;

/**
 * Endpoint for the gateway tests: echo and a validating create.
 */
class TestEndpoint implements ApiEndpoint, TestOnly
{
    public function operations(): array
    {
        return [
            ApiOperation::create(
                'echo',
                'GET',
                'echo/{word}',
                'test:echo',
                fn (HTTPRequest $request, array $params, ApiClient $client) => [
                    'word' => $params['word'],
                    'client' => $client->Title,
                ]
            )->summary('Echo'),
            ApiOperation::create(
                'things.create',
                'POST',
                'things',
                'test:things',
                function (HTTPRequest $request) {
                    $data = json_decode((string) $request->getBody(), true);
                    if (!is_array($data) || empty($data['name'])) {
                        throw ApiException::invalid(['name' => 'Required']);
                    }
                    return ['created' => $data['name']];
                }
            )->status(201)->maxBodyBytes(100)->requestSchema([
                'type' => 'object',
                'required' => ['name'],
                'properties' => ['name' => ['type' => 'string']],
            ]),
            ApiOperation::create(
                'broken',
                'GET',
                'broken',
                'test:echo',
                fn () => throw new \LogicException('secret detail')
            ),
        ];
    }
}
