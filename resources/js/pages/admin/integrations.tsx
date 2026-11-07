import { Head, router, useForm } from '@inertiajs/react';
import { Copy } from 'lucide-react';
import { Field, Flash, formatDateTime, inputClass, PageHeader, Status, Table, Td } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Key = { id: number; name: string; prefix: string; scopes: string[]; allowed_ips: string[] | null; last_used_at: string | null; expires_at: string | null; revoked_at: string | null; created_at: string };
type Conn = { enabled: boolean; name?: string; url: string; auto?: boolean; urgent_only?: boolean; has_secret: boolean };
type MailConn = { enabled: boolean; host: string; port: number; encryption: string; username: string; from_address: string; from_name: string; has_secret: boolean };
type DirConn = { enabled: boolean; url: string; client_id: string; include_applicants: boolean; deactivate_missing: boolean; follow_current_semester: boolean; sso: boolean; sso_provider_id: number | null; has_secret: boolean };
type DirSummary = { ok: boolean; error: string | null; created: number; updated: number; failed: number; staff: number; students: number; applicants: number; enrolments: number; enrolments_dropped: number;
    teaching: number; teaching_removed: number; switched_off: number; switched_on: number; kept_suspended: number; current_semester: string | null; warnings: string[]; seconds: number };
type DirRun = { id: number; at: string; summary: DirSummary | null; errors: { id: string | null; message: string }[] };
type DirTest = { institution: string | null; semesters: { id: number; session: string; semester: string; programme: string | null }[]; counts: Record<string, number> };
type Props = { keys: Key[]; scopes: Record<string, string>; plagiarism: Conn; analytics: Conn; mail: MailConn; notify: Conn; callback: string; apiBase: string; newKey: string | null;
    directory: DirConn; directoryRuns: DirRun[]; directoryTest: DirTest | null };

/** Connections to other systems: API keys, the plagiarism service and the analytics feed (LMS 221, 222, 224). */
export default function Integrations({ keys, scopes, plagiarism, analytics, mail, notify, callback, apiBase, newKey, directory, directoryRuns, directoryTest }: Props) {
    const key = useForm<{ name: string; scopes: string[]; ips: string; days: number }>({ name: '', scopes: ['attendance.write'], ips: '', days: 365 });
    const toggle = (s: string) => key.setData('scopes', key.data.scopes.includes(s) ? key.data.scopes.filter((x) => x !== s) : [...key.data.scopes, s]);

    return (
        <>
            <Head title="Integrations" />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader title="Integrations" lead="Connect the student information system, attendance terminals, the plagiarism service and your reporting warehouse. Every key and setting change is in the audit log." />
                <Flash />

                {newKey && (
                    <div role="status" className="rounded-lg border-2 border-brand p-5">
                        <p className="font-medium">Copy this key now. It is not shown again.</p>
                        <div className="mt-2 flex items-center gap-2">
                            <code className="flex-1 overflow-x-auto rounded-lg bg-muted px-3 py-2 text-sm">{newKey}</code>
                            <Button size="sm" variant="outline" onClick={() => navigator.clipboard.writeText(newKey)}><Copy className="size-4" /> Copy</Button>
                        </div>
                    </div>
                )}

                <DirectoryForm conn={directory} runs={directoryRuns} test={directoryTest} />

                <section className="grid gap-4">
                    <h2 className="text-lg font-medium">API keys</h2>
                    <p className="text-sm text-muted-foreground">Systems send the key as <code>Authorization: Bearer vk_…</code> to {apiBase}. Attendance terminals post to <code>/attendance</code>; reporting tools read <code>/analytics</code>. Sample payloads: <a className="text-brand underline" href="/templates">templates</a>.</p>
                    <form className="surface grid gap-3 p-5 md:grid-cols-3" onSubmit={(e) => {
 e.preventDefault(); key.post('/admin/integrations/keys', { preserveScroll: true, onSuccess: () => key.reset() }); 
}}>
                        <Field label="Name" error={key.errors.name}><input className={inputClass} value={key.data.name} onChange={(e) => key.setData('name', e.target.value)} required placeholder="e.g. Main gate card readers" /></Field>
                        <Field label="Allowed addresses (optional)" hint="IP or range, e.g. 10.20.0.0/16" error={key.errors.ips}><input className={inputClass} value={key.data.ips} onChange={(e) => key.setData('ips', e.target.value)} /></Field>
                        <Field label="Expires after (days)" error={key.errors.days}><input type="number" min={1} max={730} className={inputClass} value={key.data.days} onChange={(e) => key.setData('days', Number(e.target.value))} /></Field>
                        <div className="grid gap-1.5 md:col-span-3">
                            {Object.entries(scopes).map(([k, v]) => (
                                <label key={k} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={key.data.scopes.includes(k)} onChange={() => toggle(k)} /> <code className="text-xs">{k}</code> {v}</label>
                            ))}
                            {key.errors.scopes && <p className="text-xs text-destructive">{key.errors.scopes}</p>}
                        </div>
                        <Button className="w-fit" disabled={key.processing}>Create key</Button>
                    </form>
                    <Table head={['Name', 'Key', 'Can', 'Last used', 'Expires', '']}>
                        {keys.length === 0 && <tr><Td className="text-muted-foreground">No keys yet.</Td></tr>}
                        {keys.map((k) => (
                            <tr key={k.id}>
                                <Td className="font-medium">{k.name}{k.allowed_ips && <span className="block text-xs text-muted-foreground">{k.allowed_ips.join(', ')}</span>}</Td>
                                <Td className="font-mono text-xs">vk_{k.prefix}_…</Td>
                                <Td className="text-xs">{k.scopes.join(', ')}</Td>
                                <Td>{formatDateTime(k.last_used_at)}</Td>
                                <Td>{k.revoked_at ? <Status value="cancelled" /> : formatDateTime(k.expires_at)}</Td>
                                <Td>{!k.revoked_at && <Button size="sm" variant="ghost" onClick={() => confirm(`Revoke ${k.name}? Systems using it stop at once.`) && router.delete(`/admin/integrations/keys/${k.id}`, { preserveScroll: true })}>Revoke</Button>}</Td>
                            </tr>
                        ))}
                    </Table>
                </section>

                <div className="grid gap-6 lg:grid-cols-2">
                    <ConnectorForm name="plagiarism" title="Plagiarism service" conn={plagiarism}
                        help={<>Submissions are sent as signed JSON to the address below; the service replies with a score or calls back to <code className="break-all">{callback}</code>. Lecturers see the score next to the built-in similarity check.</>} />
                    <ConnectorForm name="analytics" title="Analytics feed" conn={analytics}
                        help={<>Course-level enrolment, results, attendance, exam and engagement figures are sent every night at 04:40, signed with the shared secret. No names or matriculation numbers are included.</>} />
                    <MailForm conn={mail} />
                    <ConnectorForm name="notify" title="Notification service" conn={notify}
                        help={<>A signed copy of every notice (title, text, link and the person's name, e-mail and matriculation number) is sent to your SMS gateway, Teams or Slack bridge or campus app.</>} />
                </div>
            </div>
        </>
    );
}

function ConnectorForm({ name, title, conn, help }: { name: 'plagiarism' | 'analytics' | 'notify'; title: string; conn: Conn; help: React.ReactNode }) {
    const f = useForm({ enabled: conn.enabled, name: conn.name ?? '', url: conn.url, secret: '', auto: conn.auto ?? false, urgent_only: conn.urgent_only ?? false });

    return (
        <form className="surface grid content-start gap-3 p-6" onSubmit={(e) => {
 e.preventDefault(); f.put(`/admin/integrations/${name}`, { preserveScroll: true, onSuccess: () => f.setData('secret', '') }); 
}}>
            <div className="flex items-center justify-between"><h2 className="text-lg font-medium">{title}</h2><Status value={conn.enabled ? 'active' : 'inactive'} /></div>
            <p className="text-sm text-muted-foreground">{help}</p>
            {name === 'plagiarism' && <Field label="Name shown to lecturers" error={f.errors.name}><input className={inputClass} value={f.data.name} onChange={(e) => f.setData('name', e.target.value)} placeholder="e.g. Turnitin" /></Field>}
            <Field label="Service address (https)" error={f.errors.url}><input type="url" className={inputClass} value={f.data.url} onChange={(e) => f.setData('url', e.target.value)} /></Field>
            <Field label="Shared secret" hint={conn.has_secret ? 'Leave empty to keep the current secret' : 'At least 24 characters, agreed with the service'} error={f.errors.secret}>
                <input type="password" autoComplete="new-password" className={inputClass} value={f.data.secret} onChange={(e) => f.setData('secret', e.target.value)} />
            </Field>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={f.data.enabled} onChange={(e) => f.setData('enabled', e.target.checked)} /> Switched on</label>
            {name === 'plagiarism' && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={f.data.auto} onChange={(e) => f.setData('auto', e.target.checked)} /> Check every submission automatically</label>}
            {name === 'notify' && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={f.data.urgent_only} onChange={(e) => f.setData('urgent_only', e.target.checked)} /> Only urgent notices (exam changes, warnings, suspensions)</label>}
            <div className="flex gap-2">
                <Button disabled={f.processing}>Save</Button>
                {name === 'analytics' && conn.enabled && <Button type="button" variant="outline" onClick={() => router.post('/admin/integrations/analytics/push', {}, { preserveScroll: true })}>Send now</Button>}
            </div>
        </form>
    );
}

/** The institution's records system through its directory bridge: people, courses, registrations and sign-in. */
function DirectoryForm({ conn, runs, test }: { conn: DirConn; runs: DirRun[]; test: DirTest | null }) {
    const f = useForm({ enabled: conn.enabled, url: conn.url, client_id: conn.client_id, secret: '', include_applicants: conn.include_applicants,
        deactivate_missing: conn.deactivate_missing, follow_current_semester: conn.follow_current_semester, sso: conn.sso });
    const last = runs[0]?.summary ?? null;
    const held = (last?.warnings ?? []).some((w) => w.startsWith('Did not switch off'));
    const check = (key: 'enabled' | 'sso' | 'follow_current_semester' | 'deactivate_missing' | 'include_applicants', label: string, hint?: string) => (
        <label className="flex items-start gap-2 text-sm">
            <input type="checkbox" className="mt-1" checked={f.data[key]} onChange={(e) => f.setData(key, e.target.checked)} />
            <span>{label}{hint && <span className="block text-xs text-muted-foreground">{hint}</span>}</span>
        </label>
    );

    return (
        <section className="surface grid gap-5 p-6" aria-labelledby="directory-title">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="max-w-3xl">
                    <h2 id="directory-title" className="text-lg font-medium">University portal: records and sign-in</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Bring in active staff, students and applicants, courses, the running semester, course registrations and who teaches what from your records system, through its
                        directory bridge. Every hour, and whenever you press Sync now. People who stop being active in the portal are switched off here; nothing is ever deleted.
                    </p>
                </div>
                <Status value={conn.enabled ? 'active' : 'inactive'} />
            </div>
            <form className="grid gap-4" onSubmit={(e) => {
                e.preventDefault(); f.put('/admin/integrations/directory', { preserveScroll: true, onSuccess: () => f.setData('secret', '') });
            }}>
                <div className="grid gap-3 md:grid-cols-3">
                    <Field label="Bridge address" hint="e.g. https://portal.university.edu.ng/bridge/vuna" error={f.errors.url}>
                        <input type="url" className={inputClass} value={f.data.url} onChange={(e) => f.setData('url', e.target.value)} />
                    </Field>
                    <Field label="Client ID" error={f.errors.client_id}><input className={inputClass} value={f.data.client_id} onChange={(e) => f.setData('client_id', e.target.value)} autoComplete="off" /></Field>
                    <Field label="Client secret" hint={conn.has_secret ? 'Leave empty to keep the current secret' : 'Issued by the bridge (at least 16 characters)'} error={f.errors.secret}>
                        <input type="password" autoComplete="new-password" className={inputClass} value={f.data.secret} onChange={(e) => f.setData('secret', e.target.value)} />
                    </Field>
                </div>
                <div className="grid gap-2 md:grid-cols-2">
                    {check('enabled', 'Switched on')}
                    {check('sso', 'People sign in on the normal sign-in page with their portal ID and password', 'Matric, registration or staff number, or e-mail, with their portal password. The portal checks the password; this platform never stores it. No extra button is shown.')}
                    {check('follow_current_semester', "Follow the portal's current semester", 'The semester most students are registered in becomes the current one here.')}
                    {check('deactivate_missing', 'Switch off people who are no longer active in the portal', 'Deferred, suspended, withdrawn or graduated students and staff whose appointment ended lose access; they come back on if the portal makes them active again.')}
                    {check('include_applicants', 'Also bring in applicants of the current admission session', 'For screening exams. Applicants can only sit the exams they are added to.')}
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button disabled={f.processing}>Save</Button>
                    {conn.url && <Button type="button" variant="outline" onClick={() => router.post('/admin/integrations/directory/test', {}, { preserveScroll: true })}>Test connection</Button>}
                    {conn.enabled && <Button type="button" variant="outline" onClick={() => router.post('/admin/integrations/directory/sync', {}, { preserveScroll: true })}>Sync now</Button>}
                    {conn.enabled && held && (
                        <Button type="button" variant="ghost" onClick={() => confirm('Switch off everyone the portal no longer lists as active, even though it is an unusually large number?') && router.post('/admin/integrations/directory/sync', { force: true }, { preserveScroll: true })}>
                            Sync and switch off anyway
                        </Button>
                    )}
                </div>
            </form>

            {test && (
                <div role="status" className="rounded-xl border border-border p-4 text-sm">
                    <p className="font-medium">{test.institution ?? 'The bridge'} answered</p>
                    <p className="mt-1 text-muted-foreground">
                        {test.semesters.length ? `Running now: ${test.semesters.map((s) => `${s.session} ${s.semester}${s.programme ? ` (${s.programme})` : ''}`).join('; ')}.` : 'No semester is marked as running in the portal.'}
                        {' '}Active: {test.counts.active_students ?? 0} students, {test.counts.active_staff ?? 0} staff.
                    </p>
                </div>
            )}

            {runs.length > 0 && (
                <div className="grid gap-2">
                    <h3 className="text-sm font-medium">Recent syncs</h3>
                    <Table head={['When', 'Result', 'People', 'Registrations', 'Teaching', 'Switched off / on', 'Problems']}>
                        {runs.map((r) => (
                            <tr key={r.id}>
                                <Td>{formatDateTime(r.at)}</Td>
                                <Td>{r.summary?.ok ? <Status value="active" /> : <span className="text-status-critical">{r.summary?.error ?? 'Stopped'}</span>}{r.summary?.current_semester && <span className="block text-xs text-muted-foreground">Current: {r.summary.current_semester}</span>}</Td>
                                <Td className="text-xs">{r.summary ? `${r.summary.staff} staff, ${r.summary.students} ${r.summary.students === 1 ? 'student' : 'students'}${r.summary.applicants ? `, ${r.summary.applicants} ${r.summary.applicants === 1 ? 'applicant' : 'applicants'}` : ''} (${r.summary.created} new, ${r.summary.updated} changed)` : '—'}</Td>
                                <Td className="text-xs">{r.summary ? `${r.summary.enrolments} active, ${r.summary.enrolments_dropped} dropped` : '—'}</Td>
                                <Td className="text-xs">{r.summary ? `${r.summary.teaching} assigned, ${r.summary.teaching_removed} removed` : '—'}</Td>
                                <Td className="text-xs">{r.summary ? `${r.summary.switched_off} / ${r.summary.switched_on}${r.summary.kept_suspended ? ` (${r.summary.kept_suspended} kept suspended)` : ''}` : '—'}</Td>
                                <Td className="text-xs">{r.summary?.failed ?? 0}</Td>
                            </tr>
                        ))}
                    </Table>
                    {(last?.warnings ?? []).map((w) => <p key={w} className="text-sm text-status-warning">{w}</p>)}
                    {runs[0].errors.length > 0 && (
                        <details className="text-sm">
                            <summary className="cursor-pointer">Problems in the last sync ({runs[0].errors.length})</summary>
                            <ul className="mt-2 grid gap-1 text-xs text-muted-foreground">{runs[0].errors.map((e, i) => <li key={i}><span className="font-mono">{e.id}</span>: {e.message}</li>)}</ul>
                        </details>
                    )}
                </div>
            )}
        </section>
    );
}

/** The institution's own mail server (LMS 220). */
function MailForm({ conn }: { conn: MailConn }) {
    const f = useForm({ enabled: conn.enabled, host: conn.host, port: conn.port, encryption: conn.encryption, username: conn.username, secret: '', from_address: conn.from_address, from_name: conn.from_name });

    return (
        <form className="surface grid content-start gap-3 p-6" onSubmit={(e) => {
 e.preventDefault(); f.put('/admin/integrations/mail', { preserveScroll: true, onSuccess: () => f.setData('secret', '') }); 
}}>
            <div className="flex items-center justify-between"><h2 className="text-lg font-medium">Institution e-mail</h2><Status value={conn.enabled ? 'active' : 'inactive'} /></div>
            <p className="text-sm text-muted-foreground">Send all e-mail from your own server and address (Microsoft 365, Google Workspace or your campus mail server). Leave off to use the platform's mail service.</p>
            <div className="grid gap-3 sm:grid-cols-[1fr_100px_110px]">
                <Field label="Mail server (SMTP)" error={f.errors.host}><input className={inputClass} value={f.data.host} onChange={(e) => f.setData('host', e.target.value)} placeholder="smtp.office365.com" /></Field>
                <Field label="Port" error={f.errors.port}><select className={inputClass} value={f.data.port} onChange={(e) => f.setData('port', Number(e.target.value))}>{[587, 465, 25, 2525].map((p) => <option key={p}>{p}</option>)}</select></Field>
                <Field label="Security"><select className={inputClass} value={f.data.encryption} onChange={(e) => f.setData('encryption', e.target.value)}><option value="tls">STARTTLS</option><option value="ssl">SSL</option><option value="none">None</option></select></Field>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Username" error={f.errors.username}><input className={inputClass} value={f.data.username} onChange={(e) => f.setData('username', e.target.value)} autoComplete="off" /></Field>
                <Field label="Password" hint={conn.has_secret ? 'Leave empty to keep the current one' : undefined} error={f.errors.secret}><input type="password" autoComplete="new-password" className={inputClass} value={f.data.secret} onChange={(e) => f.setData('secret', e.target.value)} /></Field>
                <Field label="Send from (address)" error={f.errors.from_address}><input type="email" className={inputClass} value={f.data.from_address} onChange={(e) => f.setData('from_address', e.target.value)} placeholder="exams@uni.edu.ng" /></Field>
                <Field label="Send from (name)" error={f.errors.from_name}><input className={inputClass} value={f.data.from_name} onChange={(e) => f.setData('from_name', e.target.value)} placeholder="Exams Office" /></Field>
            </div>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={f.data.enabled} onChange={(e) => f.setData('enabled', e.target.checked)} /> Switched on</label>
            <div className="flex gap-2">
                <Button disabled={f.processing}>Save</Button>
                {conn.enabled && <Button type="button" variant="outline" onClick={() => router.post('/admin/integrations/mail/test', {}, { preserveScroll: true })}>Send me a test</Button>}
            </div>
        </form>
    );
}

Integrations.layout = { breadcrumbs: [{ title: 'Integrations', href: '/admin/integrations' }] };
