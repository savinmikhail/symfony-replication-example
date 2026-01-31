<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ProductCreatedMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ProductCreatedMessageHandler
{
    public function __invoke(ProductCreatedMessage $message): void
    {
        $roll = random_int(1, 100);

        if ($roll <= 10) {
            usleep(random_int(300000, 1000000));

            throw new \RuntimeException('Random failure in ProductCreatedMessageHandler');
        }

        if ($roll <= 40) {
            usleep(random_int(50000, 300000));
        }

        // In a real app we might, for example, send an email or update a search index here.
    }
}

