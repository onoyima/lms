<?php

namespace App\Http\Controllers\Exam;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Safe Exam Browser for this institution: candidates download it from the institution's own server
 * (so it works on a campus network without internet), and the exams office sets the school's SEB
 * password, which protects every exam's settings file, and the quit password.
 */
class SebController extends Controller
{
    public const SYSTEMS = [
        'windows' => ['label' => 'Windows', 'needs' => 'Windows 10 or 11', 'types' => ['exe', 'msi', 'msix']],
        'macos' => ['label' => 'macOS', 'needs' => 'macOS 11 or later', 'types' => ['dmg', 'pkg']],
        'ios' => ['label' => 'iPad', 'needs' => 'iPadOS 15 or later, from the App Store', 'types' => []],
    ];

    public const OFFICIAL = 'https://safeexambrowser.org/download_en.html';

    private function installers(): array
    {
        return (array) data_get(tenant()->settings, 'seb.installers', []);
    }

    /** For everyone signed in: get SEB for this computer. */
    public function downloads(Request $request): Response
    {
        $have = $this->installers();

        return Inertia::render('seb/downloads', [
            'systems' => collect(self::SYSTEMS)->map(fn ($s, $k) => [
                'key' => $k, 'label' => $s['label'], 'needs' => $s['needs'],
                'file' => isset($have[$k]) ? ['name' => $have[$k]['name'], 'size' => $have[$k]['size'], 'version' => $have[$k]['version'] ?? null] : null,
            ])->values(),
            'official' => self::OFFICIAL,
            'protected' => (bool) data_get(tenant()->settings, 'seb.settings_password'),
            'canManage' => (bool) $request->user()?->can('exams.manage'),
        ]);
    }

    public function installer(string $os): StreamedResponse
    {
        $file = $this->installers()[$os] ?? null;
        abort_unless($file && Storage::disk('local')->exists($file['path']), 404);

        return Storage::disk('local')->download($file['path'], $file['name']);
    }

    /** For the exams office: installers and the school's SEB passwords. */
    public function edit(): Response
    {
        $have = $this->installers();

        return Inertia::render('admin/seb', [
            'systems' => collect(self::SYSTEMS)->map(fn ($s, $k) => [
                'key' => $k, 'label' => $s['label'], 'types' => $s['types'],
                'file' => isset($have[$k]) ? $have[$k] + ['path' => null] : null,
            ])->values(),
            'hasSettingsPassword' => (bool) data_get(tenant()->settings, 'seb.settings_password'),
            'hasQuitPassword' => (bool) data_get(tenant()->settings, 'seb.quit_password'),
            'official' => self::OFFICIAL,
            'maxUploadMb' => (int) floor(min($this->bytes(ini_get('upload_max_filesize')), $this->bytes(ini_get('post_max_size'))) / 1048576),
        ]);
    }

    public function upload(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'os' => ['required', 'in:windows,macos'],
            'file' => ['required', 'file', 'max:1048576'],
            'version' => ['nullable', 'string', 'max:30'],
        ]);
        $ext = strtolower($data['file']->getClientOriginalExtension());
        if (! in_array($ext, self::SYSTEMS[$data['os']]['types'], true)) {
            return back()->withErrors(['file' => 'For '.self::SYSTEMS[$data['os']]['label'].' upload a .'.implode(' or .', self::SYSTEMS[$data['os']]['types']).' installer.']);
        }

        $tenant = tenant();
        $old = $this->installers()[$data['os']]['path'] ?? null;
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $data['file']->getClientOriginalName());
        $path = $data['file']->storeAs("seb/{$tenant->id}/{$data['os']}", $name, 'local');
        if ($old && $old !== $path) {
            Storage::disk('local')->delete($old);
        }

        $settings = $tenant->settings ?? [];
        data_set($settings, "seb.installers.{$data['os']}", [
            'path' => $path, 'name' => $name, 'size' => $data['file']->getSize(), 'version' => $data['version'] ?? null,
            'sha256' => hash_file('sha256', Storage::disk('local')->path($path)), 'uploaded_at' => now()->toIso8601String(),
        ]);
        $tenant->forceFill(['settings' => $settings])->save();
        $audit->record('seb.installer_uploaded', $tenant, ['os' => $data['os'], 'name' => $name]);

        return back()->with('success', 'Installer uploaded. Candidates can download it from Get Safe Exam Browser.');
    }

    public function remove(string $os, AuditLogger $audit): RedirectResponse
    {
        $tenant = tenant();
        $settings = $tenant->settings ?? [];
        if ($path = data_get($settings, "seb.installers.$os.path")) {
            Storage::disk('local')->delete($path);
        }
        unset($settings['seb']['installers'][$os]);
        $tenant->forceFill(['settings' => $settings])->save();
        $audit->record('seb.installer_removed', $tenant, ['os' => $os]);

        return back()->with('success', 'Installer removed.');
    }

    public function passwords(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'settings_password' => ['nullable', 'string', 'min:6', 'max:100'],
            'quit_password' => ['nullable', 'string', 'min:4', 'max:100'],
            'clear' => ['nullable', 'in:settings_password,quit_password'],
        ]);
        $tenant = tenant();
        $settings = $tenant->settings ?? [];
        foreach (['settings_password', 'quit_password'] as $k) {
            if (! empty($data[$k])) {
                data_set($settings, "seb.$k", Crypt::encryptString($data[$k]));
            }
        }
        if (! empty($data['clear'])) {
            unset($settings['seb'][$data['clear']]);
        }
        $tenant->forceFill(['settings' => $settings])->save();
        $audit->record('seb.passwords_changed', $tenant, ['changed' => array_keys(array_filter($data))]);

        return back()->with('success', 'Saved. Settings files downloaded from now on use it.');
    }

    private function bytes(string|false $v): int
    {
        $v = trim((string) $v);
        $n = (int) $v;

        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n,
        } ?: PHP_INT_MAX;
    }
}
