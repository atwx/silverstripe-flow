<?php

namespace Atwx\Flow\Intake;

/**
 * A form type of the inbox, from IntakeService.types: its fields with their
 * validation, its files, notification, receipt, retention and takeover.
 *
 * Field types: string (max, default 255), text (max, default 10000), email,
 * date (YYYY-MM-DD), integer and number (min, max), boolean (accepted: true =
 * must be true), choice (options; multiple: true for several), list (strings,
 * max_items), group (an object with its own fields) and repeat (a list of
 * objects with fields, max_items). Every field: title, required, help.
 *
 * @phpstan-type FieldDef array{
 *     type?: string, title?: string, required?: bool, help?: string, max?: int|float, min?: int|float,
 *     options?: array<int|string, string>, multiple?: bool, accepted?: bool, max_items?: int,
 *     fields?: array<string, mixed>
 * }
 * @phpstan-type FileDef array{title?: string, types?: array<int, string>, max_size?: int|string,
 *     max_count?: int, required?: bool}
 */
class IntakeType
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private string $name, private array $config)
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTitle(): string
    {
        return (string) ($this->config['title'] ?? $this->name);
    }

    public function referencePrefix(): string
    {
        $prefix = (string) ($this->config['reference_prefix'] ?? strtoupper(substr($this->name, 0, 3)));
        return preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?: 'IN';
    }

    /**
     * @return array<string, FieldDef>
     */
    public function fields(): array
    {
        return self::normalizeFields((array) ($this->config['fields'] ?? []));
    }

    /**
     * @return array<string, FileDef>
     */
    public function files(): array
    {
        $files = [];
        foreach ((array) ($this->config['files'] ?? []) as $name => $definition) {
            if (is_array($definition)) {
                $files[(string) $name] = $definition;
            }
        }
        return $files;
    }

    /**
     * Value of a config key of the type (notify, receipt, retention, takeover, …).
     */
    public function option(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Field holding the submitter's e-mail address (Reply-To of the notification).
     */
    public function replyToField(): ?string
    {
        $field = $this->config['reply_to'] ?? null;
        return is_string($field) && $field !== '' ? $field : null;
    }

    /**
     * Days a submission in the status is kept after its last status change
     * (0 or missing = kept).
     */
    public function retentionDays(string $status): int
    {
        $retention = (array) ($this->config['retention'] ?? []);
        return max(0, (int) ($retention[$status] ?? 0));
    }

    /**
     * Largest accepted file in bytes.
     *
     * @param FileDef $definition
     */
    public static function maxSize(array $definition): int
    {
        $size = $definition['max_size'] ?? '10MB';
        if (is_int($size)) {
            return $size;
        }
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*(k|m|g)?b?\s*$/i', (string) $size, $match)) {
            $factor = ['' => 1, 'k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3][strtolower($match[2] ?? '')];
            return (int) ((float) $match[1] * $factor);
        }
        return 10 * 1024 ** 2;
    }

    /**
     * Largest request body: all files at their largest (base64) plus 1 MB.
     */
    public function maxBodyBytes(): int
    {
        $bytes = 1024 ** 2;
        foreach ($this->files() as $definition) {
            $bytes += (int) ceil(self::maxSize($definition) * max(1, (int) ($definition['max_count'] ?? 1)) * 4 / 3);
        }
        return $bytes;
    }

    /**
     * Checks and normalises submitted data: unknown fields and wrong types are
     * errors. Returns [clean data, errors by path ("siblings.0.first_name")].
     *
     * @param array<mixed> $data
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public function validate(array $data): array
    {
        $errors = [];
        $clean = $this->validateFields($this->fields(), $data, '', $errors);
        return [$clean, $errors];
    }

    /**
     * @param array<string, FieldDef> $fields
     * @param array<mixed> $data
     * @param array<string, string> $errors
     * @return array<string, mixed>
     */
    private function validateFields(array $fields, array $data, string $prefix, array &$errors): array
    {
        $clean = [];
        foreach (array_keys($data) as $key) {
            if (!isset($fields[(string) $key])) {
                $errors[$prefix . $key] = 'Unknown field';
            }
        }
        foreach ($fields as $name => $definition) {
            $path = $prefix . $name;
            $value = $data[$name] ?? null;
            if ($value === '' || $value === []) {
                $value = null;
            }
            if ($value === null) {
                if (!empty($definition['required'])) {
                    $errors[$path] = 'Required';
                }
                $clean[$name] = null;
                continue;
            }
            $clean[$name] = $this->validateValue($definition, $value, $path, $errors);
        }
        return $clean;
    }

    /**
     * @param FieldDef $definition
     * @param array<string, string> $errors
     */
    private function validateValue(array $definition, mixed $value, string $path, array &$errors): mixed
    {
        $type = (string) ($definition['type'] ?? 'string');
        switch ($type) {
            case 'string':
            case 'text':
            case 'email':
                if (!is_scalar($value) || is_bool($value)) {
                    $errors[$path] = 'Must be text';
                    return null;
                }
                $value = trim((string) $value);
                $max = (int) ($definition['max'] ?? ($type === 'text' ? 10000 : 255));
                if (mb_strlen($value) > $max) {
                    $errors[$path] = 'At most ' . $max . ' characters';
                } elseif ($type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$path] = 'Not a valid e-mail address';
                }
                return $value;
            case 'date':
                $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
                if (!$date || $date->format('Y-m-d') !== $value) {
                    $errors[$path] = 'Must be a date (YYYY-MM-DD)';
                }
                return $value;
            case 'integer':
            case 'number':
                $ok = $type === 'integer'
                    ? filter_var($value, FILTER_VALIDATE_INT) !== false && !is_bool($value)
                    : is_numeric($value);
                if (!$ok) {
                    $errors[$path] = $type === 'integer' ? 'Must be a whole number' : 'Must be a number';
                    return null;
                }
                $number = $type === 'integer' ? (int) $value : (float) $value;
                if (isset($definition['min']) && $number < $definition['min']) {
                    $errors[$path] = 'At least ' . $definition['min'];
                } elseif (isset($definition['max']) && $number > $definition['max']) {
                    $errors[$path] = 'At most ' . $definition['max'];
                }
                return $number;
            case 'boolean':
                if (!is_bool($value)) {
                    $errors[$path] = 'Must be true or false';
                    return null;
                }
                if (!empty($definition['accepted']) && !$value) {
                    $errors[$path] = 'Must be accepted';
                }
                return $value;
            case 'choice':
                $options = array_map('strval', array_keys(self::options($definition)));
                $values = !empty($definition['multiple']) ? $value : [$value];
                if (!is_array($values) || !array_is_list($values)) {
                    $errors[$path] = 'Must be a list';
                    return null;
                }
                foreach ($values as $one) {
                    if (!is_scalar($one) || !in_array((string) $one, $options, true)) {
                        $errors[$path] = 'Not one of the options';
                        return null;
                    }
                }
                $values = array_values(array_unique(array_map('strval', $values)));
                return !empty($definition['multiple']) ? $values : $values[0];
            case 'list':
                if (!is_array($value) || !array_is_list($value)) {
                    $errors[$path] = 'Must be a list';
                    return null;
                }
                $items = [];
                foreach ($value as $i => $item) {
                    $items[] = $this->validateValue(
                        ['type' => 'string', 'max' => $definition['max'] ?? 255],
                        $item,
                        $path . '.' . $i,
                        $errors
                    );
                }
                $this->checkMaxItems($definition, $items, $path, $errors);
                return $items;
            case 'group':
                if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                    $errors[$path] = 'Must be an object';
                    return null;
                }
                $subFields = self::normalizeFields((array) ($definition['fields'] ?? []));
                return $this->validateFields($subFields, $value, $path . '.', $errors);
            case 'repeat':
                if (!is_array($value) || !array_is_list($value)) {
                    $errors[$path] = 'Must be a list';
                    return null;
                }
                $items = [];
                $subFields = self::normalizeFields((array) ($definition['fields'] ?? []));
                foreach ($value as $i => $item) {
                    if (!is_array($item) || ($item !== [] && array_is_list($item))) {
                        $errors[$path . '.' . $i] = 'Must be an object';
                        continue;
                    }
                    $items[] = $this->validateFields($subFields, $item, $path . '.' . $i . '.', $errors);
                }
                $this->checkMaxItems($definition, $items, $path, $errors);
                return $items;
        }
        $errors[$path] = 'Unknown field type ' . $type;
        return null;
    }

    /**
     * @param FieldDef $definition
     * @param array<mixed> $items
     * @param array<string, string> $errors
     */
    private function checkMaxItems(array $definition, array $items, string $path, array &$errors): void
    {
        if (isset($definition['max_items']) && count($items) > (int) $definition['max_items']) {
            $errors[$path] = 'At most ' . (int) $definition['max_items'] . ' entries';
        }
    }

    /**
     * Options of a choice field as value => title.
     *
     * @param FieldDef $definition
     * @return array<int|string, string>
     */
    public static function options(array $definition): array
    {
        $options = (array) ($definition['options'] ?? []);
        if (array_is_list($options)) {
            $options = array_combine(array_map('strval', $options), array_map('strval', $options));
        }
        return array_map('strval', $options);
    }

    /**
     * @param array<mixed> $fields
     * @return array<string, FieldDef>
     */
    public static function normalizeFields(array $fields): array
    {
        $normalized = [];
        foreach ($fields as $name => $definition) {
            $normalized[(string) $name] = is_array($definition) ? $definition : ['type' => (string) $definition];
        }
        return $normalized;
    }

    /**
     * JSON schema of the request: {"data": {...}, "files": {...}}.
     *
     * @return array<string, mixed>
     */
    public function jsonSchema(): array
    {
        $files = [];
        foreach ($this->files() as $name => $definition) {
            $files[$name] = [
                'type' => 'array',
                'description' => trim(($definition['title'] ?? $name) . '; types: '
                    . implode(', ', (array) ($definition['types'] ?? [])) . '; max. '
                    . self::maxSize($definition) . ' bytes each'),
                'maxItems' => max(1, (int) ($definition['max_count'] ?? 1)),
                'items' => [
                    'type' => 'object',
                    'required' => ['name', 'content'],
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'content' => ['type' => 'string', 'contentEncoding' => 'base64'],
                    ],
                ],
            ];
        }
        $schema = [
            'type' => 'object',
            'required' => ['data'],
            'properties' => ['data' => $this->objectSchema($this->fields())],
        ];
        if ($files !== []) {
            $schema['properties']['files'] = [
                'type' => 'object',
                'properties' => $files,
                'additionalProperties' => false,
            ];
        }
        return $schema;
    }

    /**
     * @param array<string, FieldDef> $fields
     * @return array<string, mixed>
     */
    private function objectSchema(array $fields): array
    {
        $properties = [];
        $required = [];
        foreach ($fields as $name => $definition) {
            $properties[$name] = $this->valueSchema($definition);
            if (!empty($definition['required'])) {
                $required[] = $name;
            }
        }
        $schema = ['type' => 'object', 'properties' => (object) $properties, 'additionalProperties' => false];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        return $schema;
    }

    /**
     * @param FieldDef $definition
     * @return array<string, mixed>
     */
    private function valueSchema(array $definition): array
    {
        $type = (string) ($definition['type'] ?? 'string');
        $sub = self::normalizeFields((array) ($definition['fields'] ?? []));
        $enum = array_map('strval', array_keys(self::options($definition)));
        $maxLength = (int) ($definition['max'] ?? ($type === 'text' ? 10000 : 255));
        $schema = match ($type) {
            'string', 'text' => ['type' => 'string', 'maxLength' => $maxLength],
            'email' => ['type' => 'string', 'format' => 'email'],
            'date' => ['type' => 'string', 'format' => 'date'],
            'integer' => ['type' => 'integer'],
            'number' => ['type' => 'number'],
            'boolean' => !empty($definition['accepted'])
                ? ['type' => 'boolean', 'const' => true]
                : ['type' => 'boolean'],
            'choice' => !empty($definition['multiple'])
                ? ['type' => 'array', 'items' => ['enum' => $enum]]
                : ['enum' => $enum],
            'list' => ['type' => 'array', 'items' => ['type' => 'string']],
            'group' => $this->objectSchema($sub),
            'repeat' => ['type' => 'array', 'items' => $this->objectSchema($sub)],
            default => [],
        };
        foreach (['min' => 'minimum', 'max' => 'maximum'] as $key => $keyword) {
            if (in_array($type, ['integer', 'number'], true) && isset($definition[$key])) {
                $schema[$keyword] = $definition[$key];
            }
        }
        if (isset($definition['max_items']) && in_array($type, ['list', 'repeat'], true)) {
            $schema['maxItems'] = (int) $definition['max_items'];
        }
        if (isset($definition['title'])) {
            $schema['title'] = (string) $definition['title'];
        }
        if (isset($definition['help'])) {
            $schema['description'] = (string) $definition['help'];
        }
        return $schema;
    }

    /**
     * Rows for showing data: title and display value, nested for groups and
     * repeats ([title, value|rows]).
     *
     * @param array<string, mixed> $data
     * @param array<string, FieldDef>|null $fields
     * @return array<int, array{title: string, value: string, rows: array<int, mixed>, path: string}>
     */
    public function rows(array $data, ?array $fields = null, string $prefix = ''): array
    {
        $rows = [];
        foreach ($fields ?? $this->fields() as $name => $definition) {
            $value = $data[$name] ?? null;
            $title = (string) ($definition['title'] ?? $name);
            $type = (string) ($definition['type'] ?? 'string');
            $path = $prefix . $name;
            if ($type === 'group') {
                $sub = self::normalizeFields((array) ($definition['fields'] ?? []));
                $rows[] = ['title' => $title, 'value' => '', 'path' => $path,
                    'rows' => $this->rows(is_array($value) ? $value : [], $sub, $path . '.')];
                continue;
            }
            if ($type === 'repeat') {
                $sub = self::normalizeFields((array) ($definition['fields'] ?? []));
                $items = [];
                foreach (is_array($value) ? array_values($value) : [] as $i => $item) {
                    $items[] = ['title' => $title . ' ' . ($i + 1), 'value' => '', 'path' => $path . '.' . $i,
                        'rows' => $this->rows(is_array($item) ? $item : [], $sub, $path . '.' . $i . '.')];
                }
                $rows[] = ['title' => $title, 'value' => $items === [] ? '–' : '', 'rows' => $items, 'path' => $path];
                continue;
            }
            $rows[] = ['title' => $title, 'value' => self::display($definition, $value), 'rows' => [], 'path' => $path];
        }
        return $rows;
    }

    /**
     * @param FieldDef $definition
     */
    public static function display(array $definition, mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '–';
        }
        $type = (string) ($definition['type'] ?? 'string');
        if ($type === 'boolean') {
            return $value ? _t(self::class . '.YES', 'Yes') : _t(self::class . '.NO', 'No');
        }
        if ($type === 'date' && is_string($value) && ($time = strtotime($value))) {
            return date('d.m.Y', $time);
        }
        if ($type === 'choice') {
            $options = self::options($definition);
            $titles = array_map(fn ($one) => $options[(string) $one] ?? (string) $one, (array) $value);
            return implode(', ', $titles);
        }
        if (is_array($value)) {
            return implode(', ', array_map(fn ($one) => is_scalar($one) ? (string) $one : '', $value));
        }
        return is_scalar($value) ? (string) $value : '';
    }
}
