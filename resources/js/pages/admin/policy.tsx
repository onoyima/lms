import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { Flash, inputClass, PageHeader } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';
import { useRoleLabel } from '@/lib/role-label';

 
type PolicyData = Record<string, any>;
type Contact = { role: string; name: string; phone?: string; email?: string; hours?: string };
type Campus = { name: string; key: string; settings: PolicyData };
type Version = { id: number; scope: string; note: string | null; by: string | null; at: string; changed: string[] };
type Props = { policy: PolicyData; eventTypes: string[]; defaultSeverity: Record<string, number>; proctoringKeys: string[]; faculties: { id: number; name: string }[]; roles: string[]; campuses: Campus[]; campusKeys: string[]; history: Version[] };

/** Who proctors call during an exam (shown on the proctoring console and incident pages). */
function ContactsEditor({ value, onChange }: { value: Contact[]; onChange: (v: Contact[]) => void }) {
    const set = (i: number, k: keyof Contact, v: string) => onChange(value.map((c, n) => (n === i ? { ...c, [k]: v } : c)));

    return (
        <div className="grid gap-2">
            {value.map((c, i) => (
                <div key={i} className="grid gap-2 sm:grid-cols-[1fr_1fr_1fr_1fr_auto]">
                    <input aria-label="Role" className={inputClass} placeholder="Role, e.g. ICT on call" value={c.role} onChange={(e) => set(i, 'role', e.target.value)} />
                    <input aria-label="Name" className={inputClass} placeholder="Name" value={c.name} onChange={(e) => set(i, 'name', e.target.value)} />
                    <input aria-label="Phone" className={inputClass} placeholder="Phone" value={c.phone ?? ''} onChange={(e) => set(i, 'phone', e.target.value)} />
                    <input aria-label="Hours" className={inputClass} placeholder="When, e.g. exam days 7am–6pm" value={c.hours ?? ''} onChange={(e) => set(i, 'hours', e.target.value)} />
                    <Button variant="ghost" size="sm" onClick={() => onChange(value.filter((_, n) => n !== i))}>Remove</Button>
                </div>
            ))}
            <Button variant="outline" size="sm" className="w-fit" onClick={() => onChange([...value, { role: '', name: '' }])}>Add contact</Button>
        </div>
    );
}

function CampusCard({ campus }: { campus: Campus }) {
    const [c, setC] = useState<PolicyData>({ incident_contacts: [], exam_day_notice: '', max_exams_per_day: '', min_attendance_pct: '', ...campus.settings });

    return (
        <section className="surface grid gap-3 p-6">
            <h3 className="font-medium">{campus.name}</h3>
            <p className="text-xs text-muted-foreground">Leave a field empty to use the institution's setting.</p>
            <ContactsEditor value={c.incident_contacts ?? []} onChange={(v) => setC({ ...c, incident_contacts: v })} />
            <textarea className={`${inputClass} h-20 py-2`} placeholder="Exam-day notice for this campus" value={c.exam_day_notice ?? ''} onChange={(e) => setC({ ...c, exam_day_notice: e.target.value })} />
            <div className="flex flex-wrap gap-3 text-sm">
                <label className="grid gap-1">Most exams per student per day<input type="number" min={1} max={4} className={`${inputClass} w-28`} value={c.max_exams_per_day ?? ''} onChange={(e) => setC({ ...c, max_exams_per_day: e.target.value === '' ? null : Number(e.target.value) })} /></label>
                <label className="grid gap-1">Minimum attendance %<input type="number" min={0} max={100} className={`${inputClass} w-28`} value={c.min_attendance_pct ?? ''} onChange={(e) => setC({ ...c, min_attendance_pct: e.target.value === '' ? null : Number(e.target.value) })} /></label>
            </div>
            <Button className="w-fit" onClick={() => router.put(`/admin/policy/campus/${campus.key}`, c as never, { preserveScroll: true })}>Save {campus.name}</Button>
        </section>
    );
}

export default function Policy({ policy, eventTypes, defaultSeverity, proctoringKeys, faculties, roles, campuses, history }: Props) {
    const label = useRoleLabel();
    const [p, setP] = useState<PolicyData>(policy);
    const set = (k: string, v: unknown) => setP({ ...p, [k]: v });
    const [defaultsFor, setDefaultsFor] = useState<string>('type:cbt');
    const [kind, key] = defaultsFor.split(':');
    const bucket = kind === 'type' ? 'exam_type_defaults' : 'faculty_exam_defaults';
    const current: Record<string, unknown> = p[bucket]?.[key] ?? {};
    const setDefault = (k: string, v: unknown) => set(bucket, { ...(p[bucket] ?? {}), [key]: { ...current, [k]: v } });
    const save = () => router.put('/admin/policy', p as never, { preserveScroll: true });

    return (
        <>
            <Head title="Policy" />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader title="University policy" lead="Rules every module follows. Every change is recorded with what it was before." actions={<Button onClick={save}>Save policy</Button>} />
                <Flash />
                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="surface grid content-start gap-3 p-6">
                        <p className="eyebrow">Grading scale</p>
                        <div className="grid grid-cols-[60px_1fr_1fr] gap-2 text-xs text-muted-foreground"><span>Grade</span><span>From %</span><span>Points</span></div>
                        {Object.keys(p.grading_scale).map((g) => (
                            <div key={g} className="grid grid-cols-[60px_1fr_1fr] gap-2">
                                <span className="self-center font-semibold">{g}</span>
                                <input type="number" className={inputClass} value={p.grading_scale[g]} onChange={(e) => set('grading_scale', { ...p.grading_scale, [g]: Number(e.target.value) })} />
                                <input type="number" step="0.5" className={inputClass} value={p.grade_points[g] ?? 0} onChange={(e) => set('grade_points', { ...p.grade_points, [g]: Number(e.target.value) })} />
                            </div>
                        ))}
                        <label className="grid gap-1 text-xs">Lowest passing grade<select className={inputClass} value={p.pass_grade_min} onChange={(e) => set('pass_grade_min', e.target.value)}>{Object.keys(p.grading_scale).map((g) => <option key={g}>{g}</option>)}</select></label>
                    </section>

                    <section className="surface grid content-start gap-3 p-6">
                        <p className="eyebrow">Accounts and sign-in</p>
                        <label className="grid gap-1 text-xs">University email domains (comma-separated; empty allows any)
                            <input className={inputClass} value={(p.email_domains ?? []).join(', ')} onChange={(e) => set('email_domains', e.target.value.split(',').map((s: string) => s.trim()).filter(Boolean))} placeholder="e.g. university.edu.ng, student.university.edu.ng" />
                        </label>
                        <label className="grid gap-1 text-xs">Minimum password length<input type="number" min={8} className={inputClass} value={p.password_min} onChange={(e) => set('password_min', Number(e.target.value))} /></label>
                        <div className="flex flex-wrap gap-4 text-sm">
                            {[['password_mixed_case', 'Upper and lower case'], ['password_numbers', 'Numbers'], ['password_symbols', 'Symbols']].map(([k, l]) => <label key={k} className="flex items-center gap-2"><input type="checkbox" checked={!!p[k]} onChange={(e) => set(k, e.target.checked)} /> {l}</label>)}
                        </div>
                        <p className="text-xs text-muted-foreground">Roles that must use two-factor sign-in</p>
                        <div className="flex flex-wrap gap-2">{roles.map((r) => <button key={r} type="button" onClick={() => set('mfa_required_roles', p.mfa_required_roles.includes(r) ? p.mfa_required_roles.filter((x: string) => x !== r) : [...p.mfa_required_roles, r])} className={`rounded-full border px-3 py-1 text-xs ${p.mfa_required_roles.includes(r) ? 'border-brand bg-brand-soft' : 'border-border'}`}>{label(r)}</button>)}</div>
                        <label className="grid gap-1 text-xs">Access review every (days)<input type="number" className={inputClass} value={p.access_review_days} onChange={(e) => set('access_review_days', Number(e.target.value))} /></label>
                    </section>

                    <section className="surface grid content-start gap-3 p-6">
                        <p className="eyebrow">Registration, eligibility and data</p>
                        <label className="grid gap-1 text-xs">Minimum class attendance to sit an exam (%; empty = no rule)<input type="number" className={inputClass} value={p.min_attendance_pct ?? ''} onChange={(e) => set('min_attendance_pct', e.target.value === '' ? null : Number(e.target.value))} /></label>
                        <label className="grid gap-1 text-xs">Maximum carry-over units per semester<input type="number" className={inputClass} value={p.max_carryover_units} onChange={(e) => set('max_carryover_units', Number(e.target.value))} /></label>
                        <label className="grid gap-1 text-xs">Highest grade a resit can earn (empty = no cap)<input className={inputClass} value={p.resit_max_grade ?? ''} onChange={(e) => set('resit_max_grade', e.target.value === '' ? null : e.target.value.toUpperCase())} /></label>
                        <label className="grid gap-1 text-xs">Most exams one student sits in a day (timetable generator)<input type="number" min={1} max={4} className={inputClass} value={p.max_exams_per_day ?? 2} onChange={(e) => set('max_exams_per_day', Number(e.target.value))} /></label>
                        <label className="grid gap-1 text-xs">Exports of personal data above this many rows need a second person's approval<input type="number" className={inputClass} value={p.export_approval_rows} onChange={(e) => set('export_approval_rows', Number(e.target.value))} /></label>
                    </section>

                    <section className="surface grid content-start gap-3 p-6">
                        <p className="eyebrow">Incident severity (1 low – 5 critical)</p>
                        <div className="grid max-h-80 grid-cols-2 gap-2 overflow-y-auto">
                            {eventTypes.map((t) => (
                                <label key={t} className="flex items-center justify-between gap-2 text-xs">
                                    <span>{t.replace(/_/g, ' ')}</span>
                                    <select className={`${inputClass} w-20`} value={p.severity_overrides?.[t] ?? ''} onChange={(e) => set('severity_overrides', { ...p.severity_overrides, [t]: e.target.value ? Number(e.target.value) : undefined })}>
                                        <option value="">{defaultSeverity[t]} (default)</option>{[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n}</option>)}
                                    </select>
                                </label>
                            ))}
                        </div>
                    </section>

                    <section className="surface grid content-start gap-3 p-6 lg:col-span-2">
                        <p className="eyebrow">Default proctoring rules for new exams</p>
                        <select className={`${inputClass} max-w-sm`} value={defaultsFor} onChange={(e) => setDefaultsFor(e.target.value)}>
                            <optgroup label="By exam type">{['cbt', 'essay', 'mixed', 'practical', 'viva', 'research'].map((t) => <option key={t} value={`type:${t}`}>{t}</option>)}</optgroup>
                            <optgroup label="By faculty (applied after the type)">{faculties.map((f) => <option key={f.id} value={`faculty:${f.id}`}>{f.name}</option>)}</optgroup>
                        </select>
                        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            {proctoringKeys.map((k) => {
                                const v = current[k];

                                return (
                                    <label key={k} className="flex items-center justify-between gap-2 rounded-lg border border-border px-3 py-2 text-xs">
                                        <span>{k.replace(/_/g, ' ')}</span>
                                        {['violation_threshold', 'snapshot_seconds', 'evidence_retention_days'].includes(k)
                                            ? <input type="number" className={`${inputClass} w-24`} value={(v as number) ?? ''} placeholder="default" onChange={(e) => setDefault(k, e.target.value === '' ? undefined : Number(e.target.value))} />
                                            : k === 'identity_check'
                                                ? <select className={`${inputClass} w-36`} value={(v as string) ?? ''} onChange={(e) => setDefault(k, e.target.value || undefined)}><option value="">default</option><option value="none">none</option><option value="photo_match">photo match</option><option value="proctor_review">proctor review</option></select>
                                            : k === 'violation_action'
                                                ? <select className={`${inputClass} w-28`} value={(v as string) ?? ''} onChange={(e) => setDefault(k, e.target.value || undefined)}><option value="">default</option><option value="warn">warn</option><option value="lock">lock</option><option value="submit">submit</option></select>
                                                : <select className={`${inputClass} w-24`} value={v === undefined ? '' : v ? '1' : '0'} onChange={(e) => setDefault(k, e.target.value === '' ? undefined : e.target.value === '1')}><option value="">default</option><option value="1">on</option><option value="0">off</option></select>}
                                    </label>
                                );
                            })}
                        </div>
                    </section>
                </div>
                <section className="surface grid gap-4 p-6">
                    <div>
                        <p className="eyebrow">Exam-day contacts</p>
                        <p className="mt-1 text-sm text-muted-foreground">Proctors and invigilators see these people on the proctoring console and on every incident, with the false-positive and escalation procedure.</p>
                    </div>
                    <ContactsEditor value={p.incident_contacts ?? []} onChange={(v) => set('incident_contacts', v)} />
                    <div className="grid gap-1 border-t pt-4 text-sm">
                        <label className="flex items-center gap-2"><input type="checkbox" checked={!!p.auto_rollover} onChange={(e) => set('auto_rollover', e.target.checked)} /> Close each semester and start the next one automatically, using the dates in the academic calendar</label>
                        <label className="flex items-center gap-2 pl-6"><input type="checkbox" checked={p.auto_rollover_copy_content !== false} disabled={!p.auto_rollover} onChange={(e) => set('auto_rollover_copy_content', e.target.checked)} /> Copy last session's course content into the new semester's courses</label>
                        <p className="text-xs text-muted-foreground">A semester only closes once all its results are released; administrators are told if it is waiting.</p>
                    </div>
                    <label className="grid gap-1 text-sm">Notice shown to candidates before every exam
                        <textarea className={`${inputClass} h-20 py-2`} value={p.exam_day_notice ?? ''} onChange={(e) => set('exam_day_notice', e.target.value)} placeholder="e.g. Bring your ID card. Phones go in the bag at the front of the hall." />
                    </label>
                </section>
                <Button className="w-fit" onClick={save}>Save policy</Button>

                {campuses.length > 0 && (
                    <div className="grid gap-4">
                        <h2 className="text-lg font-medium">Campus settings</h2>
                        <div className="grid gap-4 lg:grid-cols-2">{campuses.map((c) => <CampusCard key={c.key} campus={c} />)}</div>
                    </div>
                )}

                <section className="grid gap-3">
                    <h2 className="text-lg font-medium">History</h2>
                    <p className="text-sm text-muted-foreground">Every saved version is kept. Rolling back saves the old settings as a new version, so it can be undone too.</p>
                    <div className="surface divide-y">
                        {history.length === 0 && <p className="p-4 text-sm text-muted-foreground">No changes yet.</p>}
                        {history.map((v, i) => (
                            <div key={v.id} className="flex flex-wrap items-center justify-between gap-3 p-4 text-sm">
                                <div>
                                    <p className="font-medium">{v.scope === 'policy' ? 'Institution policy' : `Campus: ${v.scope.slice(7).replace(/_/g, ' ')}`} · version {v.id}</p>
                                    <p className="text-xs text-muted-foreground">{new Date(v.at).toLocaleString()} {v.by ? `by ${v.by}` : ''}{v.note ? ` — ${v.note}` : ''}</p>
                                    {v.changed.length > 0 && <p className="mt-1 text-xs">Changed: {v.changed.map((k) => k.replace(/_/g, ' ')).join(', ')}</p>}
                                </div>
                                {i > 0 && <Button size="sm" variant="outline" onClick={() => confirm(`Roll back to version ${v.id}?`) && router.post(`/admin/policy/versions/${v.id}/restore`, {}, { preserveScroll: true })}>Roll back to this</Button>}
                            </div>
                        ))}
                    </div>
                </section>
            </div>
        </>
    );
}
