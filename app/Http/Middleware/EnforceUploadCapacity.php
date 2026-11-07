<?php

namespace App\Http\Middleware;

use App\Services\Billing\Entitlements;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every file upload in an institution is checked against its subscription: the largest file allowed and
 * the storage left. Exam evidence (snapshots, identity checks, recordings during a sitting) is never
 * refused here — an exam must not fail because storage is full; it still counts towards usage.
 */
class EnforceUploadCapacity
{
    private const EXEMPT = ['api/exam/attempt/*', 'my/identity/*', 'livekit/*', 'api/livekit/*'];

    public function __construct(private Entitlements $entitlements, private TenantContext $context, private \App\Services\Security\UploadScanner $scanner) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->get();
        // every uploaded file is screened (blocked types, disguised programs, antivirus when configured)
        foreach ($request->is(...self::EXEMPT) ? [] : Arr::dot($request->allFiles()) as $field => $f) {
            if ($f instanceof UploadedFile && $f->isValid()) {
                $this->scanner->check($f->getRealPath(), $f->getClientOriginalName(), (string) preg_replace('/\.\d+$/', '', $field));
            }
        }
        $files = $tenant && ! $request->is(...self::EXEMPT) ? array_filter(Arr::flatten($request->allFiles()), fn ($f) => $f instanceof UploadedFile) : [];
        if ($files) {
            $total = 0;
            foreach ($files as $f) {
                $mb = $f->getSize() / 1048576;
                $this->entitlements->check('upload_mb', $mb, $tenant);
                $total += $mb;
            }
            $this->entitlements->check('storage_mb', $total, $tenant);
        }

        return $next($request);
    }
}
