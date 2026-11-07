<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Reports\Exporter;
use App\Support\Platform\PlatformSettings;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Branded PDF invoices and receipts. Built from our own records only (the institution's name and logo,
 * the package, amounts, references and the period paid for); the logo is embedded, nothing is fetched.
 */
class BillingDocuments
{
    public function __construct(private Exporter $exporter) {}

    public function bytes(Invoice $invoice): string
    {
        $tenant = Tenant::findOrFail($invoice->tenant_id);
        $payment = Payment::where('invoice_id', $invoice->id)->where('status', 'succeeded')->latest('id')->first();
        $sub = $invoice->subscription_id ? Subscription::with('plan')->find($invoice->subscription_id) : null;
        $logo = null;
        if ($tenant->logo_path && Storage::disk('public')->exists($tenant->logo_path) && Storage::disk('public')->size($tenant->logo_path) < 2_000_000) {
            $mime = Storage::disk('public')->mimeType($tenant->logo_path);
            if (in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
                $logo = 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($tenant->logo_path));
            }
        }

        return $this->exporter->pdfBytes('billing.document', [
            'invoice' => $invoice, 'payment' => $payment, 'institution' => $tenant, 'logo' => $logo, 'brand' => $tenant->brand_color ?: '#1f3a5f',
            'plan' => $sub?->plan?->name, 'cycle' => Billing::CYCLE_LABELS[$sub?->cycle ?? ''] ?? null, 'issuer' => ['billing_name' => PlatformSettings::get('billing_name') ?: PlatformSettings::brand()['name']] + PlatformSettings::all(),
            'money' => fn (int $n) => Billing::money($n, $invoice->currency), 'receipt' => $invoice->status === 'paid' && $payment,
        ]);
    }

    public function download(Invoice $invoice): Response
    {
        $paid = $invoice->status === 'paid';
        $name = ($paid ? 'Receipt-' : 'Invoice-').$invoice->number.'.pdf';

        return response($this->bytes($invoice), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
