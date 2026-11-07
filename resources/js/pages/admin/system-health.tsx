import { Head, router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import { PerformanceCharts } from '@/components/metric-chart';
import type { SeriesPoint } from '@/components/metric-chart';
import { Field, Flash, formatDateTime, inputClass, PageHeader, Stat, Status, Table, Td } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Device = { id: number; name: string; kind: string; host: string; port: number; venue_id: number | null; status: string; latency_ms: number | null; last_checked_at: string | null; last_up_at: string | null; history: (number | null)[] };
type Props = {
    range: string; series: SeriesPoint[]; current: Record<string, number | string>; devices: Device[]; venues: { id: number; name: string; campus: string | null }[];
    kinds: string[]; ports: number[]; tab: string; computers: { venue_id: number | null; total: number; online: number }[];
};

/** A one-hour strip: one tick per check, height = latency, gap = no answer. */
function Strip({ history }: { history: (number | null)[] }) {
    const max = Math.max(50, ...history.filter((x): x is number => x !== null));

    return (
        <svg viewBox={`0 0 ${Math.max(1, history.length) * 4} 20`} className="h-5 w-32" aria-hidden>
            {history.map((v, i) => v === null
                ? <rect key={i} x={i * 4} y={0} width={3} height={20} fill="var(--status-critical)" opacity={0.6} />
                : <rect key={i} x={i * 4} y={20 - Math.max(2, (v / max) * 20)} width={3} height={Math.max(2, (v / max) * 20)} fill="var(--brand)" rx={1} />)}
        </svg>
    );
}

/** Exam-day performance and exam-hall network for the institution (Target 162, LMS 243). */
export default function SystemHealth(p: Props) {
    const [tab, setTab] = useState(p.tab === 'network' ? 'network' : 'performance');
    const form = useForm({ name: '', kind: 'switch', host: '', port: 80, venue_id: '' as string | number });
    const venue = (id: number | null) => p.venues.find((v) => v.id === id)?.name ?? 'Whole campus';
    const down = p.devices.filter((d) => d.status === 'down').length;

    return (
        <>
            <Head title="System health" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title="System health" lead="Watch the service during exams, and the network equipment each hall depends on. Figures are for the whole service and contain no one's records." />
                <Flash />
                <div className="flex gap-2">
                    <Button variant={tab === 'performance' ? 'default' : 'outline'} onClick={() => setTab('performance')}>Exam-day performance</Button>
                    <Button variant={tab === 'network' ? 'default' : 'outline'} onClick={() => setTab('network')}>Exam-hall network {down > 0 && <span className="ml-1 rounded-full bg-status-critical px-1.5 text-xs text-white">{down}</span>}</Button>
                </div>

                {tab === 'performance' ? (
                    <>
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="grid flex-1 gap-4 sm:grid-cols-4">
                                <Stat label="Candidates writing" value={p.current.candidates_writing ?? '—'} />
                                <Stat label="Answers saved last minute" value={p.current.answers_saved ?? '—'} />
                                <Stat label="Response time p95" value={p.current.p95_ms ? `${p.current.p95_ms} ms` : '—'} tone={Number(p.current.p95_ms) > 1000 ? 'warning' : undefined} />
                                <Stat label="Server errors" value={`${p.current.error_pct ?? 0}%`} tone={Number(p.current.error_pct) > 2 ? 'critical' : undefined} />
                            </div>
                        </div>
                        <div className="flex gap-2">{['1h', '24h', '7d'].map((r) => <Button key={r} size="sm" variant={p.range === r ? 'default' : 'outline'} onClick={() => router.get('/admin/system-health', { range: r }, { preserveScroll: true })}>{r === '1h' ? 'Last hour' : r === '24h' ? '24 hours' : '7 days'}</Button>)}</div>
                        <PerformanceCharts series={p.series} include={['candidates_writing', 'answers_saved', 'p95_ms', 'error_pct', 'cpu_pct', 'memory_pct']} />
                    </>
                ) : (
                    <>
                        <form className="surface grid gap-3 p-5 md:grid-cols-6" onSubmit={(e) => {
 e.preventDefault(); form.post('/admin/system-health/devices', { preserveScroll: true, onSuccess: () => form.reset() }); 
}}>
                            <Field label="Name" error={form.errors.name}><input className={inputClass} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required placeholder="Hall A core switch" /></Field>
                            <Field label="Kind"><select className={inputClass} value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value)}>{p.kinds.map((k) => <option key={k} value={k}>{k.replace('_', ' ')}</option>)}</select></Field>
                            <Field label="Address" error={form.errors.host}><input className={inputClass} value={form.data.host} onChange={(e) => form.setData('host', e.target.value)} required placeholder="10.20.1.1" /></Field>
                            <Field label="Port" hint="80/443 web admin, 22 SSH, 53 DNS"><select className={inputClass} value={form.data.port} onChange={(e) => form.setData('port', Number(e.target.value))}>{p.ports.map((x) => <option key={x}>{x}</option>)}</select></Field>
                            <Field label="Hall"><select className={inputClass} value={form.data.venue_id} onChange={(e) => form.setData('venue_id', e.target.value)}><option value="">Whole campus</option>{p.venues.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}</select></Field>
                            <div className="flex items-end gap-2"><Button disabled={form.processing}>Add</Button><Button type="button" variant="outline" onClick={() => router.post('/admin/system-health/probe', {}, { preserveScroll: true })}>Check now</Button></div>
                        </form>
                        <Table head={['Device', 'Hall', 'Status', 'Latency', 'Last hour', 'Last answered', '']}>
                            {p.devices.length === 0 && <tr><Td className="text-muted-foreground">Add each hall's switch, wireless access point and the internet router. They are checked every minute.</Td></tr>}
                            {p.devices.map((d) => (
                                <tr key={d.id}>
                                    <Td className="font-medium">{d.name}<span className="block font-mono text-xs text-muted-foreground">{d.kind.replace('_', ' ')} · {d.host}:{d.port}</span></Td>
                                    <Td>{venue(d.venue_id)}</Td>
                                    <Td><Status value={d.status === 'up' ? 'active' : d.status === 'slow' ? 'pending' : d.status === 'down' ? 'offline' : null} />{d.status === 'unknown' && <span className="text-xs text-muted-foreground">not checked</span>}</Td>
                                    <Td className="tabular-nums">{d.latency_ms !== null ? `${d.latency_ms} ms` : '—'}</Td>
                                    <Td><Strip history={d.history} /></Td>
                                    <Td>{formatDateTime(d.last_up_at)}</Td>
                                    <Td><Button size="icon" variant="ghost" aria-label={`Remove ${d.name}`} onClick={() => confirm(`Stop checking ${d.name}?`) && router.delete(`/admin/system-health/devices/${d.id}`, { preserveScroll: true })}><Trash2 className="size-4" /></Button></Td>
                                </tr>
                            ))}
                        </Table>
                        <section className="grid gap-2">
                            <h2 className="text-lg font-medium">Approved exam computers online</h2>
                            <div className="grid gap-3 sm:grid-cols-3">
                                {p.computers.length === 0 && <p className="text-sm text-muted-foreground">No approved hall computers yet.</p>}
                                {p.computers.map((c) => <Stat key={String(c.venue_id)} label={venue(c.venue_id)} value={`${c.online} / ${c.total}`} tone={c.online < c.total ? 'warning' : 'good'} />)}
                            </div>
                        </section>
                    </>
                )}
            </div>
        </>
    );
}

SystemHealth.layout = { breadcrumbs: [{ title: 'System health', href: '/admin/system-health' }] };
