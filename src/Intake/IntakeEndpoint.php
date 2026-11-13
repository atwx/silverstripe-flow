<?php

namespace Atwx\Flow\Intake;

use Atwx\Flow\Api\ApiClient;
use Atwx\Flow\Api\ApiEndpoint;
use Atwx\Flow\Api\ApiException;
use Atwx\Flow\Api\ApiOperation;
use Atwx\Flow\Api\ApiRuntime;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injectable;

/**
 * POST api/intake/<type>: a form submission, scope "intake:<type>".
 *
 * JSON: {"data": {…}, "files": {"<field>": [{"name": "report.pdf", "content": "<base64>"}]}}
 * or multipart/form-data with the part "data" (JSON) and file parts named
 * after the file fields ("report" or "report[]"). Header "Idempotency-Key"
 * makes a repeated call return the first submission (200) instead of a new one.
 *
 * Answer 201: {"reference": "KB-2026-0001", "status": "new",
 * "receipt": {"url": …, "expires": …} | null}.
 */
class IntakeEndpoint implements ApiEndpoint
{
    use Injectable;

    public function operations(): array
    {
        $operations = [];
        foreach (IntakeService::singleton()->types() as $type) {
            $operations[] = ApiOperation::create(
                'intake.' . $type->getName() . '.create',
                'POST',
                'intake/' . $type->getName(),
                'intake:' . $type->getName(),
                fn (HTTPRequest $request, array $params, ApiClient $client) => $this->create($type, $request, $client)
            )
                ->summary(
                    $type->getTitle(),
                    'Submits the form. Files inline (base64) or as multipart parts. '
                    . 'Header Idempotency-Key avoids duplicates.'
                )
                ->status(201)
                ->maxBodyBytes($type->maxBodyBytes())
                ->requestSchema($type->jsonSchema(), ['application/json', 'multipart/form-data'])
                ->responseSchema([
                    'type' => 'object',
                    'properties' => [
                        'reference' => ['type' => 'string'],
                        'status' => ['type' => 'string'],
                        'receipt' => [
                            'type' => ['object', 'null'],
                            'properties' => ['url' => ['type' => 'string'], 'expires' => ['type' => 'string']],
                        ],
                    ],
                ]);
        }
        return $operations;
    }

    /**
     * @return array<mixed>|HTTPResponse
     */
    private function create(IntakeType $type, HTTPRequest $request, ApiClient $client): array|HTTPResponse
    {
        [$data, $files] = $this->payload($request);
        $key = $request->getHeader('Idempotency-Key');
        $service = IntakeService::singleton();
        $key = $key !== null ? (string) $key : null;
        [$submission, $repeated] = $service->submit($type, $data, $files, $client, $key);
        $answer = [
            'reference' => $submission->Reference,
            'status' => $submission->Status,
            'receipt' => $service->receiptLink($submission),
        ];
        if (!$repeated) {
            return $answer;
        }
        $response = ApiRuntime::singleton()->json($answer, 200);
        $response->addHeader('Idempotent-Replayed', 'true');
        return $response;
    }

    /**
     * Data and files of a JSON or multipart request.
     *
     * @return array{0: array<mixed>, 1: array<string, array<int, array{name: string, content: string}>>}
     */
    private function payload(HTTPRequest $request): array
    {
        $contentType = strtolower((string) $request->getHeader('Content-Type'));
        if (str_starts_with($contentType, 'multipart/form-data')) {
            return $this->multipart($request);
        }
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            throw new ApiException(400, 'Body must be a JSON object');
        }
        $data = $body['data'] ?? null;
        if (!is_array($data)) {
            throw ApiException::invalid(['data' => 'Required (object)']);
        }
        $files = [];
        foreach ((array) ($body['files'] ?? []) as $field => $list) {
            if (!is_array($list) || !array_is_list($list)) {
                throw ApiException::invalid(['files.' . $field => 'Must be a list']);
            }
            $files[(string) $field] ??= [];
            foreach ($list as $i => $file) {
                $content = is_array($file) && is_string($file['content'] ?? null)
                    ? base64_decode($file['content'], true)
                    : false;
                if ($content === false || !is_string($file['name'] ?? null)) {
                    throw ApiException::invalid(['files.' . $field . '.' . $i => 'Needs name and base64 content']);
                }
                $files[(string) $field][] = ['name' => $file['name'], 'content' => $content];
            }
        }
        return [$data, $files];
    }

    /**
     * @return array{0: array<mixed>, 1: array<string, array<int, array{name: string, content: string}>>}
     */
    private function multipart(HTTPRequest $request): array
    {
        $data = json_decode((string) $request->postVar('data'), true);
        if (!is_array($data)) {
            throw ApiException::invalid(['data' => 'Required (JSON object)']);
        }
        $reader = UploadReader::singleton();
        $files = [];
        foreach ($request->postVars() as $field => $upload) {
            if ($field === 'data' || !is_array($upload) || !isset($upload['tmp_name'])) {
                continue;
            }
            foreach ($this->uploads($upload) as $i => $one) {
                $content = $reader->read($one);
                if ($content === null) {
                    throw ApiException::invalid(['files.' . $field . '.' . $i => 'Upload failed']);
                }
                $files[(string) $field][] = ['name' => (string) ($one['name'] ?? ''), 'content' => $content];
            }
        }
        return [$data, $files];
    }

    /**
     * Single uploads of a $_FILES entry ("report" or "report[]").
     *
     * @param array<string, mixed> $upload
     * @return array<int, array<string, mixed>>
     */
    private function uploads(array $upload): array
    {
        if (!is_array($upload['tmp_name'])) {
            return [$upload];
        }
        $list = [];
        foreach (array_keys($upload['tmp_name']) as $i) {
            $one = [];
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
                $one[$key] = is_array($upload[$key] ?? null) ? ($upload[$key][$i] ?? null) : null;
            }
            $list[] = $one;
        }
        return $list;
    }
}
