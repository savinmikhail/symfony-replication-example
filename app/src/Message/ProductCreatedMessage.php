<?php

declare(strict_types=1);

namespace App\Message;

final class ProductCreatedMessage
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $price,
    ) {
    }
}

