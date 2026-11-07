import { Head } from '@inertiajs/react';
import { PageHeader, Stat, Table, Td } from '@/components/platform/ui';

type Item = { question_id: number; responses: number; difficulty: number | null; discrimination: number | null; point_biserial: number | null; flag: string | null };
type SessionReport = { id: number; name: string; report: { candidates: Record<string, number | null>; attempts: Record<string, number | null>; incidents: Record<string, number> }; pairs: { shared_wrong: number; a: { name: string | null; seat: string | null }; b: { name: string | null; seat: string | null } }[] };

export default function Analytics({ exam, items, sessions, departments }: { exam: { id: number; title: string; type: string }; items: Item[]; sessions: SessionReport[]; departments: { department: string; candidates: number; average: number; passed: number }[] }) {
    return (
        <>
            <Head title={`Analysis — ${exam.title}`} />
            <div className="flex flex-col gap-10 p-6 md:p-10">
                <PageHeader title={exam.title} lead="Difficulty is the share who got an item right; discrimination compares the top and bottom 27% of candidates. Flags are prompts to check, not verdicts." />

                {sessions.map((s) => (
                    <section key={s.id} className="grid gap-4">
                        <p className="eyebrow">{s.name}</p>
                        <div className="grid grid-cols-2 gap-3 md:grid-cols-6">
                            <Stat label="Expected" value={s.report.candidates.total ?? 0} />
                            <Stat label="Present" value={s.report.candidates.present ?? 0} />
                            <Stat label="Absent" value={s.report.candidates.absent ?? 0} />
                            <Stat label="Submitted" value={Number(s.report.attempts.submitted ?? 0) + Number(s.report.attempts.auto_submitted ?? 0)} />
                            <Stat label="Terminated" value={s.report.attempts.terminated ?? 0} />
                            <Stat label="Incidents" value={Object.values(s.report.incidents).reduce((a, b) => a + b, 0)} />
                        </div>
                        {s.pairs.length > 0 && (
                            <Table head={['Shared wrong answers', 'Candidate', 'Seat', 'Candidate', 'Seat']}>
                                {s.pairs.map((p, i) => (
                                    <tr key={i}>
                                        <Td className="font-semibold tabular-nums text-status-warning">{p.shared_wrong}</Td>
                                        <Td>{p.a.name}</Td><Td className="font-mono text-xs">{p.a.seat ?? '—'}</Td>
                                        <Td>{p.b.name}</Td><Td className="font-mono text-xs">{p.b.seat ?? '—'}</Td>
                                    </tr>
                                ))}
                            </Table>
                        )}
                    </section>
                ))}

                {items.length > 0 && (
                    <section className="grid gap-3">
                        <p className="eyebrow">Item analysis</p>
                        <Table head={['Question', 'Responses', 'Difficulty', 'Discrimination', 'Point-biserial', 'Check']}>
                            {items.map((i) => (
                                <tr key={i.question_id}>
                                    <Td className="font-mono text-xs">Q{i.question_id}</Td>
                                    <Td className="tabular-nums">{i.responses}</Td>
                                    <Td className="tabular-nums">{i.difficulty?.toFixed(2)}</Td>
                                    <Td className={`tabular-nums ${(i.discrimination ?? 0) < 0.2 ? 'text-status-warning' : ''}`}>{i.discrimination?.toFixed(2)}</Td>
                                    <Td className="tabular-nums">{i.point_biserial?.toFixed(2) ?? '—'}</Td>
                                    <Td className="text-xs text-status-warning">{i.flag}</Td>
                                </tr>
                            ))}
                        </Table>
                    </section>
                )}

                {departments.length > 0 && (
                    <section className="grid gap-3">
                        <p className="eyebrow">By department</p>
                        <Table head={['Department', 'Candidates', 'Average', 'Passed']}>
                            {departments.map((d) => (
                                <tr key={d.department}><Td>{d.department}</Td><Td>{d.candidates}</Td><Td>{Number(d.average).toFixed(1)}</Td><Td>{d.passed}</Td></tr>
                            ))}
                        </Table>
                    </section>
                )}
            </div>
        </>
    );
}

Analytics.layout = { breadcrumbs: [{ title: 'Exams', href: '/exams' }, { title: 'Analysis', href: '#' }] };
