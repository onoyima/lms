import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, CalendarDays, ChevronLeft, ChevronRight, Wand2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Field, Flash, formatDateTime, inputClass, PageHeader, Status, Table, Td } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Sitting = {
    id: number; exam_id: number; title: string; kind: string; name: string | null; mode: string; status: string; practice: boolean; venue: string | null;
    starts_at: string; ends_at: string; candidates: number; proctors: number; attempts: number; clash: boolean;
};
type ExamOpt = { id: number; title: string; status: string; course: string | null; duration: number; scheduled: boolean; ready: boolean };
type Proposal = { exam_id: number; title: string; starts_at: string; ends_at: string; mode: string; students: number; venues: { venue_id: number; venue: string; seats: number }[] };
type Props = {
    month: string;
    sessions: Sitting[];
    clashes: { candidates: number; venues: number };
    venues: { id: number; name: string; capacity: number }[];
    exams: ExamOpt[];
    run: { id: number; status: string; proposal: Proposal[]; unplaced: { exam_id: number; title: string; reason: string }[] } | null;
    filters: { venue_id?: string };
};

const dayKey = (iso: string) => {
    const d = new Date(iso);

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};
const time = (iso: string) => new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

export default function Calendar({ month, sessions, clashes, venues, exams, run, filters }: Props) {
    const [view, setView] = useState<'month' | 'list'>('month');
    const [selected, setSelected] = useState<number[]>([]);
    const [gen, setGen] = useState({
        exam_ids: [] as number[],
        from: '',
        to: '',
        slots: ['09:00', '13:00'],
        skip_weekends: true,
        mode: 'hall',
        venue_ids: [] as number[],
        max_per_day: 2,
    });
    const [showGen, setShowGen] = useState(!!run && run.status === 'draft');
    const [roster, setRoster] = useState({ per: 30, trained_only: false });

    const first = new Date(`${month}-01T00:00:00`);
    const go = (delta: number) => {
        const d = new Date(first);
        d.setMonth(d.getMonth() + delta);
        router.get('/exam-calendar', { month: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`, ...filters }, { preserveState: true });
    };
    const days = useMemo(() => {
        const start = new Date(first);
        start.setDate(start.getDate() - ((start.getDay() + 6) % 7)); // Monday
        const out: Date[] = [];

        for (let i = 0; i < 42; i++) {
            const d = new Date(start);
            d.setDate(start.getDate() + i);
            out.push(d);
        }

        return out;
    }, [month]); // eslint-disable-line react-hooks/exhaustive-deps
    const byDay = useMemo(() => {
        const m: Record<string, (Sitting | (Proposal & { draft: true }))[]> = {};
        sessions.forEach((s) => (m[dayKey(s.starts_at)] ??= []).push(s));
        (run?.status === 'draft' ? run.proposal : []).forEach((p) => (m[dayKey(p.starts_at)] ??= []).push({ ...p, draft: true }));

        return m;
    }, [sessions, run]);
    const toggle = (id: number) => setSelected(selected.includes(id) ? selected.filter((x) => x !== id) : [...selected, id]);
    const bulk = (action: string, extra: Record<string, unknown> = {}) => {
        const reason = prompt('Reason for this change (kept in the audit log; candidates are told about time or venue changes):');

        if (reason) {
            router.post('/exam-calendar/bulk', { ids: selected, action, reason, ...extra }, { preserveScroll: true, onSuccess: () => setSelected([]) });
        }
    };

    return (
        <>
            <Head title="Exam calendar" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader
                    title={first.toLocaleDateString([], { month: 'long', year: 'numeric' })}
                    lead={`${sessions.filter((s) => !s.practice).length} sittings this view${clashes.candidates || clashes.venues ? ` · ${clashes.candidates} candidate clashes, ${clashes.venues} venue double-bookings` : ' · no clashes'}`}
                    actions={
                        <>
                            <Button variant="outline" size="icon" onClick={() => go(-1)} aria-label="Previous month"><ChevronLeft className="size-4" /></Button>
                            <Button variant="outline" size="icon" onClick={() => go(1)} aria-label="Next month"><ChevronRight className="size-4" /></Button>
                            <select className={inputClass} value={filters.venue_id ?? ''} aria-label="Venue" onChange={(e) => router.get('/exam-calendar', { month, venue_id: e.target.value || undefined })}>
                                <option value="">All venues</option>
                                {venues.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
                            </select>
                            <Button variant="outline" onClick={() => setView(view === 'month' ? 'list' : 'month')}>
                                <CalendarDays className="size-4" /> {view === 'month' ? 'List' : 'Month'}
                            </Button>
                            <Button onClick={() => setShowGen(!showGen)}><Wand2 className="size-4" /> Generate timetable</Button>
                        </>
                    }
                />
                <Flash />

                {(clashes.candidates > 0 || clashes.venues > 0) && (
                    <div className="flex items-center gap-2 rounded-lg border border-status-critical/40 bg-status-critical/10 p-3 text-sm">
                        <AlertTriangle className="size-4 text-status-critical" /> Sittings marked in red have students booked twice or a venue booked twice. Open them to see who.
                    </div>
                )}

                {showGen && (
                    <form
                        className="surface grid gap-4 p-5"
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.post('/exam-calendar/generate', gen as never, { preserveScroll: true });
                        }}
                    >
                        <p className="eyebrow">Automatic timetable</p>
                        <p className="text-sm text-muted-foreground">
                            Places each exam in the earliest slot where none of its registered students has another exam and no student has more than the daily limit, using the seats of the venues you pick. Nothing is created until you confirm.
                        </p>
                        <div className="grid gap-3 md:grid-cols-4">
                            <Field label="From"><input type="date" className={inputClass} value={gen.from} onChange={(e) => setGen({ ...gen, from: e.target.value })} required /></Field>
                            <Field label="To"><input type="date" className={inputClass} value={gen.to} onChange={(e) => setGen({ ...gen, to: e.target.value })} required /></Field>
                            <Field label="Start times (comma separated)">
                                <input className={inputClass} value={gen.slots.join(', ')} onChange={(e) => setGen({ ...gen, slots: e.target.value.split(',').map((x) => x.trim()).filter(Boolean) })} />
                            </Field>
                            <Field label="Most exams per student per day">
                                <input type="number" min={1} max={4} className={inputClass} value={gen.max_per_day} onChange={(e) => setGen({ ...gen, max_per_day: Number(e.target.value) })} />
                            </Field>
                            <Field label="Where">
                                <select className={inputClass} value={gen.mode} onChange={(e) => setGen({ ...gen, mode: e.target.value })}>
                                    <option value="hall">Exam halls</option>
                                    <option value="remote">Remote (online)</option>
                                </select>
                            </Field>
                            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={gen.skip_weekends} onChange={(e) => setGen({ ...gen, skip_weekends: e.target.checked })} /> Skip weekends</label>
                        </div>
                        {gen.mode === 'hall' && (
                            <div className="flex flex-wrap gap-2 text-sm">
                                {venues.map((v) => (
                                    <label key={v.id} className={`flex items-center gap-2 rounded-xl border px-3 py-1.5 ${gen.venue_ids.includes(v.id) ? 'border-brand bg-brand-soft' : 'border-border'}`}>
                                        <input type="checkbox" checked={gen.venue_ids.includes(v.id)} onChange={() => setGen({ ...gen, venue_ids: gen.venue_ids.includes(v.id) ? gen.venue_ids.filter((x) => x !== v.id) : [...gen.venue_ids, v.id] })} />
                                        {v.name} <span className="text-muted-foreground">({v.capacity})</span>
                                    </label>
                                ))}
                            </div>
                        )}
                        <div className="grid max-h-64 gap-1 overflow-y-auto rounded-xl border border-border p-3 text-sm">
                            <label className="flex items-center gap-2 font-medium">
                                <input type="checkbox" checked={gen.exam_ids.length > 0 && gen.exam_ids.length === exams.filter((e) => e.ready && !e.scheduled).length} onChange={(e) => setGen({ ...gen, exam_ids: e.target.checked ? exams.filter((x) => x.ready && !x.scheduled).map((x) => x.id) : [] })} />
                                All unscheduled exams
                            </label>
                            {exams.map((e) => (
                                <label key={e.id} className={`flex items-center gap-2 ${!e.ready ? 'opacity-50' : ''}`}>
                                    <input type="checkbox" disabled={!e.ready} checked={gen.exam_ids.includes(e.id)} onChange={() => setGen({ ...gen, exam_ids: gen.exam_ids.includes(e.id) ? gen.exam_ids.filter((x) => x !== e.id) : [...gen.exam_ids, e.id] })} />
                                    <span className="font-mono text-xs text-brand">{e.course ?? '—'}</span> {e.title} <span className="text-xs text-muted-foreground">{e.duration} min · {e.status}{e.scheduled ? ' · already scheduled' : ''}{!e.ready ? ' · set course and semester first' : ''}</span>
                                </label>
                            ))}
                        </div>
                        <Button className="w-fit" disabled={!gen.exam_ids.length}>Propose timetable</Button>
                    </form>
                )}

                {run?.status === 'draft' && (
                    <div className="surface grid gap-3 border-brand/50 p-5">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="eyebrow">Proposed timetable · {run.proposal.length} exams (dashed in the calendar)</p>
                            <span className="flex gap-2">
                                <Button onClick={() => router.post(`/exam-calendar/runs/${run.id}/commit`)}>Confirm and create sittings</Button>
                                <Button variant="outline" onClick={() => router.post(`/exam-calendar/runs/${run.id}/discard`)}>Discard</Button>
                            </span>
                        </div>
                        <Table head={['Exam', 'When', 'Students', 'Where']}>
                            {run.proposal.map((p) => (
                                <tr key={p.exam_id}>
                                    <Td>{p.title}</Td>
                                    <Td>{formatDateTime(p.starts_at)} – {time(p.ends_at)}</Td>
                                    <Td>{p.students}</Td>
                                    <Td>{p.mode === 'remote' ? 'Remote' : p.venues.map((v) => `${v.venue} (${v.seats})`).join(', ')}</Td>
                                </tr>
                            ))}
                        </Table>
                        {run.unplaced.length > 0 && (
                            <div className="text-sm text-status-warning">
                                Not placed: {run.unplaced.map((u) => `${u.title} — ${u.reason}`).join('; ')}. Widen the dates, add start times or venues, then generate again.
                            </div>
                        )}
                    </div>
                )}

                {selected.length > 0 && (
                    <div className="surface sticky top-2 z-10 flex flex-wrap items-center gap-2 p-3 text-sm">
                        <span>{selected.length} sittings selected</span>
                        <Button size="sm" variant="outline" onClick={() => {
 const h = Number(prompt('Move by how many hours? (negative moves earlier)', '24'));

 if (h) {
 bulk('shift', { minutes: Math.round(h * 60) }); 
} 
}}>Move</Button>
                        <select className="h-8 rounded-lg border border-input bg-background px-2" defaultValue="" onChange={(e) => e.target.value && bulk('venue', { venue_id: Number(e.target.value) })}>
                            <option value="">Change venue…</option>
                            {venues.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
                        </select>
                        <Button size="sm" variant="outline" onClick={() => {
 const m = prompt('Late entry allowed (minutes)', '15');

 if (m !== null) {
 bulk('late_entry', { late_entry_minutes: Number(m) }); 
} 
}}>Late entry</Button>
                        <Button size="sm" variant="outline" onClick={() => confirm('Cancel these sittings? Candidates are told. Sittings that started are skipped.') && bulk('cancel')}>Cancel</Button>
                        <Button size="sm" variant="ghost" onClick={() => setSelected([])}>Clear</Button>
                    </div>
                )}

                {view === 'month' ? (
                    <div className="surface overflow-x-auto">
                        <div className="grid min-w-[840px] grid-cols-7 text-xs">
                            {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((d) => <div key={d} className="border-b border-border p-2 font-medium text-muted-foreground">{d}</div>)}
                            {days.map((d) => {
                                const key = dayKey(d.toISOString());
                                const items = byDay[key] ?? [];

                                return (
                                    <div key={key} className={`min-h-28 border-r border-b border-border/60 p-1.5 ${d.getMonth() !== first.getMonth() ? 'bg-muted/30 text-muted-foreground' : ''}`}>
                                        <p className="mb-1 text-right tabular-nums">{d.getDate()}</p>
                                        <div className="grid gap-1">
                                            {items.map((it, i) =>
                                                'draft' in it ? (
                                                    <div key={`d${i}`} className="rounded-md border border-dashed border-brand p-1 text-brand">
                                                        {time(it.starts_at)} {it.title}
                                                    </div>
                                                ) : (
                                                    <div key={it.id} className={`flex items-start gap-1 rounded-md p-1 ${it.clash ? 'bg-status-critical/15 text-status-critical' : it.practice ? 'bg-muted' : 'bg-brand-soft'}`}>
                                                        <input type="checkbox" className="mt-0.5" checked={selected.includes(it.id)} onChange={() => toggle(it.id)} aria-label={`Select ${it.title}`} />
                                                        <Link href={`/sessions/${it.id}`} className="min-w-0 flex-1 truncate" title={`${it.title} · ${it.venue ?? it.mode} · ${it.candidates} candidates · ${it.proctors} proctors`}>
                                                            {time(it.starts_at)} {it.title}
                                                            <span className="block truncate opacity-70">{it.venue ?? it.mode} · {it.candidates}</span>
                                                        </Link>
                                                    </div>
                                                ),
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                ) : (
                    <Table head={['', 'When', 'Exam', 'Where', 'Candidates', 'Proctors', 'Status']}>
                        {sessions.map((s) => (
                            <tr key={s.id} className={s.clash ? 'bg-status-critical/10' : ''}>
                                <Td><input type="checkbox" checked={selected.includes(s.id)} onChange={() => toggle(s.id)} aria-label={`Select ${s.title}`} /></Td>
                                <Td>{formatDateTime(s.starts_at)} – {time(s.ends_at)}</Td>
                                <Td><Link href={`/sessions/${s.id}`} className="text-brand">{s.title}</Link>{s.kind !== 'regular' && <span className="ml-1 text-xs text-muted-foreground">({s.kind})</span>}{s.name && <span className="block text-xs text-muted-foreground">{s.name}</span>}</Td>
                                <Td>{s.venue ?? s.mode}</Td>
                                <Td>{s.candidates}</Td>
                                <Td className={s.proctors === 0 && !s.practice ? 'text-status-warning' : ''}>{s.proctors}</Td>
                                <Td><Status value={s.clash ? 'clash' : s.status} /></Td>
                            </tr>
                        ))}
                    </Table>
                )}

                <form
                    className="surface flex flex-wrap items-end gap-3 p-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const last = new Date(first);
                        last.setMonth(last.getMonth() + 1);
                        last.setDate(0);
                        router.post('/exam-calendar/roster', { from: `${month}-01`, to: dayKey(last.toISOString()), ...roster }, { preserveScroll: true });
                    }}
                >
                    <div className="grid gap-1">
                        <p className="eyebrow">Proctor roster for this month</p>
                        <p className="text-xs text-muted-foreground">Fills every sitting with free proctors, respecting their availability and other shifts. <Link href="/proctor/shifts" className="text-brand">See shifts</Link></p>
                    </div>
                    <Field label="Candidates per proctor"><input type="number" min={5} max={200} className={inputClass} value={roster.per} onChange={(e) => setRoster({ ...roster, per: Number(e.target.value) })} /></Field>
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={roster.trained_only} onChange={(e) => setRoster({ ...roster, trained_only: e.target.checked })} /> Trained proctors only</label>
                    <Button variant="outline">Assign proctors</Button>
                </form>
            </div>
        </>
    );
}

Calendar.layout = { breadcrumbs: [{ title: 'Exams', href: '/exams' }, { title: 'Calendar', href: '/exam-calendar' }] };
