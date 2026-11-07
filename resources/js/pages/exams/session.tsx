import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Download, Users } from 'lucide-react';
import { useState } from 'react';
import { downloadPost, Field, formatDateTime, inputClass, PageHeader, Status, Table, Td, textareaClass } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/types';

type Candidate = { id: number; name: string; identifier: string | null; status: string; eligibility: string; reason: string | null; extra_time: number; attendance: string; seat: string | null; checked_in: boolean; has_pin: boolean };
type SessionData = {
    id: number; name: string | null; mode: string; status: string; starts_at: string; ends_at: string; late_entry_minutes: number; is_practice: boolean; has_pin: boolean; started: boolean;
    venue: { id: number; name: string; capacity: number } | null; exam: { id: number; title: string; type: string; status: string; kind: string; parent_exam_id: number | null };
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

export default function SessionPage({ session, candidates, staff, venues, clashes = [] }: { session: SessionData; candidates: Paginated<Candidate>; clashes?: { user_id: number; name: string; identifier: string | null; other_exam: string; other_starts_at: string; other_session_id: number }[]; venues: { id: number; name: string }[]; staff: { id: number; name: string }[] }) {
    const { flash } = usePage().props;
    const add = useForm({ identifiers: '', from_enrolment: false, from_parent: false });
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
                                        <input type="number" min={0} className={`${inputClass} h-8 w-20`} defaultValue={c.extra_time} onBlur={(e) => Number(e.target.value) !== c.extra_time && update(c, { extra_time_minutes: Number(e.target.value) })} aria-label="Extra minutes" />
                                    </Td>
                                    <Td>
                                        <Status value={c.attendance} />
                                    </Td>
                                    <Td>
                                        {!c.checked_in ? (
                                            <Button size="sm" variant="outline" onClick={() => router.post(`/sessions/${session.id}/candidates/${c.id}/check-in`, {}, { preserveScroll: true })}>
                                                Check in
                                            </Button>
                                        ) : (
                                            <span className="text-xs text-status-good">checked in</span>
                                        )}
                                    </Td>
                                </tr>
                            ))}
                        </Table>
                        <p className="text-xs text-muted-foreground">{candidates.total} candidates</p>
                    </div>

                    <aside className="grid content-start gap-6">
                        <form className="surface grid gap-3 p-5" onSubmit={(e) => (e.preventDefault(), add.post(`/sessions/${session.id}/candidates`, { preserveScroll: true, onSuccess: () => add.reset() }))}>
                            <p className="eyebrow flex items-center gap-2">
                                <Users className="size-3.5" /> Add candidates
                            </p>
                            <p className="text-xs text-muted-foreground"><a className="text-brand underline" href="/templates/candidates">Sample list</a></p>
                            <Field label="Matric numbers or emails" hint="Paste a column from Excel; one per line">
                                <textarea className={`${textareaClass} font-mono`} value={add.data.identifiers} onChange={(e) => add.setData('identifiers', e.target.value)} />
                            </Field>
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={add.data.from_enrolment} onChange={(e) => add.setData('from_enrolment', e.target.checked)} /> Everyone registered for the course this semester
                            </label>
                            {session.exam.kind !== 'regular' && session.exam.parent_exam_id && (
                                <label className="flex items-center gap-2 text-sm">
                                    <input type="checkbox" checked={add.data.from_parent} onChange={(e) => add.setData('from_parent', e.target.checked)} />
                                    {session.exam.kind === 'resit' ? 'Everyone who failed the original exam' : 'Everyone who failed or missed the original exam'}
                                </label>
                            )}
                            <Button disabled={add.processing}>Add</Button>
                        </form>

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
