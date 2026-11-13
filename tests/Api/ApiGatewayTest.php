<?php

namespace Atwx\Flow\Tests\Api;

use Atwx\Flow\Api\ApiClient;
use Atwx\Flow\Api\ApiController;
use Atwx\Flow\Api\ApiGateway;
use Atwx\Flow\Api\ApiLog;
use Atwx\Flow\Api\ApiResources;
use Atwx\Flow\Tests\Stubs\TestEndpoint;
use Atwx\Flow\Tests\Stubs\TestParticipant;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;

class ApiGatewayTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [TestParticipant::class];

    protected function setUp(): void
    {
        parent::setUp();
        Config::modify()->set(ApiGateway::class, 'endpoints', ['test' => TestEndpoint::class]);
        Config::modify()->set(ApiController::class, 'token_env', []);
        Injector::inst()->registerService(new NullLogger(), LoggerInterface::class);
    }

    /**
     * @param array<string, string> $headers
     */
    private function call(
        string $method,
        string $url,
        ?string $key,
        ?string $body = null,
        array $headers = []
    ): HTTPResponse {
        if ($key !== null) {
            $headers['Authorization'] = 'Bearer ' . $key;
        }
        return Director::test($url, [], [], $method, $body, $headers);
    }

    /**
     * @return array<mixed>
     */
    private function json(HTTPResponse $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }

    public function testOffWithoutAnyKeySource(): void
    {
        $this->assertSame(404, $this->call('GET', 'api/echo/hi', 'fk_x')->getStatusCode());
    }

    public function testKeysScopesAndLog(): void
    {
        [$client, $key] = ApiClient::createWithKey('Website', ['test:echo']);
        $this->assertMatchesRegularExpression('/^fk_[a-f0-9]{12}_[a-f0-9]{40}$/', $key);
        $this->assertNotSame($key, $client->KeyHash, 'only the hash is stored');
        $this->assertNull($client->newKey(), 'shown once');

        $response = $this->call('GET', 'api/echo/hello%20world', $key);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['word' => 'hello world', 'client' => 'Website'], $this->json($response));
        $this->assertSame('private, no-store', $response->getHeader('Cache-Control'));

        $this->assertSame(401, $this->call('GET', 'api/echo/x', null)->getStatusCode());
        $this->assertSame(401, $this->call('GET', 'api/echo/x', $key . 'x')->getStatusCode());
        $denied = $this->call('POST', 'api/things', $key, '{"name":"a"}');
        $this->assertSame(403, $denied->getStatusCode(), 'scope missing');
        $this->assertSame(['error' => 'Not allowed for this key'], $this->json($denied));
        $this->assertSame(404, $this->call('GET', 'api/nothing', $key)->getStatusCode());
        $this->assertSame(405, $this->call('DELETE', 'api/things', $key)->getStatusCode());

        $client->Active = false;
        $client->write();
        $this->assertSame(404, $this->call('GET', 'api/echo/x', $key)->getStatusCode(), 'inactive: no key source left');

        $logged = ApiLog::get()->sort('ID')->column('Operation');
        $this->assertSame(
            ['echo', 'echo', 'echo', 'things.create', 'not-found', 'method-not-allowed', 'echo'],
            $logged
        );
        $first = ApiLog::get()->sort('ID')->first();
        $this->assertSame(200, $first->Status);
        $this->assertSame($client->ID, $first->ClientID);
        $this->assertStringNotContainsString('hello', implode(' ', ApiLog::get()->column('Operation')), 'no params');
        $this->assertNotNull(ApiClient::get()->byID($client->ID)->LastUsed);
    }

    public function testWildcardScopesRenewAndValidationErrors(): void
    {
        [$client, $key] = ApiClient::createWithKey('friendlydesk', ['test:*']);
        $created = $this->call('POST', 'api/things', $key, '{"name":"chair"}');
        $this->assertSame(201, $created->getStatusCode());
        $this->assertSame(['created' => 'chair'], $this->json($created));

        $invalid = $this->call('POST', 'api/things', $key, '{}');
        $this->assertSame(422, $invalid->getStatusCode());
        $this->assertSame(['error' => 'Validation failed', 'fields' => ['name' => 'Required']], $this->json($invalid));

        $large = $this->call('POST', 'api/things', $key, json_encode(['name' => str_repeat('x', 200)]));
        $this->assertSame(413, $large->getStatusCode());

        $broken = $this->call('GET', 'api/broken', $key);
        $this->assertSame(500, $broken->getStatusCode());
        $this->assertStringNotContainsString('secret', (string) $broken->getBody());

        $client->renewKey()->write();
        $newKey = (string) $client->newKey();
        $this->assertNotSame($key, $newKey);
        $this->assertSame(401, $this->call('GET', 'api/echo/x', $key)->getStatusCode(), 'old key invalid');
        $this->assertSame(200, $this->call('GET', 'api/echo/x', $newKey)->getStatusCode());

        $this->assertTrue(ApiClient::scopesAllow(['*'], 'anything'));
        $this->assertFalse(ApiClient::scopesAllow(['intake:a'], 'intake:ab'));
        $this->assertTrue(ApiClient::scopesAllow(['intake:a', 'intake:b'], 'intake:b'));
    }

    public function testRateLimit(): void
    {
        [, $key] = ApiClient::createWithKey('Busy', ['test:echo'], 2);
        $this->assertSame(200, $this->call('GET', 'api/echo/1', $key)->getStatusCode());
        $this->assertSame(200, $this->call('GET', 'api/echo/2', $key)->getStatusCode());
        $limited = $this->call('GET', 'api/echo/3', $key);
        $this->assertSame(429, $limited->getStatusCode());
        $this->assertSame('60', $limited->getHeader('Retry-After'));
        DBDatetime::set_mock_now(date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp() + 61));
        try {
            $this->assertSame(200, $this->call('GET', 'api/echo/4', $key)->getStatusCode(), 'next minute');
        } finally {
            DBDatetime::clear_mock_now();
        }
    }

    public function testOpenApiShowsOnlyTheOperationsOfTheKey(): void
    {
        Config::modify()->set(ApiResources::class, 'resources', [
            'participant' => [
                'class' => TestParticipant::class,
                'get' => true,
                'list' => true,
                'params' => ['CourseID'],
                'fields' => ['name' => 'Name'],
            ],
        ]);
        [, $key] = ApiClient::createWithKey('Website', ['test:things']);
        $document = $this->json($this->call('GET', 'api/openapi.json', $key));
        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame(['/api/things'], array_keys($document['paths']));
        $post = $document['paths']['/api/things']['post'];
        $this->assertSame('things.create', $post['operationId']);
        $this->assertSame(['name'], $post['requestBody']['content']['application/json']['schema']['required']);
        $this->assertArrayHasKey('201', $post['responses']);
        $this->assertArrayHasKey('422', $post['responses']);

        [, $all] = ApiClient::createWithKey('Admin', ['*']);
        $paths = array_keys($this->json($this->call('GET', 'api/openapi.json', $all))['paths']);
        $this->assertSame(
            ['/api/broken', '/api/echo/{word}', '/api/things', '/flow-api/participant', '/flow-api/participant/{ID}'],
            $paths
        );
        $this->assertSame(401, $this->call('GET', 'api/openapi.json', null)->getStatusCode());
    }

    public function testReadApiWithClientsAndEnvironmentTokens(): void
    {
        Config::modify()->set(ApiResources::class, 'resources', [
            'participant' => ['class' => TestParticipant::class, 'get' => true, 'fields' => ['name' => 'Name']],
            'other' => ['class' => TestParticipant::class, 'get' => true, 'fields' => ['name' => 'Name']],
        ]);
        $ruth = TestParticipant::create(['Name' => 'Ruth']);
        $ruth->write();
        [, $key] = ApiClient::createWithKey('Portal', ['resources:participant']);
        $this->assertSame(200, $this->call('GET', 'flow-api/participant/' . $ruth->ID, $key)->getStatusCode());
        $this->assertSame(403, $this->call('GET', 'flow-api/other/' . $ruth->ID, $key)->getStatusCode());

        Environment::setEnv('FLOW_TEST_TOKEN', 'env-secret');
        Config::modify()->set(ApiController::class, 'token_env', ['FLOW_TEST_TOKEN']);
        $this->assertSame(200, $this->call('GET', 'flow-api/other/' . $ruth->ID, 'env-secret')->getStatusCode());
        $echo = $this->call('GET', 'api/echo/x', 'env-secret');
        $this->assertSame(200, $echo->getStatusCode(), 'env tokens: all scopes');
        $this->assertSame(
            ['resources:participant:get', 'resources:other:get', 'resources:other:get', 'echo'],
            ApiLog::get()->sort('ID')->column('Operation')
        );
        $this->assertSame('env:FLOW_TEST_TOKEN', ApiLog::get()->sort('ID')->last()->ClientName);
    }

    public function testRetentionDeletesOldLogEntries(): void
    {
        Config::modify()->set(ApiLog::class, 'retention_days', 30);
        DBDatetime::set_mock_now('2026-01-01 10:00:00');
        ApiLog::create(['Operation' => 'old'])->write();
        DBDatetime::set_mock_now('2026-02-15 10:00:00');
        ApiLog::create(['Operation' => 'new'])->write();
        try {
            $this->assertSame(1, ApiLog::prune());
        } finally {
            DBDatetime::clear_mock_now();
        }
        $this->assertSame(['new'], ApiLog::get()->column('Operation'));
    }
}
