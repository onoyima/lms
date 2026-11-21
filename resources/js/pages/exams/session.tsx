import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Download, Trash2, Users } from 'lucide-react';
import { useState } from 'react';
import { AddStudents } from '@/components/exam/add-students';
import { downloadPost, Field, formatDateTime, inputClass, PageHeader, Status, Table, Td } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/types';

type Candidate = { id: number; name: string; identifier: string | null; status: string; eligibility: string; reason: string | null; extra_time: number; attendance: string; seat: string | null; checked_in: boolean; has_pin: boolean };
type SessionData = {
    id: number; name: string | null; mode: string; status: string; starts_at: string; ends_at: string; late_entry_minutes: number; is_practice: boolean; has_pin: boolean; started: boolean;
    venue: { id: number; name: string; capacity: number } | null; exam: { id: number; title: string; type: string; status: string; kind: string; parent_exam_id: number | null; category?: string };
    proctors: { id: number; user: { id: number; name: string }; role: string; max_candidates: number }[];
};

/** datetime-local value in the viewer's time zone */
const local = (iso: string) => {
    const d = new Date(iso);

    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
};

/** Move the sitting to another time or room; candidates are told. */
function Reschedule({ session, venues }: { session: SessionData; venues: { id: number; name: string }[] }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ name: session.name ?? '', starts_at: local(session.starts_at), ends_at: local(session.ends_at), venue_id: session.venue?.id ?? '', late_entry_minutes: session.late_entry_minutes, notify: true });

    if (!open) {
        return <Button variant="outline" onClick={() => setOpen(true)}>Change time or room</Button>;
    }

    return (
        <form className="surface grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-5" onSubmit={(e) => {
            e.preventDefault();
            form.transform((d) => ({ ...d, starts_at: new Date(d.starts_at).toISOString(), ends_at: new Date(d.ends_at).toISOString(), venue_id: d.venue_id || null }));
            form.put(`/sessions/${session.id}`, { preserveScroll: true, onSuccess: () => setOpen(false) });
        }}>
            <Field label="Starts" error={form.errors.starts_at}><input type="datetime-local" className={inputClass} value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} required /></Field>
            <Field label="Ends" error={form.errors.ends_at}><input type="datetime-local" className={inputClass} value={form.data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} required /></Field>
            {session.mode === 'hall' && (
                <Field label="Room" error={form.errors.venue_id}>
                    <select className={inputClass} value={form.data.venue_id} onChange={(e) => form.setData('venue_id', e.target.value ? Number(e.target.value) : '')}>
                        {venues.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
                    </select>
                </Field>
            )}
            <Field label="Late entry (minutes)" error={form.errors.late_entry_minutes}><input type="number" min={0} max={600} className={inputClass} value={form.data.late_entry_minutes} onChange={(e) => form.setData('late_entry_minutes', Number(e.target.value))} /></Field>
            <div className="flex flex-col justify-end gap-2">
                <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.notify} onChange={(e) => form.setData('notify', e.target.checked)} /> Tell the candidates</label>
                <div className="flex gap-2"><Button disabled={form.processing}>Save</Button><Button type="button" variant="ghost" onClick={() => setOpen(false)}>Cancel</Button></div>
            </div>
        </form>
    );
}

export default function SessionPage({ session, candidates, staff, venues, clashes = [], centres = [], centre }: { centres?: { id: number; name: string; code: string; role: string }[]; centre?: { from_main: boolean; sent_to: number | null }; session: SessionData; candidates: Paginated<Candidate>; clashes?: { user_id: number; name: string; identifier: string | null; other_exam: string; other_starts_at: string; other_session_id: number }[]; venues: { id: number; name: string }[]; staff: { id: number; name: string }[] }) {
    const { flash } = usePage().props;
    const [adding, setAdding] = useState(false);
    const proctor = useForm({ user_id: '', role: 'invigilator', max_candidates: 30 });
    const update = (c: Candidate, data: Record<string, unknown>) => router.put(`/sessions/${session.id}/candidates/${c.id}`, data as never, { preserveScroll: true });

    return (
        <>
            <Head title="Sitting" />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader
                    eyebrow={`${session.exam.title} · ${session.mode}${session.is_practice ? ' · practice' : ''}`}
                    title={session.name || `Sitting #${session.id}`}
                    lead={`${formatDateTime(session.starts_at)} → ${formatDateTime(session.ends_at)} · ${session.venue?.name ?? 'Remote'} · late entry ${session.late_entry_minutes} min${session.has_pin ? ' · PIN required' : ''}`}
                    actions={
                        <>
                            <Button asChild>
                                <Link href={`/proctor/sessions/${session.id}`}>Open console</Link>
                            </Button>
                            <Button variant="outline" onClick={() => confirm('Generate new PINs for every candidate? Old PINs stop working.') && downloadPost(`/sessions/${session.id}/pins`, `exam-slips-${session.id}.csv`)}>
                                <Download className="size-4" /> PINs (CSV)
                            </Button>
                            <Button variant="outline" asChild>
                                <a href={`/sessions/${session.id}/slips`}>
                                    <Download className="size-4" /> Exam slips (PDF)
                                </a>
                            </Button>
                            <Button variant="outline" onClick={() => router.post(`/sessions/${session.id}/recheck`, {}, { preserveScroll: true })}>
                                Re-check eligibility
                            </Button>
                            {session.venue && (
                                <Button variant="outline" onClick={() => router.post(`/sessions/${session.id}/seats`, {}, { preserveScroll: true })}>
                                    Allocate seats
                                </Button>
                            )}
                        </>
                    }
                />
                {flash?.success && <p className="text-sm text-status-good">{flash.success}</p>}
                {session.status !== 'closed' && !session.started && <div><Reschedule session={session} venues={venues} /></div>}
                {clashes.length > 0 && (
                    <div className="surface border-status-critical/50 p-5 text-sm">
                        <p className="eyebrow text-status-critical">Timetable clash · {clashes.length} candidate{clashes.length === 1 ? '' : 's'}</p>
                        <ul className="mt-2 grid gap-1">
                            {clashes.slice(0, 20).map((c) => <li key={`${c.user_id}-${c.other_session_id}`}>{c.name} ({c.identifier}) also sits <strong>{c.other_exam}</strong> at {formatDateTime(c.other_starts_at)}</li>)}
                        </ul>
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-[1fr_360px]">
                    <div className="grid gap-4">
                        <Table head={['Candidate', 'Eligibility', 'Seat', 'Extra time', 'Attendance', '']}>
                            {candidates.data.map((c) => (
                                <tr key={c.id}>
                                    <Td>
                                        <div className="font-medium">{c.name}</div>
                                        <div className="font-mono text-xs text-muted-foreground">{c.identifier}</div>
                                    </Td>
                                    <Td>
                                        <Status value={c.eligibility} />
                                        {c.reason && <div className="mt-1 text-xs text-muted-foreground">{c.reason}</div>}
                                        {c.eligibility === 'ineligible' && (
                                            <button className="mt-1 block text-xs text-brand" onClick={() => update(c, { eligibility: 'override', eligibility_reason: prompt('Reason for override') ?? 'Override' })}>
                                                Override
                                            </button>
                                        )}
                                    </Td>
                                    <Td className="font-mono text-xs">{c.seat ?? '—'}</Td>
                                    <Td>
                                        <input type="number" min={0} className={`${inputClass} h-8 !w-20`} defaultValue={c.extra_time} onBlur={(e) => Number(e.target.value) !== c.extra_time && update(c, { extra_time_minutes: Number(e.target.value) })} aria-label="Extra minutes" />
                                    </Td>
                                    <Td>
                                        <Status value={c.attendance} />
                                    </Td>
                                    <Td>
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            {!c.checked_in ? (
                                                <Button size="sm" variant="outline" onClick={() => router.post(`/sessions/${session.id}/candidates/${c.id}/check-in`, {}, { preserveScroll: true })}>
                                                    Check in
                                                </Button>
                                            ) : (
                                                <span className="text-xs text-status-good">checked in</span>
                                            )}
                                            <Button size="sm" variant="ghost" aria-label={`Take ${c.name} off this sitting`} onClick={() => confirm(`Take ${c.name} off this sitting?`) && router.delete(`/sessions/${session.id}/candidates/${c.id}`, { preserveScroll: true })}>
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </div>
                                    </Td>
                                </tr>
                            ))}
                        </Table>
                        <p className="text-xs text-muted-foreground">{candidates.total} candidates</p>
                    </div>

                    <aside className="grid content-start gap-6">
                        <section className="surface grid gap-3 p-5">
                            <p className="eyebrow flex items-center gap-2">
                                <Users className="size-3.5" /> {session.exam.category === 'promotion' ? 'Add staff to this sitting' : 'Add students to this sitting'}
                            </p>
                            <AddStudents audience={session.exam.category === 'promotion' ? 'staff' : 'student'} busy={adding} send={(body) => router.post(`/sessions/${session.id}/candidates`, body as never, { preserveScroll: true, onStart: () => setAdding(true), onFinish: () => setAdding(false) })} />
                            <div className="grid gap-1.5 border-t border-border pt-3">
                                <Button variant="outline" size="sm" className="w-fit" disabled={adding} onClick={() => router.post(`/sessions/${session.id}/candidates`, { from_enrolment: true }, { preserveScroll: true })}>
                                    Add everyone registered for the course
                                </Button>
                                {session.exam.kind !== 'regular' && session.exam.parent_exam_id && (
                                    <Button variant="outline" size="sm" className="w-fit" disabled={adding} onClick={() => router.post(`/sessions/${session.id}/candidates`, { from_parent: true }, { preserveScroll: true })}>
                                        {session.exam.kind === 'resit' ? 'Add everyone who failed the original exam' : 'Add everyone who failed or missed the original exam'}
                                    </Button>
                                )}
                            </div>
                        </section>

                        <CentrePanel sessionId={session.id} centres={centres} centre={centre} />

                        <section className="surface grid gap-3 p-5">
                            <p className="eyebrow">Proctors & invigilators</p>
                            {session.proctors.map((p) => (
                                <div key={p.id} className="flex items-center justify-between text-sm">
                                    <span>
                                        {p.user.name} <span className="text-xs text-muted-foreground">· {p.role}</span>
                                    </span>
                                    <button className="text-xs text-muted-foreground hover:text-status-critical" onClick={() => router.delete(`/sessions/${session.id}/proctors/${p.id}`, { preserveScroll: true })}>
                                        Remove
                                    </button>
                                </div>
                            ))}
                            <form className="grid gap-2" onSubmit={(e) => (e.preventDefault(), proctor.post(`/sessions/${session.id}/proctors`, { preserveScroll: true }))}>
                                <select className={inputClass} value={proctor.data.user_id} onChange={(e) => proctor.setData('user_id', e.target.value)} required>
                                    <option value="">Choose staff…</option>
                                    {staff.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name}
                                        </option>
                                    ))}
                                </select>
                                <div className="grid grid-cols-2 gap-2">
                                    <select className={inputClass} value={proctor.data.role} onChange={(e) => proctor.setData('role', e.target.value)}>
                                        {['chief', 'invigilator', 'proctor', 'supervisor'].map((r) => (
                                            <option key={r}>{r}</option>
                                        ))}
                                    </select>
                                    <input type="number" min={1} className={inputClass} value={proctor.data.max_candidates} onChange={(e) => proctor.setData('max_candidates', Number(e.target.value))} aria-label="Max candidates" />
                                </div>
                                <Button variant="outline">Assign</Button>
                            </form>
                            <p className="text-xs text-muted-foreground">Candidates are shared out by capacity and reassigned when a proctor is removed.</p>
                        </section>
                    </aside>
                </div>
            </div>
        </>
    );
}

SessionPage.layout = { breadcrumbs: [{ title: 'Exams', href: '/exams' }, { title: 'Sitting', href: '#' }] };

/** Running this sitting at an exam centre without internet: the package out, the scripts back. */
function CentrePanel({ sessionId, centres, centre }: { sessionId: number; centres: { id: number; name: string; code: string; role: string }[]; centre?: { from_main: boolean; sent_to: number | null } }) {
    const outward = centres.filter((c) => c.role === 'centre');
    const mains = centres.filter((c) => c.role === 'main');
    const [pick, setPick] = useState<string>(String(centre?.sent_to ?? outward[0]?.id ?? ''));
    const [file, setFile] = useState<File | null>(null);

    if (!outward.length && !(centre?.from_main && mains.length)) {
        return null;
    }

    return (
        <section className="surface grid gap-3 p-5">
            <p className="eyebrow">Exam centre without internet</p>
            {centre?.from_main && mains.length > 0 ? (
                <>
                    <p className="text-sm text-muted-foreground">This sitting came from the main server. After the exam, send the scripts back.</p>
                    <Button asChild variant="outline"><a href={`/sessions/${sessionId}/centre-results?centre_id=${mains[0].id}`}><Download className="size-4" /> Scripts for the main server</a></Button>
                </>
            ) : (
                <>
                    <select className={inputClass} value={pick} onChange={(e) => setPick(e.target.value)} aria-label="Centre">
                        {outward.map((c) => <option key={c.id} value={c.id}>{c.name} ({c.code})</option>)}
                    </select>
                    <Button asChild variant="outline"><a href={`/sessions/${sessionId}/centre-package?centre_id=${pick}`}><Download className="size-4" /> Package for the centre</a></Button>
                    <label className="flex cursor-pointer items-center gap-2 rounded-lg border border-dashed border-border px-3 py-2 text-sm hover:border-brand/60">
                        <input type="file" className="sr-only" accept=".cbtpkg" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
                        {file ? file.name : 'Choose the scripts file from the centre'}
                    </label>
                    <Button disabled={!file || !pick} onClick={() => file && router.post(`/sessions/${sessionId}/centre-results`, { centre_id: pick, file }, { forceFormData: true, preserveScroll: true })}>Add the centre&apos;s scripts</Button>
                </>
            )}
        </section>
    );
}
