import { Head, router } from '@inertiajs/react';
import { PerformanceCharts } from '@/components/metric-chart';
import type { SeriesPoint } from '@/components/metric-chart';
import { Flash, formatDateTime, PageHeader, Stat, Status, Table, Td } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Alert = { id: number; key: string; level: string; message: string; opened_at: string; resolved_at: string | null; acknowledged_at: string | null };
type Drill = { id: number; status: string; rto_seconds: number | null; rpo_seconds: number | null; started_at: string; finished_at: string | null; steps: { step: string; ok: boolean | null; seconds: number; detail: string }[] };
type Props = { range: string; series: SeriesPoint[]; current: Record<string, number | string>; alerts: Alert[]; drills: Drill[]; webhook: boolean };

const dur = (s: number | null) => (s === null ? '—' : s < 120 ? `${s} s` : s < 7200 ? `${Math.round(s / 60)} min` : `${Math.round(s / 3600)} h`);

/** Service health for the platform operator: live figures, alerts and recovery drills (LMS 140, 143, 243, 245; Target 186). */
export default function Health({ range, series, current, alerts, drills, webhook }: Props) {
    const open = alerts.filter((a) => !a.resolved_at);

    return (
        <>
            <Head title="System health" />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader title="System health" lead={`Recorded every minute by the scheduler. Alerts go to every platform administrator${webhook ? ' and the alert webhook' : ''}.`}
                    actions={['1h', '24h', '7d'].map((r) => <Button key={r} size="sm" variant={range === r ? 'default' : 'outline'} onClick={() => router.get('/platform/health', { range: r }, { preserveScroll: true })}>{r === '1h' ? 'Last hour' : r === '24h' ? '24 hours' : '7 days'}</Button>)} />
                <Flash />
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <Stat label="Candidates writing" value={current.candidates_writing ?? '—'} />
                    <Stat label="Requests last minute" value={current.requests ?? '—'} />
                    <Stat label="Response time p95" value={current.p95_ms ? `${current.p95_ms} ms` : '—'} tone={Number(current.p95_ms) > 1000 ? 'warning' : undefined} />
                    <Stat label="CPU / memory" value={`${current.cpu_pct ?? '—'}% / ${current.memory_pct ?? '—'}%`} />
                    <Stat label="Open alerts" value={open.length} tone={open.some((a) => a.level === 'critical') ? 'critical' : open.length ? 'warning' : 'good'} />
                </div>

                <section className="grid gap-3">
                    <h2 className="text-lg font-medium">Alerts</h2>
                    <Table head={['Opened', 'Level', 'What', 'Closed', '']}>
                        {alerts.length === 0 && <tr><Td className="text-muted-foreground">No alerts so far.</Td></tr>}
                        {alerts.map((a) => (
                            <tr key={a.id}>
                                <Td>{formatDateTime(a.opened_at)}</Td>
                                <Td><Status value={a.resolved_at ? 'resolved' : a.level} /></Td>
                                <Td>{a.message}</Td>
                                <Td>{a.resolved_at ? formatDateTime(a.resolved_at) : 'open'}</Td>
                                <Td>{!a.resolved_at && !a.acknowledged_at && <Button size="sm" variant="ghost" onClick={() => router.post(`/platform/health/alerts/${a.id}/ack`, {}, { preserveScroll: true })}>I'm on it</Button>}{a.acknowledged_at && <span className="text-xs text-muted-foreground">acknowledged</span>}</Td>
                            </tr>
                        ))}
                    </Table>
                </section>

                <section className="grid gap-3">
                    <h2 className="text-lg font-medium">Traffic and exams</h2>
                    <PerformanceCharts series={series} include={['candidates_writing', 'answers_saved', 'requests', 'p95_ms', 'error_pct', 'queue_waiting']} />
                    <h2 className="mt-4 text-lg font-medium">Server</h2>
                    <PerformanceCharts series={series} include={['cpu_pct', 'memory_pct', 'disk_free_pct', 'db_connections']} />
                </section>

                <section className="grid gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 className="text-lg font-medium">Recovery drills</h2>
                            <p className="text-sm text-muted-foreground">Backup, full restore with row counts compared, replica write test and peer servers. Runs on the 1st of every month; run one before each exam season.</p>
                        </div>
                        <Button variant="outline" onClick={() => confirm('Start a recovery drill now? It takes a fresh backup and restores it into a scratch database.') && router.post('/platform/health/drill', {}, { preserveScroll: true })}>Run a drill</Button>
                    </div>
                    {drills.length === 0 && <p className="text-sm text-muted-foreground">No drills yet.</p>}
                    {drills.map((d) => (
                        <details key={d.id} className="surface p-4 text-sm">
                            <summary className="flex cursor-pointer flex-wrap items-center gap-3">
                                <Status value={d.status === 'passed' ? 'resolved' : d.status === 'running' ? 'pending' : 'failed'} />
                                <span>{formatDateTime(d.started_at)}</span>
                                <span className="text-muted-foreground">recovery time {dur(d.rto_seconds)} · data at risk {dur(d.rpo_seconds)}</span>
                            </summary>
                            <ul className="mt-3 grid gap-1">
                                {d.steps.map((s, i) => <li key={i}><b className={s.ok === false ? 'text-status-critical' : s.ok ? 'text-status-good' : 'text-muted-foreground'}>{s.ok === null ? 'Skipped' : s.ok ? 'Passed' : 'Failed'}</b> {s.step} ({s.seconds} s){s.detail ? ` — ${s.detail}` : ''}</li>)}
                            </ul>
                        </details>
                    ))}
                </section>
            </div>
        </>
    );
}

Health.layout = { breadcrumbs: [{ title: 'System health', href: '/platform/health' }] };
