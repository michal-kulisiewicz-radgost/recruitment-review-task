<?php

namespace App\Invoice;

use App\Entity\Invoice;
use App\Webhook\OrderWebhookController;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;

class InvoiceService
{
    private Client $http;

    public function __construct(
        private EntityManagerInterface $em,
        private \PHPMailer\PHPMailer\PHPMailer $mailer
    ) {
        $this->http = new Client(['base_uri' => 'https://ksef.mf.gov.pl/api/']);
    }

    public function issueForOrder(array $order, array $items): Invoice
    {
        $invoice = new Invoice();
        $invoice->setOrderId($order['id']);
        $invoice->setNumber($this->nextInvoiceNumber());

        $total = 0.0;
        foreach ($items as $item) {
            // Doładuj dane produktu z bazy (potrzebne VAT i nazwa)
            $product = $this->em->getRepository(\App\Entity\Product::class)
                ->find($item['product_id']);

            $line = new \App\Entity\InvoiceLine();
            $line->setName($product->getName());
            $line->setQty($item['qty']);
            $line->setPrice($item['price']);
            $line->setVatRate($product->getVatRate());

            $total += $item['qty'] * $item['price'];
            $invoice->addLine($line);
        }

        $invoice->setTotal($total);
        $this->em->persist($invoice);
        $this->em->flush();

        return $invoice;
    }

    public function sendToKsef(Invoice $invoice): void
    {
        $xml = $this->buildKsefXml($invoice);

        error_log("Wysyłka KSeF: " . $xml);

        $response = $this->http->post('invoices/send', [
            'headers' => [
                'Authorization' => 'Bearer ' . OrderWebhookController::KSEF_API_TOKEN,
                'Content-Type'  => 'application/xml',
            ],
            'body' => $xml,
        ]);

        if ($response->getStatusCode() === 200) {
            $invoice->setKsefStatus('sent');
            $invoice->setKsefReference(json_decode($response->getBody(), true)['reference']);
            $this->em->flush();
        }
    }

    public function sendEmailToCustomer(Invoice $invoice): void
    {
        $pdf = $this->renderPdf($invoice);

        $this->mailer->addAddress($invoice->getCustomerEmail());
        $this->mailer->Subject = 'Faktura ' . $invoice->getNumber();
        $this->mailer->Body = 'W załączniku faktura.';
        $this->mailer->addStringAttachment($pdf, $invoice->getNumber() . '.pdf');
        $this->mailer->send();
    }

    private function nextInvoiceNumber(): string
    {
        $last = $this->em->createQuery(
            'SELECT MAX(i.number) FROM App\Entity\Invoice i'
        )->getSingleScalarResult();

        return 'FV/' . date('Y/m') . '/' . ((int)$last + 1);
    }

    private function buildKsefXml(Invoice $invoice): string
    {
        // Pomijamy szczegóły budowy XML — to nie jest przedmiot review
        return '<?xml version="1.0"?><Faktura>...</Faktura>';
    }

    private function renderPdf(Invoice $invoice): string
    {
        // Pomijamy szczegóły renderowania PDF
        return '%PDF-1.4 ...';
    }
}
