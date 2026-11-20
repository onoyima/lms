import { Head, router, useForm } from '@inertiajs/react';
import { Download, KeyRound, Trash2, Upload } from 'lucide-react';
import { Field, Flash, inputClass, PageHeader } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type System = { key: string; label: string; types: string[]; file: { name: string; size: number; version: string | null; sha256: string; uploaded_at: string } | null };
type Props = { systems: System[]; hasSettingsPassword: boolean; hasQuitPassword: boolean; official: string; maxUploadMb: number };

function Installer({ s, maxUploadMb }: { s: System; maxUploadMb: number }) {
    const form = useForm<{ os: string; file: File | null; version: string }>({ os: s.key, file: null, version: '' });

    return (
        <section className="surface grid content-start gap-3 p-5">
            <h2 className="font-semibold">{s.label}</h2>
            {s.file ? (
                <div className="rounded-xl bg-foreground/[.04] p-3 text-sm">
                    <p className="font-medium">{s.file.name}{s.file.version ? ` · ${s.file.version}` : ''}</p>
                    <p className="text-xs text-muted-foreground">{(s.file.size / 1048576).toFixed(0)} MB · SHA-256 {s.file.sha256.slice(0, 16)}…</p>
                    <div className="mt-2 flex gap-2">
                        <Button asChild size="sm" variant="outline"><a href={`/safe-exam-browser/${s.key}/download`}><Download className="size-4" /> Test download</a></Button>
                        <Button size="sm" variant="outline" onClick={() => confirm(`Remove the ${s.label} installer?`) && router.delete(`/admin/safe-exam-browser/installers/${s.key}`, { preserveScroll: true })}><Trash2 className="size-4" /> Remove</Button>
                    </div>
                </div>
            ) : (
                <p className="text-sm text-muted-foreground">Not uploaded: candidates are sent to the official download page, which needs the internet.</p>
            )}
            <form className="grid gap-2" onSubmit={(e) => {
                e.preventDefault();
                form.post('/admin/safe-exam-browser/installers', { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset('file', 'version') });
            }}>
                <Field label={`Installer (.${s.types.join(', .')}, up to ${maxUploadMb} MB on this server)`} error={form.errors.file}>
                    <input type="file" accept={s.types.map((t) => `.${t}`).join(',')} className="text-sm" onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)} required />
                </Field>
                <Field label="Version (optional)"><input className={inputClass} value={form.data.version} onChange={(e) => form.setData('version', e.target.value)} placeholder="e.g. 3.8" /></Field>
                <Button className="w-fit" disabled={form.processing || !form.data.file}><Upload className="size-4" /> {form.processing ? `Uploading ${form.progress?.percentage ?? 0}%` : 'Upload'}</Button>
            </form>
        </section>
    );
}

/** The exams office's Safe Exam Browser setup: installers on this server and the school's SEB passwords. */
export default function SebSettings({ systems, hasSettingsPassword, hasQuitPassword, official, maxUploadMb }: Props) {
    const pw = useForm({ settings_password: '', quit_password: '' });

    return (
        <>
            <Head title="Safe Exam Browser" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title="Safe Exam Browser" lead="Upload the installers so candidates can get SEB from this server, even on a campus network without internet. The school's SEB password locks every exam's settings file." />
                <Flash />
                <section className="surface grid gap-4 p-6 md:grid-cols-[1fr_1fr]">
                    <div>
                        <h2 className="flex items-center gap-2 font-semibold"><KeyRound className="size-4" /> School SEB password</h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Every exam's SEB settings file is encrypted with it: SEB asks for it before the exam opens, and it also stops anyone changing SEB's settings on that computer.
                            Candidates then sign in with their own account. An exam can have its own password instead (exam rules).
                        </p>
                        <p className="mt-2 text-sm">{hasSettingsPassword ? 'Set.' : 'Not set: settings files open without a password.'}</p>
                    </div>
                    <form className="grid content-start gap-3" onSubmit={(e) => {
                        e.preventDefault();
                        pw.put('/admin/safe-exam-browser/passwords', { preserveScroll: true, onSuccess: () => pw.reset() });
                    }}>
                        <Field label={hasSettingsPassword ? 'New school SEB password' : 'School SEB password'} error={pw.errors.settings_password}>
                            <input type="password" autoComplete="new-password" className={inputClass} value={pw.data.settings_password} onChange={(e) => pw.setData('settings_password', e.target.value)} />
                        </Field>
                        <Field label={hasQuitPassword ? 'New quit password (to leave SEB early)' : 'Quit password (to leave SEB early)'} error={pw.errors.quit_password}>
                            <input type="password" autoComplete="new-password" className={inputClass} value={pw.data.quit_password} onChange={(e) => pw.setData('quit_password', e.target.value)} />
                        </Field>
                        <div className="flex flex-wrap gap-2">
                            <Button disabled={pw.processing || (!pw.data.settings_password && !pw.data.quit_password)}>Save</Button>
                            {hasSettingsPassword && <Button type="button" variant="outline" onClick={() => confirm('Remove the school SEB password? Settings files will open without one.') && router.put('/admin/safe-exam-browser/passwords', { clear: 'settings_password' }, { preserveScroll: true })}>Remove password</Button>}
                        </div>
                    </form>
                </section>
                <div className="grid gap-4 md:grid-cols-2">
                    {systems.filter((s) => s.types.length > 0).map((s) => <Installer key={s.key} s={s} maxUploadMb={maxUploadMb} />)}
                </div>
                <p className="text-sm text-muted-foreground">
                    Get the installers from <a className="underline" href={official} target="_blank" rel="noreferrer">the Safe Exam Browser project</a>. iPads install it from the App Store.
                    Candidates find everything on <a className="underline" href="/safe-exam-browser">Get Safe Exam Browser</a>.
                </p>
            </div>
        </>
    );
}
