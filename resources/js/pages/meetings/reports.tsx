import { Head, Link, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useState } from 'react';
import { Field, inputClass, PageHeader, Table, Td } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Cell = { percent: number; status: string; minutes: number } | null;
type Report = {
    meetings: { id: string; title: string; date: string; minutes: number; attended: number; late: number; partial: number; turnout: number; average_minutes: number }[];
    students: { id: number; name: string; identifier: string | null; cells: Cell[]; attended: number; average: number; minutes: number }[];
    summary: { classes: number; students: number; average_turnout: number; never: number; below_half: number };
};
type Props = {
    courses: { id: number; label: string; title: string }[]; course: { id: number; label: string } | null; filters: { from: string | null; to: string | null };
    report: Report | null; overview: { held: number; upcoming: number; people: number; hours: number; recordings: number };
};

const tone = (c: Cell) => !c ? 'bg-muted text-muted-foreground' : c.status === 'partial' ? 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200' : c.status === 'late' ? 'bg-sky-100 text-sky-900 dark:bg-sky-950 dark:text-sky-200' : 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200';

function Stat({ label, value }: { label: string; value: string | number }) {
    return <div className="rounded-lg border border-border bg-card p-4"><div className="text-2xl font-semibold tabular-nums">{value}</div><div className="text-xs text-muted-foreground">{label}</div></div>;
}

/** Live-class attendance across a course: each class, each student, downloadable. */
export default function MeetingReports({ courses, course, filters, report, overview }: Props) {
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');
    const go = (id: number | null) => router.get('/meeting-reports', { course: id ?? undefined, from: from || undefined, to: to || undefined }, { preserveState: true });
    const qs = course ? `course=${course.id}${from ? `&from=${from}` : ''}${to ? `&to=${to}` : ''}` : '';

    return (
        <>
            <Head title="Live-class reports" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title="Live-class reports" lead="Who came to each class, for how long, and who keeps missing them." />
                <section className="grid gap-3 sm:grid-cols-5" aria-label="Last 30 days">
                    <Stat label="Classes held (30 days)" value={overview.held} /><Stat label="Coming up" value={overview.upcoming} />
                    <Stat label="People who joined" value={overview.people} /><Stat label="Hours in class" value={overview.hours} /><Stat label="Recordings published" value={overview.recordings} />
                </section>
                <form className="flex flex-wrap items-end gap-3" onSubmit={(e) => (e.preventDefault(), go(course?.id ?? null))}>
                    <Field label="Course">
                        <select className={inputClass} value={course?.id ?? ''} onChange={(e) => go(e.target.value ? Number(e.target.value) : null)}>
                            <option value="">Choose a course</option>
                            {courses.map((c) => <option key={c.id} value={c.id}>{c.label} — {c.title}</option>)}
                        </select>
                    </Field>
                    <Field label="From"><input type="date" className={inputClass} value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
                    <Field label="To"><input type="date" className={inputClass} value={to} onChange={(e) => setTo(e.target.value)} /></Field>
                    <Button variant="outline" disabled={!course}>Apply</Button>
                    {course && report && report.meetings.length > 0 && (
                        <span className="flex items-center gap-3 pb-2 text-sm">
                            <a className="inline-flex items-center gap-1 text-brand hover:underline" href={`/meeting-reports/export?${qs}&format=xlsx`}><Download className="size-4" /> Excel</a>
                            <a className="text-brand hover:underline" href={`/meeting-reports/export?${qs}&format=pdf`}>PDF</a>
                            <a className="text-brand hover:underline" href={`/meeting-reports/export?${qs}&format=csv`}>CSV</a>
                        </span>
                    )}
                </form>

                {!course && <p className="text-sm text-muted-foreground">Choose a course to see its classes and each student's attendance.</p>}
                {course && report && (report.meetings.length === 0 ? <p className="text-sm text-muted-foreground">No finished classes for {course.label} in this period.</p> : (
                    <>
                        <section className="grid gap-3 sm:grid-cols-4">
                            <Stat label="Classes" value={report.summary.classes} /><Stat label="Average turnout" value={`${report.summary.average_turnout}%`} />
                            <Stat label="Never attended" value={report.summary.never} /><Stat label="Below half the time" value={report.summary.below_half} />
                        </section>
                        <section className="grid gap-2">
                            <h2 className="text-sm font-medium">Each class</h2>
                            <Table head={['Date', 'Class', 'Came', 'Turnout', 'Late', 'Left early / partial', 'Average stay']}>
                                {report.meetings.map((m) => (
                                    <tr key={m.id}>
                                        <Td className="tabular-nums">{m.date}</Td><Td><Link className="text-brand hover:underline" href={`/meetings/${m.id}`}>{m.title}</Link></Td>
                                        <Td className="tabular-nums">{m.attended} / {report.summary.students}</Td>
                                        <Td><div className="flex items-center gap-2"><div className="h-2 w-24 overflow-hidden rounded bg-muted"><div className="h-full bg-brand" style={{ width: `${m.turnout}%` }} /></div><span className="tabular-nums text-xs">{m.turnout}%</span></div></Td>
                                        <Td className="tabular-nums">{m.late}</Td><Td className="tabular-nums">{m.partial}</Td><Td className="tabular-nums">{m.average_minutes} of {m.minutes} min</Td>
                                    </tr>
                                ))}
                            </Table>
                        </section>
                        <section className="grid gap-2">
                            <h2 className="text-sm font-medium">Each student</h2>
                            <div className="overflow-x-auto rounded-lg border border-border">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                        <tr>
                                            <th className="sticky left-0 bg-muted px-3 py-2 font-medium">Student</th>
                                            {report.meetings.map((m) => <th key={m.id} className="px-2 py-2 text-center font-medium" title={m.title}>{m.date.slice(5)}</th>)}
                                            <th className="px-3 py-2 font-medium">Attended</th><th className="px-3 py-2 font-medium">Average</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border">
                                        {report.students.map((s) => (
                                            <tr key={s.id}>
                                                <td className="sticky left-0 bg-card px-3 py-2"><span className="font-medium">{s.name}</span><span className="block font-mono text-xs text-muted-foreground">{s.identifier}</span></td>
                                                {s.cells.map((c, i) => <td key={i} className="px-1 py-1 text-center"><span className={`inline-block min-w-10 rounded px-1.5 py-0.5 text-xs tabular-nums ${tone(c)}`} title={c ? `${c.minutes} min, ${c.status}` : 'absent'}>{c ? `${c.percent}%` : '—'}</span></td>)}
                                                <td className="px-3 py-2 tabular-nums">{s.attended} / {report.meetings.length}</td>
                                                <td className={`px-3 py-2 tabular-nums ${s.average < 50 ? 'font-semibold text-status-critical' : ''}`}>{s.average}%</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <p className="text-xs text-muted-foreground">Green: present · blue: came late · amber: stayed less than the required share · grey: absent.</p>
                        </section>
                    </>
                ))}
            </div>
        </>
    );
}

MeetingReports.layout = { breadcrumbs: [{ title: 'Live classes & meetings', href: '/meetings' }, { title: 'Reports', href: '/meeting-reports' }] };
