<?php

namespace App\Http\Controllers\Exam;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Seat;
use App\Models\Venue;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** CBT halls, seats and approved exam computers (Target 44, 47, 97, 99, 163, 169). */
class VenueController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): Response
    {
        return Inertia::render('exams/venues', [
            'venues' => Venue::withCount('seats')->orderBy('name')->get(),
            'devices' => Device::with('venue:id,name')->latest('id')->limit(500)->get(['id', 'venue_id', 'label', 'status', 'last_seen_at', 'public_key']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'], 'campus' => ['nullable', 'string', 'max:190'],
            'capacity' => ['required', 'integer', 'min:1', 'max:5000'], 'network_cidr' => ['nullable', 'string', 'max:64'],
            'seat_prefix' => ['nullable', 'string', 'max:3'],
        ]);
        $venue = Venue::create($data);
        $rows = [];
        for ($i = 1; $i <= $venue->capacity; $i++) {
            $rows[] = ['venue_id' => $venue->id, 'label' => ($data['seat_prefix'] ?? 'S').str_pad((string) $i, 3, '0', STR_PAD_LEFT)];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            Seat::insert($chunk);
        }
        $this->audit->record('venue.created', $venue);

        return back()->with('success', "Venue created with {$venue->capacity} seats.");
    }

    public function updateDevice(Request $request, Device $device): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:pending,approved,blocked'], 'venue_id' => ['nullable', 'integer'], 'label' => ['nullable', 'string', 'max:100']]);
        $device->update(array_filter($data, fn ($v) => $v !== null));
        $this->audit->record('device.'.$data['status'], $device);

        return back();
    }

    /**
     * Desktop app enrolment: the app generates an Ed25519 key pair on first run and sends
     * its public key. The device stays "pending" until an ICT officer approves it.
     */
    public function enrol(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'min:16', 'max:128'],
            'public_key' => ['required', 'string', 'max:64'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);
        abort_unless(strlen((string) base64_decode($data['public_key'], true)) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 422, 'Invalid public key.');
        $device = Device::firstOrCreate(
            ['fingerprint_hash' => hash('sha256', $data['device_id'])],
            ['label' => $data['label'] ?? ('Device '.substr(hash('sha256', $data['device_id']), 0, 6)), 'public_key' => $data['public_key'], 'status' => 'pending']
        );

        return response()->json(['status' => $device->status, 'label' => $device->label], $device->wasRecentlyCreated ? 201 : 200);
    }
}
