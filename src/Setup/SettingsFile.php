<?php declare(strict_types=1);

namespace Shopproof\Compliance\Setup;

/**
 * Reads and checks a Shopproof settings file, so an agency can set up one shop, export its
 * settings and import them into its other client shops. The allowed names, types, choices and
 * limits come from the plugin's own config.xml, so the file can never set anything the
 * settings page cannot. Pure: unit tested alone.
 *
 * File format: {"shopproof": 1, "settings": {"countries": ["AT", "DE"], "showGuaranteeNotice": true, ...}}
 */
final class SettingsFile
{
    public const FORMAT = 1;

    /** @var array<string, array{type: string, options: list<string>, min: ?int, max: ?int}> */
    private array $fields = [];

    /** @var list<string> settings that point at this shop's own records (component fields) */
    private array $skipped = [];

    public function __construct(string $configXml)
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($configXml);
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            throw new \InvalidArgumentException('The plugin settings schema (config.xml) could not be read.');
        }
        foreach ($xml->xpath('//component') ?: [] as $component) {
            $name = trim((string) $component->name);
            if ($name !== '') {
                $this->skipped[] = $name;
            }
        }
        foreach ($xml->xpath('//input-field') ?: [] as $field) {
            $name = trim((string) $field->name);
            if ($name === '') {
                continue;
            }
            $options = [];
            foreach ($field->xpath('options/option/id') ?: [] as $id) {
                $options[] = trim((string) $id);
            }
            $this->fields[$name] = [
                'type' => trim((string) ($field['type'] ?? 'text')) ?: 'text',
                'options' => $options,
                'min' => isset($field->min) ? (int) $field->min : null,
                'max' => isset($field->max) ? (int) $field->max : null,
            ];
        }
    }

    /**
     * Settings left out of the file because they hold ids of this shop's own records (such as
     * categories), which differ in every other shop.
     *
     * @return list<string>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->fields);
    }

    /**
     * The settings file for the given values; names the schema does not know are left out.
     *
     * @param array<string, mixed> $values
     */
    public function export(array $values): string
    {
        $settings = [];
        foreach ($this->fields as $name => $field) {
            if (\array_key_exists($name, $values) && $values[$name] !== null) {
                // Shopware may keep a number or a switch as text; write it as the type the setting has.
                $value = $values[$name];
                $settings[$name] = match (true) {
                    $field['type'] === 'int' && \is_string($value) && preg_match('/^-?\d+$/', $value) === 1 => (int) $value,
                    $field['type'] === 'bool' && \in_array($value, ['1', '0', 1, 0], true) => (bool) $value,
                    default => $value,
                };
            }
        }

        return json_encode(
            ['shopproof' => self::FORMAT, 'settings' => (object) $settings],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
        ) . "\n";
    }

    /**
     * Checks a settings file. Returns the values to write and the problems; when there is any
     * problem nothing should be written, so a shop never gets half a file.
     *
     * @return array{values: array<string, bool|int|string|list<string>>, errors: list<string>}
     */
    public function parse(string $json): array
    {
        try {
            $data = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['values' => [], 'errors' => ['The file is not valid JSON: ' . $e->getMessage()]];
        }
        if (!\is_array($data) || ($data['shopproof'] ?? null) !== self::FORMAT || !\is_array($data['settings'] ?? null)) {
            return ['values' => [], 'errors' => ['The file is not a Shopproof settings file. Expected {"shopproof": 1, "settings": {...}}, as written by shopproof:settings:export.']];
        }

        $values = [];
        $errors = [];
        foreach ($data['settings'] as $name => $value) {
            $name = (string) $name;
            $field = $this->fields[$name] ?? null;
            if ($field === null) {
                $errors[] = \sprintf('"%s" is not a Shopproof setting.', $name);
                continue;
            }
            $checked = self::check($name, $field, $value);
            if (\is_string($checked['error'])) {
                $errors[] = $checked['error'];
                continue;
            }
            $values[$name] = $checked['value'];
        }
        if ($values === [] && $errors === []) {
            $errors[] = 'The file has no settings.';
        }

        return ['values' => $errors === [] ? $values : [], 'errors' => $errors];
    }

    /**
     * @param array{type: string, options: list<string>, min: ?int, max: ?int} $field
     *
     * @return array{value: bool|int|string|list<string>, error: ?string}
     */
    private static function check(string $name, array $field, mixed $value): array
    {
        $fail = static fn (string $why): array => ['value' => '', 'error' => \sprintf('"%s" %s', $name, $why)];

        switch ($field['type']) {
            case 'bool':
                return \is_bool($value) ? ['value' => $value, 'error' => null] : $fail('must be true or false.');
            case 'int':
                if (!\is_int($value)) {
                    return $fail('must be a whole number.');
                }
                if (($field['min'] !== null && $value < $field['min']) || ($field['max'] !== null && $value > $field['max'])) {
                    return $fail(\sprintf('must be between %s and %s.', $field['min'] ?? 'any', $field['max'] ?? 'any'));
                }

                return ['value' => $value, 'error' => null];
            case 'single-select':
                return \is_string($value) && \in_array($value, $field['options'], true)
                    ? ['value' => $value, 'error' => null]
                    : $fail('must be one of: ' . implode(', ', $field['options']) . '.');
            case 'multi-select':
                if (!\is_array($value) || !array_is_list($value)) {
                    return $fail('must be a list, for example ["AT", "DE"].');
                }
                foreach ($value as $item) {
                    if (!\is_string($item) || !\in_array($item, $field['options'], true)) {
                        return $fail('may only hold: ' . implode(', ', $field['options']) . '.');
                    }
                }

                return ['value' => array_values(array_unique($value)), 'error' => null];
            default:
                if (!\is_string($value)) {
                    return $fail('must be text.');
                }
                $value = trim($value);
                if ($value !== '' && $field['type'] === 'url' && preg_match('#^https?://[^\s]+$#i', $value) !== 1) {
                    return $fail('must be a web address starting with https:// or http://.');
                }
                if ($value !== '' && str_ends_with($name, 'Email') && filter_var($value, \FILTER_VALIDATE_EMAIL) === false) {
                    return $fail('must be an email address.');
                }

                return ['value' => $value, 'error' => null];
        }
    }
}
