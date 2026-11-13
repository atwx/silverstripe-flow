<?php

namespace Atwx\Flow\Api;

use RuntimeException;

/**
 * Thrown by an endpoint; the gateway answers with a uniform JSON error:
 * {"error": "<message>", "fields": {"<field>": "<message>"}}.
 */
class ApiException extends RuntimeException
{
    /**
     * @param array<string, string> $fields Field errors (422)
     */
    public function __construct(int $status, string $message, private array $fields = [])
    {
        parent::__construct($message, $status);
    }

    /**
     * @param array<string, string> $fields
     */
    public static function invalid(array $fields, string $message = 'Validation failed'): self
    {
        return new self(422, $message, $fields);
    }

    public function status(): int
    {
        return $this->getCode();
    }

    /**
     * @return array<string, string>
     */
    public function fields(): array
    {
        return $this->fields;
    }
}
