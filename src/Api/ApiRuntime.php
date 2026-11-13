<?php

namespace Atwx\Flow\Api;

use Closure;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\FieldType\DBDatetime;
use Throwable;

/**
 * What every API request goes through: key check (ApiClient, or a token from
 * the environment variables in ApiController.token_env with all scopes), scope,
 * rate limit, body size, uniform JSON errors and the request log (ApiLog).
 */
class ApiRuntime
{
    use Injectable;
    use Configurable;

    /**
     * Largest request body in bytes (operations may set their own).
     */
    private static int $max_body_bytes = 25 * 1024 * 1024;

    /**
     * Runs $action for an authorised client; $action gets the client and
     * returns an array (JSON with $status) or a response.
     *
     * @param Closure(ApiClient): (array<mixed>|HTTPResponse) $action
     */
    public function run(
        HTTPRequest $request,
        string $operation,
        ?string $scope,
        Closure $action,
        int $status = 200,
        ?int $maxBodyBytes = null
    ): HTTPResponse {
        $start = hrtime(true);
        $client = $this->authenticate($request);
        try {
            $response = $this->dispatch($request, $client, $scope, $action, $status, $maxBodyBytes);
        } catch (ApiException $e) {
            $response = $this->error($e->status(), $e->getMessage(), $e->fields());
        } catch (Throwable $e) {
            Injector::inst()->get(LoggerInterface::class)->error(
                'Flow API: ' . $operation . ' failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
            $response = $this->error(500, 'Internal error');
        }
        if ($response->getStatusCode() === 401) {
            Injector::inst()->get(LoggerInterface::class)->warning(
                'Flow API: request without valid key from ' . $request->getIP()
            );
        }
        $this->log($request, $client, $operation, $response->getStatusCode(), $start);
        return $response;
    }

    /**
     * @param Closure(ApiClient): (array<mixed>|HTTPResponse) $action
     */
    private function dispatch(
        HTTPRequest $request,
        ?ApiClient $client,
        ?string $scope,
        Closure $action,
        int $status,
        ?int $maxBodyBytes
    ): HTTPResponse {
        if (!$client) {
            throw $this->hasCredentials()
                ? new ApiException(401, 'Invalid key')
                : new ApiException(404, 'Not found');
        }
        if ($scope !== null && !$client->hasScope($scope)) {
            throw new ApiException(403, 'Not allowed for this key');
        }
        $limit = $client->rateLimit();
        if ($limit > 0 && ApiLog::recentCount($this->clientName($client), 60) >= $limit) {
            $response = $this->error(429, 'Too many requests');
            $response->addHeader('Retry-After', '60');
            return $response;
        }
        $max = $maxBodyBytes ?? (int) static::config()->get('max_body_bytes');
        $length = max((int) $request->getHeader('Content-Length'), strlen((string) $request->getBody()));
        if ($max > 0 && $length > $max) {
            throw new ApiException(413, 'Request too large');
        }
        $result = $action($client);
        return $result instanceof HTTPResponse ? $result : $this->json($result, $status);
    }

    /**
     * The client of the key in "Authorization: Bearer <key>" or
     * "X-Api-Token: <key>", or null.
     */
    public function authenticate(HTTPRequest $request): ?ApiClient
    {
        $given = trim((string) $request->getHeader('X-Api-Token'));
        $authorization = (string) $request->getHeader('Authorization');
        if ($given === '' && str_starts_with($authorization, 'Bearer ')) {
            $given = trim(substr($authorization, 7));
        }
        if ($given === '') {
            return null;
        }
        $client = ApiClient::byKey($given);
        if ($client) {
            return $client;
        }
        foreach ($this->envTokens() as $env => $token) {
            if (hash_equals($token, $given)) {
                return ApiClient::create(['Title' => 'env:' . $env, 'Scopes' => '*', 'RateLimit' => -1]);
            }
        }
        return null;
    }

    /**
     * Whether any key could be valid (otherwise the API is off: 404).
     */
    public function hasCredentials(): bool
    {
        return $this->envTokens() !== [] || ApiClient::get()->filter('Active', true)->exists();
    }

    /**
     * @return array<string, string> Variable name => token
     */
    private function envTokens(): array
    {
        $tokens = [];
        foreach ((array) ApiController::config()->get('token_env') as $env) {
            $value = (string) Environment::getEnv((string) $env);
            if ($value !== '') {
                $tokens[(string) $env] = $value;
            }
        }
        return $tokens;
    }

    private function clientName(ApiClient $client): string
    {
        return $client->isInDB() ? '#' . $client->ID . ' ' . $client->Title : (string) $client->Title;
    }

    private function log(
        HTTPRequest $request,
        ?ApiClient $client,
        string $operation,
        int $status,
        int|float $start
    ): void {
        ApiLog::create([
            'ClientID' => $client ? $client->ID : 0,
            'ClientName' => $client ? $this->clientName($client) : '',
            'Operation' => mb_substr($operation, 0, 255),
            'Method' => $request->httpMethod(),
            'Status' => $status,
            'Duration' => (int) round((hrtime(true) - $start) / 1e6),
            'IP' => (string) $request->getIP(),
        ])->write();
        if ($client?->isInDB()) {
            $now = DBDatetime::now()->getTimestamp();
            if (!$client->LastUsed || strtotime((string) $client->LastUsed) < $now - 60) {
                $client->LastUsed = date('Y-m-d H:i:s', $now);
                $client->write();
            }
        }
    }

    /**
     * @param array<mixed> $data
     */
    public function json(array $data, int $status = 200): HTTPResponse
    {
        $body = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response = HTTPResponse::create($body, $status);
        $response->addHeader('Content-Type', 'application/json; charset=utf-8');
        $response->addHeader('Cache-Control', 'private, no-store');
        $response->addHeader('X-Robots-Tag', 'noindex');
        return $response;
    }

    /**
     * @param array<string, string> $fields
     */
    public function error(int $status, string $message, array $fields = []): HTTPResponse
    {
        $data = ['error' => $message];
        if ($fields !== []) {
            $data['fields'] = $fields;
        }
        return $this->json($data, $status);
    }
}
