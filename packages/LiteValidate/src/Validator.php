<?php

declare(strict_types=1);

namespace LiteValidate;

use LiteValidate\Attribute\{Nested, RuleInterface};
use LiteValidate\Exception\ValidationException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * High-performance PHP 8.2+ Attribute-based Validation Engine.
 */
class Validator
{
    /** @var array<string, array<string, RuleInterface[]>> Cache of class => [property => rules] */
    private static array $metadataCache = [];

    /**
     * Set active locale for validation messages ('en', 'vi', etc.).
     */
    public static function setLocale(string $locale): void
    {
        \LiteValidate\I18n\Translator::setLocale($locale);
    }

    /**
     * Get current active locale.
     */
    public static function getLocale(): string
    {
        return \LiteValidate\I18n\Translator::getLocale();
    }

    /**
     * Set default PDO instance for database validation rules (#[Unique], #[Exists]).
     */
    public static function setPdo(?\PDO $pdo): void
    {
        \LiteValidate\Database\DatabaseResolver::setPdo($pdo);
    }

    /**
     * Set callback resolver for database connections.
     */
    public static function setConnectionResolver(callable $resolver): void
    {
        \LiteValidate\Database\DatabaseResolver::setConnectionResolver($resolver);
    }

    /**
     * Validate an object or an array against a DTO class.
     *
     * @param object|array<string, mixed> $data
     * @param class-string|null $dtoClass Required if $data is an array
     * @return ValidationResult
     */
    public static function validate(object|array $data, ?string $dtoClass = null): ValidationResult
    {
        if (is_array($data)) {
            if ($dtoClass === null) {
                throw new \InvalidArgumentException('A DTO class-string must be provided when validating array data.');
            }
            return self::validateArrayAgainstClass($data, $dtoClass);
        }

        return self::validateObject($data);
    }

    /**
     * Validate array data against a DTO class, hydrate it, and return the typed instance or throw ValidationException.
     *
     * @template T of object
     * @param class-string<T> $dtoClass
     * @param array<string, mixed> $data
     * @return T
     * @throws ValidationException
     */
    public static function make(string $dtoClass, array $data): object
    {
        $result = self::validate($data, $dtoClass);
        return $result->validateOrFail();
    }

    /**
     * Export a standard JSON Schema (Draft-07) from a DTO class and its validation attributes.
     * Compatible with OpenAPI 3.0.3, Swagger UI, and form generators.
     *
     * @param class-string $dtoClass
     * @param string|null $title
     * @return array<string, mixed>
     */
    public static function toJsonSchema(string $dtoClass, ?string $title = null): array
    {
        $ref = new ReflectionClass($dtoClass);
        $properties = [];
        $required = [];

        foreach ($ref->getProperties() as $prop) {
            $name = $prop->getName();
            $type = $prop->getType();
            $propSchema = ['type' => 'string'];

            if ($type instanceof ReflectionNamedType) {
                $propSchema['type'] = match ($type->getName()) {
                    'int' => 'integer',
                    'float' => 'number',
                    'bool' => 'boolean',
                    'array' => 'array',
                    default => 'string',
                };
            }

            $attributes = $prop->getAttributes();
            foreach ($attributes as $attr) {
                $instance = $attr->newInstance();

                if ($instance instanceof Attribute\Required) {
                    $required[] = $name;
                } elseif ($instance instanceof Attribute\Email) {
                    $propSchema['format'] = 'email';
                } elseif ($instance instanceof Attribute\Url) {
                    $propSchema['format'] = 'uri';
                } elseif ($instance instanceof Attribute\Length) {
                    if ($instance->min !== null) $propSchema['minLength'] = $instance->min;
                    if ($instance->max !== null) $propSchema['maxLength'] = $instance->max;
                } elseif ($instance instanceof Attribute\Range) {
                    $isInteger = ($propSchema['type'] ?? '') === 'integer';
                    if ($instance->min !== null) $propSchema['minimum'] = $isInteger ? (int) $instance->min : $instance->min;
                    if ($instance->max !== null) $propSchema['maximum'] = $isInteger ? (int) $instance->max : $instance->max;
                } elseif ($instance instanceof Attribute\Positive) {
                    $propSchema['minimum'] = 1;
                } elseif ($instance instanceof Attribute\PositiveOrZero) {
                    $propSchema['minimum'] = 0;
                } elseif ($instance instanceof Attribute\In) {
                    $propSchema['enum'] = $instance->values;
                } elseif ($instance instanceof Attribute\Regex) {
                    $propSchema['pattern'] = trim($instance->pattern, '/');
                } elseif ($instance instanceof Attribute\Nested) {
                    $nestedClass = $instance->dtoClass;
                    if ($nestedClass === null && $type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                        $nestedClass = $type->getName();
                    }
                    if ($nestedClass !== null && class_exists($nestedClass)) {
                        $nestedSchema = self::toJsonSchema($nestedClass);
                        unset($nestedSchema['$schema']);
                        $propSchema = $nestedSchema;
                    }
                }
            }

            $properties[$name] = $propSchema;
        }

        $schema = [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'title' => $title ?? $ref->getShortName(),
            'properties' => $properties,
        ];

        if (!empty($required)) {
            $schema['required'] = array_values(array_unique($required));
        }

        return $schema;
    }

    private static function validateObject(object $object, string $prefix = ''): ValidationResult
    {
        $class = get_class($object);
        $rules = self::getRulesForClass($class);
        $errors = [];

        foreach ($rules as $property => $propertyRules) {
            $fieldName = $prefix === '' ? $property : "{$prefix}.{$property}";
            $value = isset($object->{$property}) ? $object->{$property} : null;

            foreach ($propertyRules as $rule) {
                if ($rule instanceof Nested) {
                    if ($value !== null && is_object($value)) {
                        $nestedResult = self::validateObject($value, $fieldName);
                        foreach ($nestedResult->getErrors() as $nestedField => $nestedErrors) {
                            $errors[$nestedField] = array_merge($errors[$nestedField] ?? [], $nestedErrors);
                        }
                    }
                    continue;
                }

                $error = $rule->validate($fieldName, $value, $object);
                if ($error !== null) {
                    $errors[$fieldName][] = $error;
                }
            }
        }

        return new ValidationResult($errors, $object);
    }

    private static function validateArrayAgainstClass(array $data, string $class, string $prefix = ''): ValidationResult
    {
        $rules = self::getRulesForClass($class);
        $errors = [];
        $hydratedProps = [];

        $refClass = new ReflectionClass($class);

        foreach ($rules as $property => $propertyRules) {
            $fieldName = $prefix === '' ? $property : "{$prefix}.{$property}";
            $hasKey = array_key_exists($property, $data);
            $rawValue = $hasKey ? $data[$property] : null;

            // Handle Nested rule
            $nestedRule = null;
            foreach ($propertyRules as $rule) {
                if ($rule instanceof Nested) {
                    $nestedRule = $rule;
                    break;
                }
            }

            if ($nestedRule !== null) {
                $nestedClass = $nestedRule->dtoClass;
                if ($nestedClass === null && $refClass->hasProperty($property)) {
                    $type = $refClass->getProperty($property)->getType();
                    if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                        $nestedClass = $type->getName();
                    }
                }

                if ($nestedClass !== null && is_array($rawValue)) {
                    $nestedResult = self::validateArrayAgainstClass($rawValue, $nestedClass, $fieldName);
                    if ($nestedResult->isFailed()) {
                        foreach ($nestedResult->getErrors() as $nf => $ne) {
                            $errors[$nf] = array_merge($errors[$nf] ?? [], $ne);
                        }
                    } else {
                        $hydratedProps[$property] = $nestedResult->validated();
                    }
                    continue;
                }
            }

            // Normal rule validation
            foreach ($propertyRules as $rule) {
                if ($rule instanceof Nested) continue;
                $error = $rule->validate($fieldName, $rawValue, $data);
                if ($error !== null) {
                    $errors[$fieldName][] = $error;
                }
            }

            if (!isset($errors[$fieldName]) && $hasKey) {
                $hydratedProps[$property] = self::castToPropertyType($refClass, $property, $rawValue);
            }
        }

        if (!empty($errors)) {
            return new ValidationResult($errors, null);
        }

        // Hydrate object
        $instance = $refClass->newInstanceWithoutConstructor();
        foreach ($hydratedProps as $prop => $val) {
            if ($refClass->hasProperty($prop)) {
                $refProp = $refClass->getProperty($prop);
                $refProp->setAccessible(true);
                $refProp->setValue($instance, $val);
            }
        }

        return new ValidationResult([], $instance);
    }

    /**
     * @param class-string $class
     * @return array<string, RuleInterface[]>
     */
    private static function getRulesForClass(string $class): array
    {
        if (isset(self::$metadataCache[$class])) {
            return self::$metadataCache[$class];
        }

        $ref = new ReflectionClass($class);
        $rules = [];

        foreach ($ref->getProperties() as $prop) {
            $propRules = [];
            foreach ($prop->getAttributes() as $attr) {
                $instance = $attr->newInstance();
                if ($instance instanceof RuleInterface) {
                    $propRules[] = $instance;
                }
            }
            if (!empty($propRules)) {
                $rules[$prop->getName()] = $propRules;
            }
        }

        self::$metadataCache[$class] = $rules;
        return $rules;
    }

    private static function castToPropertyType(ReflectionClass $class, string $property, mixed $value): mixed
    {
        if ($value === null || !$class->hasProperty($property)) {
            return $value;
        }

        $type = $class->getProperty($property)->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin() === false) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => (int) $value,
            'float' => (float) $value,
            'string' => (string) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }
}
