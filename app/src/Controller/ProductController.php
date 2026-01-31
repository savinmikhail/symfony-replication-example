<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Product;
use App\Message\ProductCreatedMessage;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[Route('/products', name: 'product_')]
class ProductController extends AbstractController
{
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(
        ProductRepository $repository,
        CacheInterface $cache,
    ): JsonResponse {
        $this->simulateLatencyAndFailures();

        $useCache = random_int(1, 100) <= 60;

        if ($useCache) {
            $products = $cache->get(
                'products_latest_50',
                function (ItemInterface $item) use ($repository): array {
                    $item->expiresAfter(5);

                    $entities = $repository->findBy([], ['id' => 'DESC'], 50);

                    return array_map(
                        fn (Product $product): array => $this->normalizeProduct($product),
                        $entities,
                    );
                },
            );
        } else {
            $entities = $repository->findBy([], ['id' => 'DESC'], 50);

            $products = array_map(
                fn (Product $product): array => $this->normalizeProduct($product),
                $entities,
            );
        }

        return $this->json($products);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em, MessageBusInterface $bus): JsonResponse
    {
        $this->simulateLatencyAndFailures();

        $data = json_decode((string) $request->getContent(), true, 512, JSON_THROW_ON_ERROR);

        if (!isset($data['name'], $data['price']) || !is_string($data['name']) || !is_numeric($data['price'])) {
            return $this->json(['error' => 'Invalid payload'], Response::HTTP_BAD_REQUEST);
        }

        $product = new Product($data['name'], (string) $data['price']);

        $em->persist($product);
        $em->flush();

        $bus->dispatch(new ProductCreatedMessage($product->getId(), $product->getName(), $product->getPrice()));

        return $this->json(
            [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'price' => $product->getPrice(),
                'createdAt' => $product->getCreatedAt()->format(\DATE_ATOM),
                'updatedAt' => $product->getUpdatedAt()?->format(\DATE_ATOM),
            ],
            Response::HTTP_CREATED,
        );
    }

    private function simulateLatencyAndFailures(): void
    {
        $appEnv = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null;

        if ($appEnv === 'test') {
            return;
        }

        $roll = random_int(1, 100);

        if ($roll <= 5) {
            usleep(random_int(400000, 1200000));

            throw new \RuntimeException('Random failure for observability demo');
        }

        if ($roll <= 35) {
            usleep(random_int(50000, 400000));
        }
    }

    private function normalizeProduct(Product $product): array
    {
        return [
            'id' => $product->getId(),
            'name' => $product->getName(),
            'price' => $product->getPrice(),
            'createdAt' => $product->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $product->getUpdatedAt()?->format(\DATE_ATOM),
        ];
    }
}
