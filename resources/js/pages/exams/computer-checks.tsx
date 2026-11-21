import { Head, Link, router, useForm } from '@inertiajs/react';
import { MonitorCheck, MonitorX } from 'lucide-react';
import { Field, formatDateTime, inputClass, PageHeader, Table, Td } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Check = { id: number; name: string; code: string; open: boolean; closes_at: string | null; exam: string | null; computers: number; failed: number; passed: number };
type Sitting = { id: number; label: string; needs: string[] };
type Problem = { attempt_id: number; name: string | null; identifier: string | null; exam: string | null; session_id: number; status: string; ip: string | null; browser: string; problems: { label: string; count: number; last: string; message?: string }[]; last: string | null };

/** Computer checks before exams, and computer problems reported during exams, with each computer's IP. */
export default function ComputerChecks({ checks, sittings, tests, basic, problems, hours }: { checks: Check[]; sittings: Sitting[]; tests: Record<string, string>; basic: string[]; problems: Problem[]; hours: number }) {
    const form = useForm<{ name: string; exam_session_id: string; requirements: string[]; hours: number }>({ name: '', exam_session_id: '', requirements: [], hours: 12 });
    const extra = Object.keys(tests).filter((k) => !basic.includes(k));
    const pickSitting = (id: string) => {
        const s = sittings.find((x) => String(x.id) === id);
        form.setData((d) => ({ ...d, exam_session_id: id, requirements: s ? s.needs : d.requirements }));
    };

    return (
        <>
            <Head title="Computer checks" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title="Computer checks" lead="Test every lab computer before an exam: open the check's link on each computer and it tests itself. Problems from real exams are listed here too, with each computer's IP address." />

                <section className="surface grid gap-4 p-5">
                    <h2 className="font-semibold">New check</h2>
                    <form className="grid gap-4 md:grid-cols-[1fr_1fr_auto]" onSubmit={(e) => (e.preventDefault(), form.post('/computer-checks'))}>
                        <Field label="Name" error={form.errors.name}><input className={inputClass} placeholder="e.g. CBT Lab 2, before GST 111" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></Field>
                        <Field label="For a sitting (optional)" hint="The check then tests what that exam needs.">
                            <select className={inputClass} value={form.data.exam_session_id} onChange={(e) => pickSitting(e.target.value)}>
                                <option value="">No particular sitting</option>
                                {sittings.map((s) => <option key={s.id} value={s.id}>{s.label}</option>)}
                            </select>
                        </Field>
                        <Field label="Open for (hours)"><input type="number" min={1} max={168} className={`${inputClass} !w-24`} value={form.data.hours} onChange={(e) => form.setData('hours', Number(e.target.value))} /></Field>
                        <div className="md:col-span-3">
                            <p className="text-sm">Always tested: {basic.map((k) => tests[k]).join(', ')}.</p>
                            <div className="mt-2 flex flex-wrap gap-3 text-sm">
                                {extra.map((k) => (
                                    <label key={k} className="flex items-center gap-2"><input type="checkbox" checked={form.data.requirements.includes(k)} onChange={(e) => form.setData('requirements', e.target.checked ? [...form.data.requirements, k] : form.data.requirements.filter((x) => x !== k))} /> {tests[k]}</label>
                                ))}
                            </div>
                        </div>
                        <Button className="w-fit" disabled={form.processing}><MonitorCheck className="size-4" /> Create check</Button>
                    </form>
                </section>

                {checks.length > 0 && (
                    <Table head={['Check', 'Exam', 'Computers', 'Passed', 'Failed', 'Open until', '']}>
                        {checks.map((c) => (
                            <tr key={c.id} className="border-t border-border">
                                <Td><Link href={`/computer-checks/${c.id}`} className="font-medium text-brand">{c.name}</Link></Td>
                                <Td>{c.exam ?? '—'}</Td>
                                <Td className="tabular-nums">{c.computers}</Td>
                                <Td className="tabular-nums text-status-good">{c.passed}</Td>
                                <Td className={`tabular-nums ${c.failed ? 'font-semibold text-status-critical' : ''}`}>{c.failed}</Td>
                                <Td>{c.open ? formatDateTime(c.closes_at) : 'Closed'}</Td>
                                <Td><Link href={`/computer-checks/${c.id}`} className="text-sm text-brand">Open</Link></Td>
                            </tr>
                        ))}
                    </Table>
                )}

                <section className="grid gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 className="flex items-center gap-2 text-lg font-semibold"><MonitorX className="size-5 text-status-critical" /> Computer problems during exams</h2>
                        <label className="flex items-center gap-2 text-sm">Last
                            <select className={`${inputClass} h-9 !w-auto`} value={hours} onChange={(e) => router.get('/computer-checks', { hours: e.target.value }, { preserveScroll: true })}>
                                {[1, 6, 24, 72, 168].map((h) => <option key={h} value={h}>{h < 24 ? `${h} hour${h > 1 ? 's' : ''}` : `${h / 24} day${h > 24 ? 's' : ''}`}</option>)}
                            </select>
                        </label>
                    </div>
                    {problems.length === 0 ? (
                        <p className="surface p-6 text-sm text-muted-foreground">No computer problems reported in this period. Lost networks, computers that went off, camera failures, answers not saving and exam-screen errors appear here automatically.</p>
                    ) : (
                        <Table head={['Computer (IP)', 'Candidate', 'Exam', 'What happened', 'Last']}>
                            {problems.map((p) => (
                                <tr key={p.attempt_id} className="border-t border-border align-top">
                                    <Td><span className="font-mono font-semibold">{p.ip ?? '—'}</span><span className="block text-xs text-muted-foreground">{p.browser}</span></Td>
                                    <Td>{p.name}<span className="block font-mono text-xs text-muted-foreground">{p.identifier}</span></Td>
                                    <Td>{p.exam}<span className="block text-xs text-muted-foreground">{p.status.replace('_', ' ')}</span></Td>
                                    <Td>
                                        <ul className="grid gap-0.5 text-sm">
                                            {p.problems.map((x) => <li key={x.label}>{x.label}{x.count > 1 ? ` ×${x.count}` : ''}{x.message && <span className="block text-xs text-muted-foreground">{x.message}</span>}</li>)}
                                        </ul>
                                    </Td>
                                    <Td className="whitespace-nowrap text-sm">{formatDateTime(p.last)}</Td>
                                </tr>
                            ))}
                        </Table>
                    )}
                </section>
            </div>
        </>
    );
}

ComputerChecks.layout = { breadcrumbs: [{ title: 'Exam day', href: '/proctor' }, { title: 'Computer checks', href: '/computer-checks' }] };
