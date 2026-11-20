<?php

declare(strict_types=1);

namespace LiteValidate\Attribute;

use Attribute;
use LiteValidate\Database\DatabaseResolver;
use LiteValidate\I18n\Translator;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class Exists implements RuleInterface
{
    public function __construct(
        public string $table,
        public ?string $column = null,
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
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':val' => $value]);
        $count = (int) $stmt->fetchColumn();

        if ($count === 0) {
            return Translator::trans('exists', ['field' => $field], $this->message);
        }

        return null;
    }
}
