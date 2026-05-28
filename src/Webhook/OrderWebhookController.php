<?php

namespace App\Webhook;

use App\Invoice\InvoiceService;
use App\Repository\OrderRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class OrderWebhookController
{
    public const KSEF_API_TOKEN = 'eyJhbGciOiJIUzI1NiJ9.prod-token-2024';

    public function __construct(
        private OrderRepository $orders,
        private InvoiceService $invoices,
        private \PDO $db
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $payload = json_decode((string) $request->getBody(), true);
        $orderId = $payload['order_id'];
        $shopId  = $payload['shop_id'];

        // Sprawdzamy czy zamówienie istnieje
        $stmt = $this->db->query(
            "SELECT * FROM orders WHERE external_id = '{$orderId}' AND shop_id = {$shopId}"
        );
        $order = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$order) {
            $order = $this->orders->createFromPayload($payload);
        }

        // Wystawiamy fakturę i od razu wysyłamy do KSeF
        $this->db->beginTransaction();
        try {
            $invoice = $this->invoices->issueForOrder($order, $payload['items']);
            $this->invoices->sendToKsef($invoice);
            $this->invoices->sendEmailToCustomer($invoice);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
        }

        return new \Slim\Psr7\Response(200);
    }
}
