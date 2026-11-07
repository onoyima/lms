<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Paystack card / bank / USSD payments for invoices. The amount and the invoice come from our
 * records, never from the browser; a payment only counts after Paystack confirms it (verify
 * call or a webhook whose HMAC-SHA512 signature matches our secret key).
 */
class Paystack
{
    public function enabled(): bool
    {
        return (bool) config('platform.paystack.secret');
    }

    public function initialize(Invoice $invoice, string $email, string $callbackUrl): array
    {
        $reference = 'VT'.$invoice->id.'-'.bin2hex(random_bytes(6));
        $res = Http::timeout(15)->withToken((string) config('platform.paystack.secret'))->acceptJson()
            ->post('https://api.paystack.co/transaction/initialize', [
                'email' => $email, 'amount' => $invoice->amount, 'currency' => $invoice->currency, 'reference' => $reference,
                'callback_url' => $callbackUrl, 'metadata' => ['invoice_id' => $invoice->id, 'invoice' => $invoice->number, 'tenant_id' => $invoice->tenant_id],
            ]);
        if (! $res->successful() || ! $res->json('status')) {
            throw new RuntimeException('The payment service did not accept the request. Try again or pay by bank transfer.');
        }

        return ['url' => $res->json('data.authorization_url'), 'reference' => $reference];
    }

    /** @return array{paid: bool, amount: int, currency: string, invoice_id: ?int, data: array} */
    public function verify(string $reference): array
    {
        abort_unless(preg_match('/^VT\d+-[a-f0-9]{12}$/', $reference), 422);
        $res = Http::timeout(15)->withToken((string) config('platform.paystack.secret'))->acceptJson()->get('https://api.paystack.co/transaction/verify/'.rawurlencode($reference));
        $d = (array) $res->json('data');

        return ['paid' => $res->successful() && ($d['status'] ?? '') === 'success', 'amount' => (int) ($d['amount'] ?? 0), 'currency' => (string) ($d['currency'] ?? ''),
            'invoice_id' => isset($d['metadata']['invoice_id']) ? (int) $d['metadata']['invoice_id'] : null, 'data' => array_intersect_key($d, array_flip(['id', 'channel', 'paid_at', 'reference', 'gateway_response']))];
    }

    public function validSignature(string $body, ?string $signature): bool
    {
        return $this->enabled() && $signature && hash_equals(hash_hmac('sha512', $body, (string) config('platform.paystack.secret')), $signature);
    }
}
