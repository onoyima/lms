<?php

namespace App\Http\Controllers;

use App\Models\InstitutionApplication;
use App\Models\Plan;
use App\Services\Billing\Billing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The public website: what the platform does, prices, and the form to apply. */
class LandingController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('welcome', [
            'plans' => Plan::where('is_public', true)->orderBy('sort')->get()->map(fn (Plan $p) => [
                'id' => $p->id, 'key' => $p->key, 'name' => $p->name, 'tagline' => $p->tagline, 'description' => $p->description,
                'monthly' => $p->price_monthly ? Billing::money((int) $p->price_monthly, $p->currency) : null,
                'yearly' => $p->price_yearly ? Billing::money((int) $p->price_yearly, $p->currency) : null,
                'student_limit' => $p->student_limit, 'features' => $p->features ?? [], 'highlighted' => (bool) $p->highlighted,
            ]),
        ]);
    }

    public function apply(Request $request): RedirectResponse
    {
        // bots fill every field; people never see this one
        if (filled($request->input('website'))) {
            return back()->with('success', 'Thank you. We will be in touch.');
        }
        $data = $request->validate([
            'institution' => ['required', 'string', 'min:3', 'max:190'],
            'slug' => ['required', 'alpha_dash', 'min:2', 'max:40'],
            'contact_name' => ['required', 'string', 'max:190'],
            'contact_email' => ['required', 'email:rfc', 'max:190'],
            'contact_phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9 +()-]+$/'],
            'country' => ['nullable', 'string', 'max:60'],
            'students' => ['nullable', 'integer', 'min:1', 'max:2000000'],
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'cycle' => ['required', \App\Services\Billing\Billing::cycleRule()],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);
        $dupe = InstitutionApplication::where('contact_email', strtolower($data['contact_email']))->where('status', 'pending')->exists();
        if (! $dupe) {
            InstitutionApplication::create(array_merge($data, ['contact_email' => strtolower($data['contact_email']), 'slug' => strtolower($data['slug']),
                'country' => $data['country'] ?? 'Nigeria', 'status' => 'pending', 'ip' => $request->ip()]));
        }

        return back()->with('success', 'Thank you. Your application has been received; the platform team will review it and email you within two working days.');
    }
}
