<?php

namespace Atwx\Flow\Api;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Model\List\SS_List;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBBoolean;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBDecimal;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBFloat;
use SilverStripe\ORM\FieldType\DBInt;
use SilverStripe\ORM\FieldType\DBTime;

/**
 * Resources of the JSON API (ApiController), configured by the project. Only
 * what is listed goes out:
 *
 *   Atwx\Flow\Api\ApiResources:
 *     resources:
 *       family:
 *         class: App\Models\Family
 *         get: true                      # GET flow-api/family/<id>
 *         fields:
 *           id: ID
 *           surname: Surname             # database field
 *           status: StatusTitle          # getter getStatusTitle()
 *           placementSchool: PlacementSchool.Name   # has_one path
 *           image: { file: Image }       # download URL (flow-api/file/...)
 *           children: { relation: Children, resource: child }   # nested
 *           documents: { relation: Documents, resource: document, filter: { InPortal: 1 } }
 *       schools:
 *         class: App\Models\School
 *         list: true                     # GET flow-api/schools?Active=1
 *         filter: { Active: 1 }          # always applied
 *         params: [Active]               # filters the client may set
 *         fields: { id: ID, name: Name }
 *
 * Values: integers, decimals and booleans as JSON numbers and booleans, dates
 * as YYYY-MM-DD, date and time as YYYY-MM-DD HH:MM:SS, the rest as strings; a
 * missing has_one gives null. Getters are only called when listed.
 */
class ApiResources
{
    use Configurable;
    use Injectable;

    /**
     * Resource definitions by name (see class doc).
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $resources = [];

    /**
     * Nesting deeper than this is cut off (protects against loops).
     */
    private static int $max_depth = 6;

    /**
     * @return array<string, mixed>|null
     */
    public function definition(string $name): ?array
    {
        $definitions = (array) static::config()->get('resources');
        $definition = $definitions[$name] ?? null;
        if (!is_array($definition) || !isset($definition['class']) || !class_exists((string) $definition['class'])) {
            return null;
        }
        return $definition;
    }

    /**
     * The base list of a resource with its fixed filter.
     *
     * @param array<string, mixed> $definition
     * @return DataList<DataObject>
     */
    public function baseList(array $definition): DataList
    {
        $list = DataList::create((string) $definition['class']);
        $filter = (array) ($definition['filter'] ?? []);
        return $filter ? $list->filter($filter) : $list;
    }

    /**
     * The record as array, with the resource's fields.
     *
     * @return array<string, mixed>
     */
    public function serialize(string $resource, DataObject $record, string $fileBase, int $depth = 0): array
    {
        $definition = $this->definition($resource);
        if (!$definition) {
            return [];
        }
        $data = [];
        foreach ((array) ($definition['fields'] ?? []) as $key => $spec) {
            $name = is_int($key) ? (string) $spec : (string) $key;
            if (is_array($spec) && isset($spec['relation'])) {
                $data[$name] = $depth >= (int) static::config()->get('max_depth')
                    ? null
                    : $this->relation($record, $spec, $fileBase, $depth);
            } elseif (is_array($spec) && isset($spec['file'])) {
                $data[$name] = $this->fileOf($record, (string) $spec['file'])
                    ? $fileBase . '/' . rawurlencode($resource) . '/' . $record->ID . '/' . rawurlencode($name)
                    : null;
            } else {
                $data[$name] = $this->value($record, explode('.', (string) $spec));
            }
        }
        return $data;
    }

    /**
     * The stored file of a file field of a resource (has_one File or DBFile),
     * if the field is configured as file and has content.
     */
    public function fileFor(string $resource, DataObject $record, string $key): ?DBFile
    {
        $spec = $this->definition($resource)['fields'][$key] ?? null;
        return is_array($spec) && isset($spec['file']) ? $this->fileOf($record, (string) $spec['file']) : null;
    }

    private function fileOf(DataObject $record, string $field): ?DBFile
    {
        $schema = DataObject::getSchema();
        if ($schema->hasOneComponent($record::class, $field)) {
            $file = $record->getComponent($field);
            return $file instanceof File && $file->exists() && $file->File->exists() ? $file->File : null;
        }
        if ($schema->fieldSpec($record::class, $field)) {
            $file = $record->dbObject($field);
            return $file instanceof DBFile && $file->exists() ? $file : null;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<string, mixed>|array<int, array<string, mixed>>|null
     */
    private function relation(DataObject $record, array $spec, string $fileBase, int $depth): ?array
    {
        $relation = (string) $spec['relation'];
        $resource = (string) ($spec['resource'] ?? '');
        if (!$this->definition($resource)) {
            return null;
        }
        $schema = DataObject::getSchema();
        if ($schema->hasOneComponent($record::class, $relation)) {
            $related = $record->getComponent($relation);
            return $related->exists() ? $this->serialize($resource, $related, $fileBase, $depth + 1) : null;
        }
        $isList = $schema->hasManyComponent($record::class, $relation)
            || $schema->manyManyComponent($record::class, $relation);
        if (!$isList) {
            return null;
        }
        $list = $record->$relation();
        if (!$list instanceof SS_List) {
            return [];
        }
        if ($list instanceof DataList) {
            $filter = (array) ($spec['filter'] ?? []);
            $list = $filter ? $list->filter($filter) : $list;
            $list = isset($spec['sort']) ? $list->orderBy((string) $spec['sort']) : $list;
        }
        $items = [];
        foreach ($list as $item) {
            $items[] = $this->serialize($resource, $item, $fileBase, $depth + 1);
        }
        return $items;
    }

    /**
     * A field, getter or has_one path; no other methods are called.
     *
     * @param array<int, string> $parts
     */
    private function value(DataObject $record, array $parts): mixed
    {
        $schema = DataObject::getSchema();
        while (count($parts) > 1) {
            $relation = array_shift($parts);
            if (!$schema->hasOneComponent($record::class, $relation)) {
                return null;
            }
            $record = $record->getComponent($relation);
            if (!$record->exists()) {
                return null;
            }
        }
        $field = (string) $parts[0];
        if ($field === 'ID') {
            return (int) $record->ID;
        }
        if ($schema->fieldSpec($record::class, $field)) {
            $object = $record->dbObject($field);
            $raw = $object->getValue();
            if ($raw === null || $raw === '') {
                return $object instanceof DBBoolean ? false : null;
            }
            return match (true) {
                $object instanceof DBBoolean => (bool) $raw,
                $object instanceof DBInt => (int) $raw,
                $object instanceof DBDecimal, $object instanceof DBFloat => (float) $raw,
                $object instanceof DBDate, $object instanceof DBTime => (string) $raw,
                default => is_scalar($raw) ? (string) $raw : null,
            };
        }
        if ($schema->hasOneComponent($record::class, $field)) {
            $component = $record->getComponent($field);
            return $component->exists() ? (string) $component->getTitle() : null;
        }
        $getter = 'get' . $field;
        if (method_exists($record, $getter)) {
            $value = $record->$getter();
            if ($value instanceof DBField) {
                $value = $value->getValue();
            }
            return is_scalar($value) || $value === null ? $value : null;
        }
        return null;
    }
}
