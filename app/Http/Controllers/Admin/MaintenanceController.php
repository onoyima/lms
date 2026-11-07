<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\LmsNotice;
use App\Services\Audit\AuditLogger;
use App\Support\Maintenance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

/** Planned maintenance windows: staff are told, no exam is booked into one, and none start during it. */
class MaintenanceController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): Response
    {
        $tid = tenant_id();

        return Inertia::render('admin/maintenance', [
            'windows' => DB::table('maintenance_windows')->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tid))
                ->where('ends_at', '>', now()->subDays(30))->orderByDesc('starts_at')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['starts_at' => ['required', 'date', 'after:now'], 'ends_at' => ['required', 'date', 'after:starts_at', 'before:'.now()->addYear()],
            'message' => ['required', 'string', 'max:500'], 'blocks_exams' => ['boolean'], 'notify' => ['boolean']]);
        $clash = DB::table('exam_sessions')->where('tenant_id', tenant_id())->where('is_practice', false)
            ->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])->count();
        if ($clash && ($data['blocks_exams'] ?? true)) {
            return back()->withErrors(['starts_at' => "$clash exam sittings are booked in that time. Move them first (Exam calendar → select → Move)."]);
        }
        $id = DB::table('maintenance_windows')->insertGetId(['tenant_id' => tenant_id(), 'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'], 'message' => $data['message'],
            'blocks_exams' => $data['blocks_exams'] ?? true, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        Maintenance::forget(tenant_id());
        if ($data['notify'] ?? true) {
            Notification::send(User::where('status', 'active')->whereNotNull('tenant_id')->where('user_type', 'staff')->get(),
                new LmsNotice('Planned maintenance', \Carbon\Carbon::parse($data['starts_at'])->format('D j M H:i').'–'.\Carbon\Carbon::parse($data['ends_at'])->format('H:i').': '.$data['message'], category: 'system'));
        }
        $this->audit->record('maintenance.scheduled', null, ['id' => $id] + $data);

        return back()->with('success', 'Maintenance scheduled and announced.');
    }

    public function destroy(int $id): RedirectResponse
    {
        DB::table('maintenance_windows')->where('id', $id)->where('tenant_id', tenant_id())->delete();
        Maintenance::forget(tenant_id());
        $this->audit->record('maintenance.cancelled', null, ['id' => $id]);

        return back()->with('success', 'Maintenance cancelled.');
    }
}
