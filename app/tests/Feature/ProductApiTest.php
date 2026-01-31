<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ProductApiTest extends WebTestCase
{
    public function testCreateAndListProducts(): void
    {
        $client = static::createClient();

        $payload = [
            'name' => 'Test product '.uniqid('', true),
            'price' => '9.99',
        ];

        $client->request(
            method: 'POST',
            uri: '/products',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $created = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($created);
        self::assertArrayHasKey('id', $created);
        self::assertIsInt($created['id']);
        self::assertSame($payload['name'], $created['name']);
        self::assertSame($payload['price'], $created['price']);

        $client->request('GET', '/products');

        self::assertResponseIsSuccessful();

        $list = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($list);
        self::assertNotEmpty($list);

        $found = false;

        foreach ($list as $item) {
            if (!is_array($item) || !array_key_exists('id', $item)) {
                continue;
            }

            if ($item['id'] === $created['id']) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'Created product should be present in products list response.');
    }
}

