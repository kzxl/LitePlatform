<?php

declare(strict_types=1);

namespace App\Entities;

use LiteORM\Attribute\{Entity, Table, Column, Id, AutoIncrement, CreatedAt, UpdatedAt};

#[Entity]
#[Table('queue_jobs')]
class QueueJob
{
    #[Id, AutoIncrement]
    public int $id;

    #[Column(length: 100)]
    public string $queue;

    #[Column]
    public string $payload;

    #[Column]
    public int $attempts;

    #[Column(name: 'reserved_at', nullable: true)]
    public ?int $reservedAt = null;

    #[Column(name: 'available_at')]
    public int $availableAt;

    #[CreatedAt]
    #[Column(name: 'created_at')]
    public int $createdAt;
}
