<?php

declare(strict_types=1);

namespace BNT\NpcAgent;

/**
 * Minimal JSON-schema validator for tool arguments (the subset the tools use):
 * type, required, properties, additionalProperties, enum, minimum, maximum, minLength/maxLength.
 */
class SchemaValidator
{
    /** @return string[] error messages; empty when the value is valid */
    public static function validate(mixed $value, array $schema, string $path = 'arguments'): array
    {
        $errors = [];
        $type = $schema['type'] ?? null;
        if ($type !== null && !self::typeMatches($value, $type)) {
            return ["$path must be of type $type"];
        }
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $errors[] = "$path must be one of: " . implode(', ', $schema['enum']);
        }
        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = "$path must be >= {$schema['minimum']}";
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = "$path must be <= {$schema['maximum']}";
            }
        }
        if (is_string($value)) {
            if (isset($schema['minLength']) && mb_strlen($value) < $schema['minLength']) {
                $errors[] = "$path must be at least {$schema['minLength']} characters";
            }
            if (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
                $errors[] = "$path must be at most {$schema['maxLength']} characters";
            }
        }
        if ($type === 'object' && is_array($value)) {
            foreach ($schema['required'] ?? [] as $req) {
                if (!array_key_exists($req, $value)) {
                    $errors[] = "$path.$req is required";
                }
            }
            $props = $schema['properties'] ?? [];
            foreach ($value as $k => $v) {
                if (isset($props[$k])) {
                    $errors = array_merge($errors, self::validate($v, $props[$k], "$path.$k"));
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = "$path.$k is not an allowed argument";
                }
            }
        }
        return $errors;
    }

    private static function typeMatches(mixed $v, string $type): bool
    {
        return match ($type) {
            'integer' => is_int($v),
            'number' => is_int($v) || is_float($v),
            'string' => is_string($v),
            'boolean' => is_bool($v),
            'array' => is_array($v) && array_is_list($v),
            'object' => is_array($v) && ($v === [] || !array_is_list($v)),
            default => true,
        };
    }
}
