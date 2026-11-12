<?php

namespace Atwx\Flow\Api;

use Psr\Log\LoggerInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPStreamResponse;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;

/**
 * Read-only JSON API for other systems (e.g. a portal), without GraphQL:
 *
 *   GET flow-api/<resource>/<id>             one record (resources with get: true)
 *   GET flow-api/<resource>?<param>=…        list (list: true; limit, offset)
 *   GET flow-api/file/<resource>/<id>/<key>  a file field of a record
 *
 * Every request needs the token: header "Authorization: Bearer <token>" or
 * "X-Api-Token: <token>". Tokens come from the environment variables named in
 * ApiController.token_env (without any, the API answers 404). Resources and
 * their fields: ApiResources.
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
        $denied = $this->checkToken($request);
        if ($denied) {
            return $denied;
        }
        $resources = ApiResources::singleton();
        $name = (string) $request->param('Resource');
        $definition = $resources->definition($name);
        $id = (int) $request->param('ID');
        if (!$definition) {
            return $this->error(404, 'Unknown resource');
        }
        $list = $resources->baseList($definition);
        if ($id) {
            $record = empty($definition['get']) ? null : $list->byID($id);
            if (!$record instanceof DataObject) {
                return $this->error(404, 'Not found');
            }
            return $this->json($resources->serialize($name, $record, $this->fileBase()));
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
        return $this->json(['total' => $total, 'limit' => $limit, 'offset' => $offset, 'items' => $items]);
    }

    public function file(HTTPRequest $request): HTTPResponse
    {
        $denied = $this->checkToken($request);
        if ($denied) {
            return $denied;
        }
        $resources = ApiResources::singleton();
        $name = (string) $request->param('Resource');
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

    /**
     * Null when the token is valid, otherwise the error response.
     */
    private function checkToken(HTTPRequest $request): ?HTTPResponse
    {
        $tokens = [];
        foreach ((array) static::config()->get('token_env') as $env) {
            $value = (string) Environment::getEnv((string) $env);
            if ($value !== '') {
                $tokens[] = $value;
            }
        }
        if (!$tokens) {
            return $this->error(404, 'Not found');
        }
        $given = (string) $request->getHeader('X-Api-Token');
        $authorization = (string) $request->getHeader('Authorization');
        if ($given === '' && str_starts_with($authorization, 'Bearer ')) {
            $given = trim(substr($authorization, 7));
        }
        foreach ($tokens as $token) {
            if ($given !== '' && hash_equals($token, $given)) {
                return null;
            }
        }
        Injector::inst()->get(LoggerInterface::class)->warning(
            'Flow API: request without valid token from ' . $request->getIP()
        );
        return $this->error(401, 'Invalid token');
    }

    private function fileBase(): string
    {
        return Director::absoluteURL('flow-api/file');
    }

    /**
     * @param array<mixed> $data
     */
    private function json(array $data): HTTPResponse
    {
        $response = HTTPResponse::create((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $response->addHeader('Content-Type', 'application/json; charset=utf-8');
        $response->addHeader('Cache-Control', 'private, no-store');
        $response->addHeader('X-Robots-Tag', 'noindex');
        return $response;
    }

    private function error(int $status, string $message): HTTPResponse
    {
        $response = $this->json(['error' => $message]);
        $response->setStatusCode($status);
        return $response;
    }
}
