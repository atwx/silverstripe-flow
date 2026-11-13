<?php

namespace Atwx\Flow\Api;

use Closure;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;

/**
 * One operation of the API: method, path below api/ (segments in braces are
 * parameters, e.g. "submissions/{reference}"), the scope a client needs, the
 * handler and what the OpenAPI document says about it.
 *
 * The handler gets (HTTPRequest $request, array $params, ApiClient $client) and
 * returns an array (answered as JSON with status()) or an HTTPResponse; it
 * throws ApiException for errors.
 */
class ApiOperation
{
    private string $summary = '';

    private string $description = '';

    private int $status = 200;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $requestSchema = null;

    /**
     * @var array<int, string>
     */
    private array $requestTypes = ['application/json'];

    /**
     * @var array<string, mixed>|null
     */
    private ?array $responseSchema = null;

    private ?int $maxBodyBytes = null;

    /**
     * @param Closure(HTTPRequest, array<string, string>, ApiClient): (array<mixed>|HTTPResponse) $handler
     */
    public function __construct(
        private string $id,
        private string $method,
        private string $path,
        private string $scope,
        private Closure $handler
    ) {
        $this->method = strtoupper($method);
        $this->path = trim($path, '/');
    }

    /**
     * @param Closure(HTTPRequest, array<string, string>, ApiClient): (array<mixed>|HTTPResponse) $handler
     */
    public static function create(string $id, string $method, string $path, string $scope, Closure $handler): self
    {
        return new self($id, $method, $path, $scope, $handler);
    }

    public function summary(string $summary, string $description = ''): static
    {
        $this->summary = $summary;
        $this->description = $description;
        return $this;
    }

    /**
     * Status of a successful answer (e.g. 201).
     */
    public function status(int $status): static
    {
        $this->status = $status;
        return $this;
    }

    /**
     * JSON schema of the request body and its media types.
     *
     * @param array<string, mixed> $schema
     * @param array<int, string> $types
     */
    public function requestSchema(array $schema, array $types = ['application/json']): static
    {
        $this->requestSchema = $schema;
        $this->requestTypes = $types;
        return $this;
    }

    /**
     * @param array<string, mixed> $schema
     */
    public function responseSchema(array $schema): static
    {
        $this->responseSchema = $schema;
        return $this;
    }

    /**
     * Largest request body; null = ApiGateway.max_body_bytes.
     */
    public function maxBodyBytes(?int $bytes): static
    {
        $this->maxBodyBytes = $bytes;
        return $this;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRequestSchema(): ?array
    {
        return $this->requestSchema;
    }

    /**
     * @return array<int, string>
     */
    public function getRequestTypes(): array
    {
        return $this->requestTypes;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResponseSchema(): ?array
    {
        return $this->responseSchema;
    }

    public function getMaxBodyBytes(): ?int
    {
        return $this->maxBodyBytes;
    }

    /**
     * Path parameters when method and path match, otherwise null.
     *
     * @return array<string, string>|null
     */
    public function match(string $method, string $path): ?array
    {
        if (strtoupper($method) !== $this->method) {
            return null;
        }
        $mine = explode('/', $this->path);
        $given = explode('/', trim($path, '/'));
        if (count($mine) !== count($given)) {
            return null;
        }
        $params = [];
        foreach ($mine as $i => $segment) {
            if (preg_match('/^\{(\w+)\}$/', $segment, $name)) {
                if ($given[$i] === '') {
                    return null;
                }
                $params[$name[1]] = rawurldecode($given[$i]);
            } elseif ($segment !== $given[$i]) {
                return null;
            }
        }
        return $params;
    }

    /**
     * @param array<string, string> $params
     * @return array<mixed>|HTTPResponse
     */
    public function handle(HTTPRequest $request, array $params, ApiClient $client): array|HTTPResponse
    {
        return ($this->handler)($request, $params, $client);
    }
}
