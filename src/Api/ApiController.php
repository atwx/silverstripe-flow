<?php

namespace Atwx\Flow\Api;

use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPStreamResponse;
use SilverStripe\ORM\DataObject;

/**
 * Read-only JSON API for other systems (e.g. a portal), without GraphQL:
 *
 *   GET flow-api/<resource>/<id>             one record (resources with get: true;
 *                                            with key: <field>, by that field)
 *   GET flow-api/<resource>?<param>=…        list (list: true; limit, offset)
 *   GET flow-api/file/<resource>/<id>/<key>  a file field of a record
 *
 * Every request needs a key: header "Authorization: Bearer <key>" or
 * "X-Api-Token: <key>", either of an ApiClient with the scope
 * "resources:<resource>" or a token from the environment variables named in
 * ApiController.token_env (all scopes). Without any key source the API answers
 * 404. Requests are logged (ApiLog) and rate limited: ApiRuntime. Resources
 * and their fields: ApiResources.
 */
class ApiController extends Controller
{
    /**
     * Names of environment variables holding valid tokens.
     *
     * @var array<int, string>
     */
    private static array $token_env = ['FLOW_API_TOKEN'];

    /**
     * Largest page of a list.
     */
    private static int $max_limit = 500;

    private static array $url_handlers = [
        'file/$Resource!/$ID!/$Key!' => 'file',
        '$Resource!/$ID' => 'resource',
    ];

    private static array $allowed_actions = [
        'file',
        'resource',
    ];

    public function resource(HTTPRequest $request): HTTPResponse
    {
        $name = (string) $request->param('Resource');
        $id = (string) $request->param('ID');
        return ApiRuntime::singleton()->run(
            $request,
            'resources:' . $name . ($id !== '' ? ':get' : ':list'),
            'resources:' . $name,
            fn () => $this->resourceData($request, $name, $id)
        );
    }

    /**
     * @return array<mixed>|HTTPResponse
     */
    private function resourceData(HTTPRequest $request, string $name, string $id): array|HTTPResponse
    {
        $resources = ApiResources::singleton();
        $definition = $resources->definition($name);
        if (!$definition) {
            return $this->error(404, 'Unknown resource');
        }
        $list = $resources->baseList($definition);
        if ($id !== '') {
            $record = empty($definition['get']) ? null : $resources->findOne($definition, $id);
            if (!$record instanceof DataObject) {
                return $this->error(404, 'Not found');
            }
            return $resources->serialize($name, $record, $this->fileBase());
        }
        if (empty($definition['list'])) {
            return $this->error(404, 'Not found');
        }
        $params = (array) ($definition['params'] ?? []);
        foreach ($request->getVars() as $key => $value) {
            if (in_array($key, ['url', 'limit', 'offset'], true)) {
                continue;
            }
            if (!in_array($key, $params, true) || !is_scalar($value)) {
                return $this->error(400, 'Unknown parameter: ' . $key);
            }
            $list = $list->filter($key, $value);
        }
        $total = $list->count();
        $limit = min(max(1, (int) ($request->getVar('limit') ?: 100)), (int) static::config()->get('max_limit'));
        $offset = max(0, (int) $request->getVar('offset'));
        $items = [];
        foreach ($list->limit($limit, $offset) as $record) {
            $items[] = $resources->serialize($name, $record, $this->fileBase());
        }
        return ['total' => $total, 'limit' => $limit, 'offset' => $offset, 'items' => $items];
    }

    public function file(HTTPRequest $request): HTTPResponse
    {
        $name = (string) $request->param('Resource');
        return ApiRuntime::singleton()->run(
            $request,
            'resources:' . $name . ':file',
            'resources:' . $name,
            fn () => $this->fileResponse($request, $name)
        );
    }

    private function fileResponse(HTTPRequest $request, string $name): HTTPResponse
    {
        $resources = ApiResources::singleton();
        $definition = $resources->definition($name);
        $record = $definition ? $resources->baseList($definition)->byID((int) $request->param('ID')) : null;
        $file = $record instanceof DataObject
            ? $resources->fileFor($name, $record, (string) $request->param('Key'))
            : null;
        $stream = $file?->getStream();
        if (!$file || !is_resource($stream)) {
            return $this->error(404, 'Not found');
        }
        $response = HTTPStreamResponse::create($stream, (int) $file->getAbsoluteSize());
        $response->addHeader('Content-Type', $file->getMimeType() ?: 'application/octet-stream');
        $filename = basename((string) $file->getFilename());
        $response->addHeader('Content-Disposition', 'attachment; filename="' . addcslashes($filename, '"\\') . '"');
        $response->addHeader('Cache-Control', 'private, no-store');
        return $response;
    }

    private function fileBase(): string
    {
        return Director::absoluteURL('flow-api/file');
    }

    private function error(int $status, string $message): HTTPResponse
    {
        return ApiRuntime::singleton()->error($status, $message);
    }
}
