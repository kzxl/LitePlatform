<?php

declare(strict_types=1);

namespace LiteValidate\Attribute;

use Attribute;
use LiteValidate\Database\DatabaseResolver;
use LiteValidate\I18n\Translator;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class Unique implements RuleInterface
{
    public function __construct(
        public string $table,
        public ?string $column = null,
        public ?string $exceptField = null,
        public ?string $connection = null,
        public ?string $message = null,
    ) {}

    public function validate(string $field, mixed $value, object|array $context): ?string
    {
        if ($value === null || $value === '') return null;

        $pdo = DatabaseResolver::getPdo($this->connection);
        if ($pdo === null) {
            return null; // Skip if no DB configured
        }

        $col = $this->column ?? $field;
        if (str_contains($col, '.')) {
            $parts = explode('.', $col);
            $col = end($parts);
        }

        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE {$col} = :val";
        $params = [':val' => $value];

        if ($this->exceptField !== null) {
            $exceptVal = is_object($context)
                ? ($context->{$this->exceptField} ?? null)
                : ($context[$this->exceptField] ?? null);

            if ($exceptVal !== null) {
                $sql .= " AND id != :except_id";
                $params[':except_id'] = $exceptVal;
            }
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $count = (int) $stmt->fetchColumn();

        if ($count > 0) {
            return Translator::trans('unique', ['field' => $field], $this->message);
        }

        return null;
    }
}
