<?php

namespace App\Http\Middleware;

use App\Services\Modules\ModuleRegistry;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route middleware: ->middleware('module:question_bank') */
class RequireModule
{
    public function __construct(private ModuleRegistry $modules, private TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (! $this->modules->isEnabled($this->context->get(), $module)) {
            abort(403, 'This feature is not part of your institution\'s subscription.');
        }

        return $next($request);
    }
}
