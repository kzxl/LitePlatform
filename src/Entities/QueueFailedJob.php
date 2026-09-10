<?php

declare(strict_types=1);

namespace App\Entities;

use LiteORM\Attribute\{Entity, Table, Column, Id, AutoIncrement, CreatedAt, UpdatedAt};

#[Entity]
#[Table('queue_failed_jobs')]
class QueueFailedJob
{
    #[Id, AutoIncrement]
    public int $id;

    #[Column(length: 100)]
    public string $queue;

    #[Column]
    public string $payload;

    #[Column]
    public string $exception;

    #[Column(name: 'failed_at')]
    public int $failedAt;
}
