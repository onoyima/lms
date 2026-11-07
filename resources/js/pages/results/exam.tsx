import { Head, Link, router, usePage } from '@inertiajs/react';
import { BarChart3, Download } from 'lucide-react';
import { PageHeader, Stat, Status, Table, Td } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/types';

type Row = { id: number; name: string; identifier: string | null; raw: number | null; max: number | null; final: number | null; grade: string | null; status: string; locked: boolean; withheld_reason: string | null };

export default function ExamResults({ exam, results, stats, changes, appeals, canApprove, canPublish }: {
    exam: { id: number; title: string; type: string; total_mark: string | null; pass_mark: string | null; status: string }; results: Paginated<Row>;
    stats: { count: number; mean: number; min: number; max: number; grades: Record<string, number>; status: Record<string, number> };
    changes: { id: number; candidate: string; from: number; to: number; reason: string; requested_by: string | null }[];
    appeals: { id: number; candidate: string; statement: string; status: string }[]; canApprove: boolean; canPublish: boolean;
}) {
    const { flash } = usePage().props;
    const move = (to: string) => confirm(`Mark all eligible results as ${to}?`) && router.post(`/results/${exam.id}/transition`, { to }, { preserveScroll: true });

    return (
        <>
            <Head title={`Results — ${exam.title}`} />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader
                    title={exam.title}
                    lead="Draft → moderated → approved → published. Approved results are locked; changes need a second officer."
                    actions={
                        <>
                            <Button variant="outline" onClick={() => router.get(`/results/${exam.id}`, { recompute: 1 })}>Recompute</Button>
                            <Button variant="outline" onClick={() => move('moderated')}>Moderate</Button>
                            {canApprove && <Button variant="outline" onClick={() => move('approved')}>Approve</Button>}
                            {canPublish && <Button onClick={() => move('published')}>Publish</Button>}
                            <Button variant="outline" asChild><a href={`/results/${exam.id}/export?format=xlsx`}><Download className="size-4" /> Excel</a></Button>
                            <Button variant="outline" asChild><a href={`/results/${exam.id}/export?format=pdf`}>PDF</a></Button>
                            <Button variant="outline" asChild><a href={`/results/${exam.id}/export`}>CSV</a></Button>
                            <Button variant="outline" asChild><Link href={`/results/${exam.id}/analytics`}><BarChart3 className="size-4" /> Analysis</Link></Button>
                        </>
                    }
                />
                {flash?.success && <p className="text-sm text-status-good">{flash.success}</p>}
                <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
                    <Stat label="Candidates" value={stats.count} />
                    <Stat label="Mean" value={stats.mean} />
                    <Stat label="Lowest" value={stats.min} />
                    <Stat label="Highest" value={stats.max} />
                    <Stat label="Grades" value={<span className="text-sm">{Object.entries(stats.grades).map(([g, n]) => `${g}:${n}`).join('  ')}</span>} />
                </div>

                {changes.length > 0 && (
                    <section className="surface p-5">
                        <p className="eyebrow mb-3">Changes waiting for a second officer</p>
                        {changes.map((c) => (
                            <div key={c.id} className="flex flex-wrap items-center justify-between gap-3 border-b border-border py-2 text-sm last:border-0">
                                <span>{c.candidate}: {c.from} → <strong>{c.to}</strong> — {c.reason} <span className="text-xs text-muted-foreground">(by {c.requested_by})</span></span>
                                {canApprove && (
                                    <span className="flex gap-2">
                                        <Button size="sm" onClick={() => router.post(`/results/changes/${c.id}`, { approve: true }, { preserveScroll: true })}>Approve</Button>
                                        <Button size="sm" variant="outline" onClick={() => router.post(`/results/changes/${c.id}`, { approve: false }, { preserveScroll: true })}>Reject</Button>
                                    </span>
                                )}
                            </div>
                        ))}
                    </section>
                )}
                {appeals.length > 0 && (
                    <section className="surface p-5">
                        <p className="eyebrow mb-3">Recheck requests</p>
                        {appeals.map((a) => (
                            <div key={a.id} className="grid gap-2 border-b border-border py-3 text-sm last:border-0">
                                <p><strong>{a.candidate}</strong>: {a.statement}</p>
                                {canApprove && (
                                    <span className="flex gap-2">
                                        <Button size="sm" onClick={() => {
 const d = prompt('Decision (sent to the candidate):');

 if (d) {
router.post(`/results/appeals/${a.id}`, { upheld: true, decision: d }, { preserveScroll: true });
} 
}}>Uphold</Button>
                                        <Button size="sm" variant="outline" onClick={() => {
 const d = prompt('Reason for rejecting:');

 if (d) {
router.post(`/results/appeals/${a.id}`, { upheld: false, decision: d }, { preserveScroll: true });
} 
}}>Reject</Button>
                                    </span>
                                )}
                            </div>
                        ))}
                    </section>
                )}

                <Table head={['Candidate', 'Raw', 'Final', 'Grade', 'Status', '']}>
                    {results.data.map((r) => (
                        <tr key={r.id}>
                            <Td>{r.name}<div className="font-mono text-xs text-muted-foreground">{r.identifier}</div></Td>
                            <Td className="tabular-nums">{r.raw ?? '—'} / {r.max ?? '—'}</Td>
                            <Td className="font-semibold tabular-nums">{r.final ?? '—'}</Td>
                            <Td>{r.grade}</Td>
                            <Td><Status value={r.status} />{r.locked && <span className="ml-1 text-xs text-muted-foreground">locked</span>}{r.withheld_reason && <div className="text-xs text-status-critical">{r.withheld_reason}</div>}</Td>
                            <Td className="flex gap-2 text-xs">
                                <button className="text-brand" onClick={() => {
 const to = prompt('Corrected final score:'); const reason = to && prompt('Reason (kept in the audit trail):');

 if (to && reason) {
router.post(`/results/items/${r.id}/change`, { to, reason }, { preserveScroll: true });
} 
}}>Change…</button>
                                {canApprove && r.status !== 'withheld' && <button className="text-status-critical" onClick={() => {
 const reason = prompt('Why withhold this result?');

 if (reason) {
router.post(`/results/items/${r.id}/withhold`, { reason }, { preserveScroll: true });
} 
}}>Withhold</button>}
                            </Td>
                        </tr>
                    ))}
                </Table>
            </div>
        </>
    );
}

ExamResults.layout = { breadcrumbs: [{ title: 'Exams', href: '/exams' }, { title: 'Results', href: '#' }] };
