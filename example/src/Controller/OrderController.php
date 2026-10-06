<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\SendInvoice;
use Doctrine\DBAL\Connection;
use Fixwire\Scope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsController]
final class OrderController
{
    public function __construct(private HttpClientInterface $inventoryClient) {}

    #[Route('/orders/{id}', methods: ['GET'])]
    public function show(string $id, Connection $db): JsonResponse
    {
        // A query: a breadcrumb, and a span under the request's.
        return new JsonResponse($db->executeQuery("select ? as id, 'paid' as status", [$id])->fetchAssociative());
    }

    #[Route('/orders', methods: ['POST'])]
    public function create(Request $request, MessageBusInterface $bus): JsonResponse
    {
        $order = json_decode($request->getContent(), true);
        if (!\is_array($order) || !\is_string($order['sku'] ?? null) || !\is_string($order['card'] ?? null)) {
            return new JsonResponse(['error' => 'bad order'], 400); // a 4xx is not reported
        }
        // A traced call to the inventory service, which gets the trace headers.
        $this->inventoryClient->request('POST', '/reservations', ['json' => ['sku' => $order['sku']]])->getContent();
        $id = 'ord_' . bin2hex(random_bytes(4));

        if ($order['card'] === '4000000000000002') { // the test card that is always declined
            // Handled: the customer gets an answer, Fixwire gets the error with the order.
            \Fixwire\withScope(static function (Scope $scope) use ($id, $order): void {
                $scope->setContext('order', ['id' => $id, 'sku' => $order['sku']]);
                \Fixwire\captureException(new \RuntimeException("charging order {$id}", 0, new \DomainException('card_declined')));
            });

            return new JsonResponse(['error' => 'payment declined'], 402);
        }
        $bus->dispatch(new SendInvoice($id)); // carries this request's trace to the worker

        return new JsonResponse(['id' => $id], 201);
    }

    #[Route('/admin/report', methods: ['GET'])]
    public function report(): JsonResponse
    {
        $cents = []; // today's orders: none yet
        // A bug: with no orders this divides by zero. Symfony answers 500; Fixwire reports it.
        return new JsonResponse(['average_cents' => intdiv(array_sum($cents), \count($cents))]);
    }
}
