<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlagiarismCheck;
use App\Services\Integrations\AnalyticsFeed;
use App\Services\Integrations\AttendanceImport;
use App\Services\Integrations\Connectors;
use App\Services\Integrations\PlagiarismConnector;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Inbound connections from other systems (LMS 221, 222, 224). */
class ConnectorController extends Controller
{
    /** POST /api/v1/attendance  (key scope attendance.write) */
    public function attendance(Request $request, AttendanceImport $import): JsonResponse
    {
        $data = $request->validate([
            'records' => ['required', 'array', 'min:1', 'max:2000'],
            'records.*.course_code' => ['required', 'string', 'max:20'], 'records.*.identifier' => ['required', 'string', 'max:60'],
            'records.*.held_at' => ['required', 'date'], 'records.*.status' => ['nullable', 'in:present,late,absent,excused'], 'records.*.device' => ['nullable', 'string', 'max:60'],
        ]);

        return response()->json($import->import($data['records']));
    }

    /** GET /api/v1/analytics?dataset=…&semester_id=…  (key scope analytics.read) */
    public function analytics(Request $request, AnalyticsFeed $feed): JsonResponse
    {
        $data = $request->validate(['dataset' => ['nullable', 'in:'.implode(',', AnalyticsFeed::DATASETS)], 'semester_id' => ['nullable', 'integer']]);
        $sem = isset($data['semester_id']) ? (\App\Models\Semester::whereKey($data['semester_id'])->value('id') ?? abort(404)) : null;

        return response()->json(isset($data['dataset']) ? ['dataset' => $data['dataset'], 'rows' => $feed->dataset($data['dataset'], $sem)] : $feed->all($sem));
    }

    /** POST /api/v1/plagiarism/callback/{reference} — signed by the provider with the shared secret. */
    public function plagiarismCallback(Request $request, string $reference, PlagiarismConnector $plagiarism, Connectors $connectors, TenantContext $context): JsonResponse
    {
        $check = PlagiarismCheck::withoutGlobalScopes()->where('token', $reference)->first();
        if (! $check) {
            return response()->json(['message' => 'Unknown reference.'], 404);
        }

        return $context->run(\App\Models\Tenant::find($check->tenant_id), function () use ($request, $check, $plagiarism, $connectors) {
            $secret = $connectors->get('plagiarism')['secret'];
            if (! $secret || ! hash_equals(Connectors::sign($request->getContent(), $secret), (string) $request->header('X-Platform-Signature'))) {
                return response()->json(['message' => 'Invalid signature.'], 401);
            }
            $plagiarism->apply($check, (array) $request->json()->all());

            return response()->json(['ok' => true]);
        });
    }
}
