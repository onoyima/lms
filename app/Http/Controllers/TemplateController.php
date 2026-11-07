<?php

namespace App\Http\Controllers;

use App\Support\Templates;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Sample templates for every import and integration, filtered to what the person may use. */
class TemplateController extends Controller
{
    private function allowed(Request $request): array
    {
        $u = $request->user();
        abort_if($u->user_type === 'student', 403);

        return array_filter(Templates::ALL, fn ($t) => $t[3] === null || $u->can($t[3]));
    }

    public function index(Request $request): Response
    {
        return Inertia::render('learn/templates', ['templates' => collect($this->allowed($request))->map(fn ($t, $k) => ['key' => $k, 'title' => $t[0], 'about' => $t[1], 'file' => $t[2]])->values()]);
    }

    public function download(Request $request, string $key)
    {
        $t = $this->allowed($request)[$key] ?? abort(404);

        return response(Templates::content($key), 200, ['Content-Type' => Templates::mime($t[2]), 'Content-Disposition' => 'attachment; filename="'.$t[2].'"', 'X-Content-Type-Options' => 'nosniff']);
    }
}
