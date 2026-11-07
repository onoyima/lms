import { Head, Link, router, useForm } from '@inertiajs/react';
import { GraduationCap, Trash2 } from 'lucide-react';
import { useMemo } from 'react';
import { Field, Flash, formatDateTime, inputClass, PageHeader, Status, Table, Td } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Assignment = { id: number; user_id: number; role: string; session_id: number; title: string; starts_at: string; ends_at: string; venue: string | null };
type Availability = { id: number; user_id: number; date: string; from_time: string; to_time: string; available: boolean; note: string | null };
type Props = {
    from: string;
    to: string;
    manage: boolean;
    proctors: { id: number; name: string }[];
    assignments: Assignment[];
    availability: Availability[];
    unstaffed: { id: number; title: string; name: string | null; starts_at: string }[];
    trained: number[];
};

const time = (iso: string) => new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

export default function Shifts({ from, manage, proctors, assignments, availability, unstaffed, trained }: Props) {
    const form = useForm({ user_id: '', date: '', from_time: '08:00', to_time: '17:00', available: true, note: '' });
    const days = useMemo(() => {
        const out: string[] = [];
        const d = new Date(`${from}T00:00:00`);

        for (let i = 0; i < 14; i++) {
            out.push(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`);
            d.setDate(d.getDate() + 1);
        }

        return out;
    }, [from]);
    const people = manage ? proctors : [{ id: 0, name: 'Me' }];
    const sameDay = (iso: string, day: string) => new Date(iso).toDateString() === new Date(`${day}T00:00:00`).toDateString();
    const mine = (uid: number, owner: number) => !manage || owner === uid;
    const cell = (uid: number, day: string) => ({
        shifts: assignments.filter((a) => mine(uid, a.user_id) && sameDay(a.starts_at, day)),
        avail: availability.filter((a) => mine(uid, a.user_id) && a.date.slice(0, 10) === day),
    });
    const move = (days: number) => {
        const d = new Date(`${from}T00:00:00`);
        d.setDate(d.getDate() + days);
        router.get('/proctor/shifts', { from: d.toISOString().slice(0, 10) }, { preserveState: true });
    };

    return (
        <>
            <Head title="Proctor shifts" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader
                    title="Shifts"
                    accent="and availability"
                    lead="Two weeks at a time. Tell the exams office when you can and cannot work; rostering only uses free, available staff."
                    actions={
                        <>
                            <Button variant="outline" onClick={() => move(-14)}>Earlier</Button>
                            <Button variant="outline" onClick={() => move(14)}>Later</Button>
                            <Button onClick={() => router.post('/proctor/training')}>
                                <GraduationCap className="size-4" /> Practise in the training sandbox
                            </Button>
                        </>
                    }
                />
                <Flash />

                {manage && unstaffed.length > 0 && (
                    <div className="rounded-lg border border-status-warning/40 bg-status-warning/10 p-3 text-sm">
                        {unstaffed.length} sittings have no proctor yet:{' '}
                        {unstaffed.slice(0, 8).map((u) => (
                            <Link key={u.id} href={`/sessions/${u.id}`} className="mr-2 text-brand">{u.title} ({formatDateTime(u.starts_at)})</Link>
                        ))}
                        — use <Link href="/exam-calendar" className="text-brand">Assign proctors</Link> on the calendar.
                    </div>
                )}

                <div className="surface overflow-x-auto">
                    <table className="w-full min-w-[960px] text-xs">
                        <thead>
                            <tr className="border-b border-border">
                                <th className="p-2 text-left font-medium">{manage ? 'Proctor' : ''}</th>
                                {days.map((d) => (
                                    <th key={d} className="p-2 text-left font-medium text-muted-foreground">
                                        {new Date(`${d}T00:00:00`).toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' })}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {people.map((p) => (
                                <tr key={p.id} className="border-b border-border/60 align-top">
                                    <td className="p-2 font-medium whitespace-nowrap">
                                        {p.name}
                                        {manage && trained.includes(p.id) && <span className="ml-1 text-[10px] text-status-good">trained</span>}
                                    </td>
                                    {days.map((d) => {
                                        const c = cell(p.id, d);

                                        return (
                                            <td key={d} className="min-w-20 p-1">
                                                {c.avail.map((a) => (
                                                    <div key={`a${a.id}`} className={`mb-1 flex items-center justify-between gap-1 rounded px-1 ${a.available ? 'bg-status-good/10 text-status-good' : 'bg-status-critical/10 text-status-critical'}`} title={a.note ?? ''}>
                                                        {a.available ? '' : 'off '}{a.from_time.slice(0, 5)}–{a.to_time.slice(0, 5)}
                                                        <button onClick={() => router.delete(`/proctor/availability/${a.id}`, { preserveScroll: true })} aria-label="Remove"><Trash2 className="size-3" /></button>
                                                    </div>
                                                ))}
                                                {c.shifts.map((s) => (
                                                    <Link key={s.id} href={`/proctor/sessions/${s.session_id}`} className="mb-1 block rounded bg-brand-soft px-1 text-brand" title={`${s.title} · ${s.venue ?? 'remote'}`}>
                                                        {time(s.starts_at)} {s.role}
                                                        <span className="block truncate opacity-70">{s.title}</span>
                                                    </Link>
                                                ))}
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <form
                    className="surface grid gap-3 p-5 md:grid-cols-6"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/proctor/availability', { preserveScroll: true, onSuccess: () => form.reset('note') });
                    }}
                >
                    <p className="eyebrow md:col-span-6">Add availability</p>
                    {manage && (
                        <Field label="Proctor">
                            <select className={inputClass} value={form.data.user_id} onChange={(e) => form.setData('user_id', e.target.value)}>
                                <option value="">Me</option>
                                {proctors.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                            </select>
                        </Field>
                    )}
                    <Field label="Date" error={form.errors.date}><input type="date" className={inputClass} value={form.data.date} onChange={(e) => form.setData('date', e.target.value)} required /></Field>
                    <Field label="From"><input type="time" className={inputClass} value={form.data.from_time} onChange={(e) => form.setData('from_time', e.target.value)} /></Field>
                    <Field label="To" error={form.errors.to_time}><input type="time" className={inputClass} value={form.data.to_time} onChange={(e) => form.setData('to_time', e.target.value)} /></Field>
                    <Field label="Type">
                        <select className={inputClass} value={form.data.available ? '1' : '0'} onChange={(e) => form.setData('available', e.target.value === '1')}>
                            <option value="1">Available</option>
                            <option value="0">Not available</option>
                        </select>
                    </Field>
                    <Field label="Note"><input className={inputClass} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} /></Field>
                    <Button className="w-fit" disabled={form.processing}>Save</Button>
                </form>

                {!manage && assignments.length > 0 && (
                    <Table head={['When', 'Exam', 'Where', 'Role']}>
                        {assignments.map((a) => (
                            <tr key={a.id}>
                                <Td>{formatDateTime(a.starts_at)} – {time(a.ends_at)}</Td>
                                <Td><Link href={`/proctor/sessions/${a.session_id}`} className="text-brand">{a.title}</Link></Td>
                                <Td>{a.venue ?? 'Remote'}</Td>
                                <Td><Status value={a.role} /></Td>
                            </tr>
                        ))}
                    </Table>
                )}
            </div>
        </>
    );
}

Shifts.layout = { breadcrumbs: [{ title: 'Proctoring', href: '/proctor' }, { title: 'Shifts', href: '/proctor/shifts' }] };
