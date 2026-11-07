import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Hand, Lock, MessageSquare, Pause, Play, Search, Timer, Unlock, WifiOff, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { IncidentContacts } from '@/components/incident-contacts';
import type { IncidentContact } from '@/components/incident-contacts';
import { formatClock, sendJson, Status } from '@/components/platform/ui';
import { SyncedRecordings } from '@/components/synced-recordings';
import { Button } from '@/components/ui/button';
import { readStats, watch } from '@/lib/live';
import type { LinkStats, Tile } from '@/lib/live';

type AttemptRow = {
    id: number; status: string; channel: string; client_status: string; remaining: number; answered: number; total: number; violations: number; risk: number;
    last_seen: string | null; snapshot_id: number | null; incidents: number; help: { id: number; body: string }[];
};
type Row = { candidate_id: number; name: string; identifier: string | null; seat: string | null; attendance: string; checked_in: boolean; eligibility: string; attempt: AttemptRow | null };
type Live = { server_time: string; session: { paused: boolean; status: string }; summary: Record<string, number>; proctors_offline: string[]; candidates: Row[] };
type Timeline = { events: { id: number; type: string; severity: number; created_at: string; data: unknown }[]; messages: { id: number; direction: string; kind: string; body: string; created_at: string; acknowledged_at: string | null }[]; evidence: { id: number; kind: string; captured_at: string }[]; incidents: { id: number; title: string; severity: string; status: string }[];
    recordings?: { id: number; kind: string; status: string; duration_seconds: number | null; started_at: string | null }[];
    identity?: { checks: { id: number; kind: string; result: string; evidence_file_id: number | null; created_at: string }[]; reference: { status: string; enrolment: boolean; id: boolean } | null } };

/** Live proctor console: refreshes every 5 seconds; highest-risk and help requests first. */
/** One remote track rendered in place; audio plays only when a proctor chooses to listen. */
function LiveTrack({ tile, muted = true, className }: { tile: Tile; muted?: boolean; className?: string }) {
    const ref = useRef<HTMLVideoElement & HTMLAudioElement>(null);

    useEffect(() => {
        const el = ref.current;

        if (!el) {
            return;
        }

        tile.track.attach(el);

        return () => {
            tile.track.detach(el);
        };
    }, [tile.track]);

    return tile.track.kind === 'audio' ? <audio ref={ref} autoPlay muted={muted} /> : <video ref={ref} autoPlay playsInline muted className={className} />;
}

/** Bitrate and latency of one live stream, refreshed every few seconds (Target 146). */
function LinkFigures({ tile }: { tile: Tile }) {
    const [s, setS] = useState<LinkStats | null>(null);

    useEffect(() => {
        let mark: { bytes: number; at: number } | undefined;
        let alive = true;
        const tick = async () => {
            try {
                const r = await readStats(tile.track, mark);
                mark = r.mark;

                if (alive) {
                    setS(r.stats);
                }
            } catch {
                /* stats not available in this browser */
            }
        };
        tick();
        const t = window.setInterval(tick, 4000);

        return () => {
            alive = false;
            window.clearInterval(t);
        };
    }, [tile.track]);

    if (!s || (s.kbps === null && s.rttMs === null)) {
        return null;
    }

    const bad = (s.rttMs ?? 0) > 400 || (s.lossPct ?? 0) > 5 || (s.kbps !== null && s.kbps < 100);

    return (
        <span title={`Jitter ${s.jitterMs ?? '–'} ms, loss ${s.lossPct ?? '–'}%`} className={`absolute right-1 bottom-1 rounded px-1 text-[10px] tabular-nums ${bad ? 'bg-status-warning text-black' : 'bg-black/60 text-white'}`}>
            {s.kbps ?? '–'} kbps · {s.rttMs ?? '–'} ms
        </span>
    );
}

type Layout = { columns: number; cameras: boolean; sort: 'risk' | 'seat' | 'name' | 'progress'; only_flagged: boolean; compact: boolean };
const GRID: Record<number, string> = { 2: 'sm:grid-cols-2', 3: 'sm:grid-cols-2 lg:grid-cols-3', 4: 'sm:grid-cols-3 lg:grid-cols-4', 6: 'sm:grid-cols-4 lg:grid-cols-6', 8: 'sm:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8' };

export default function Console({ session, canManage, isChief, layout: initialLayout, training, contacts = [] }: { session: { id: number; exam: string; name: string | null; venue: string | null; mode: string; starts_at: string; ends_at: string }; canManage: boolean; isChief: boolean; layout: Layout; training: { tasks: Record<string, string>; done: string[] } | null; contacts?: IncidentContact[] }) {
    const [layout, setLayoutState] = useState<Layout>(initialLayout);
    const [tiles, setTiles] = useState<Tile[]>([]);
    const [liveMode, setLiveMode] = useState<'off' | 'connecting' | 'on' | 'unavailable'>('off');
    const [listening, setListening] = useState<string | null>(null);
    const liveRoom = useRef<{ disconnect: () => Promise<void> } | null>(null);
    const toggleLive = async () => {
        if (liveRoom.current) {
            await liveRoom.current.disconnect();
            liveRoom.current = null;
            setTiles([]);
            setLiveMode('off');

            return;
        }

        setLiveMode('connecting');
        const res = await fetch(`/proctor/sessions/${session.id}/media-token`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const data = res.ok ? ((await res.json()) as { enabled: boolean; url?: string; token?: string }) : null;

        if (!data?.enabled || !data.url || !data.token) {
            setLiveMode('unavailable');

            return;
        }

        try {
            liveRoom.current = await watch(data.url, data.token, setTiles);
            setLiveMode('on');
        } catch {
            setLiveMode('unavailable');
        }
    };
    useEffect(() => () => void liveRoom.current?.disconnect(), []);
    const tileFor = (attemptId: number | undefined, source: string, phone = false) =>
        attemptId ? tiles.find((t) => t.identity === `c-${attemptId}${phone ? '-phone' : ''}` && t.source === source) : undefined;
    const setLayout = (patch: Partial<Layout>) => {
        const next = { ...layout, ...patch };
        setLayoutState(next);
        sendJson('/proctor/layout', 'PUT', next);
    };
    const [live, setLive] = useState<Live | null>(null);
    const [filter, setFilter] = useState('');
    const [selected, setSelected] = useState<Row | null>(null);
    const [timeline, setTimeline] = useState<Timeline | null>(null);
    const [error, setError] = useState<string | null>(null);

    const refresh = useCallback(async () => {
        const res = await fetch(`/proctor/sessions/${session.id}/live${canManage ? '?all=1' : ''}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

        if (res.ok) {
            setLive(await res.json());
            setError(null);
        } else {
            setError('Lost connection to the server — retrying.');
        }
    }, [session.id, canManage]);

    useEffect(() => {
        const first = window.setTimeout(refresh, 0);
        const t = window.setInterval(refresh, 5000);

        return () => (window.clearTimeout(first), window.clearInterval(t));
    }, [refresh]);

    useEffect(() => {
        if (!selected?.attempt) {
            return;
        }

        fetch(`/proctor/attempts/${selected.attempt.id}/timeline`, { headers: { Accept: 'application/json' } }).then(async (r) => r.ok && setTimeline(await r.json()));
    }, [selected, live]);

    const act = async (attemptId: number, action: string, extra: Record<string, unknown> = {}) => {
        await sendJson(`/proctor/attempts/${attemptId}/action`, 'POST', { action, ...extra });
        refresh();

        if (training) {
            router.reload({ only: ['training'] });
        }
    };
    const sessionAct = async (action: string) => {
        const reason = action === 'pause' ? prompt('Reason for pausing everyone (logged as an incident):') : null;

        if (action === 'pause' && !reason) {
            return;
        }

        if (action === 'close' && !confirm('Close the sitting and submit every open attempt now?')) {
            return;
        }

        await sendJson(`/proctor/sessions/${session.id}/action`, 'POST', { action, reason });
        refresh();
    };

    const rows = useMemo(() => {
        const list = (live?.candidates ?? [])
            .filter((r) => !filter || `${r.name} ${r.identifier} ${r.seat}`.toLowerCase().includes(filter.toLowerCase()))
            .filter((r) => !layout.only_flagged || (r.attempt && (r.attempt.help.length > 0 || r.attempt.risk >= 40 || r.attempt.incidents > 0 || r.attempt.status === 'locked' || r.attempt.client_status === 'offline')));
        const by: Record<Layout['sort'], (a: Row, b: Row) => number> = {
            risk: () => 0, // the server already orders help requests and risk first
            seat: (a, b) => (a.seat ?? '').localeCompare(b.seat ?? '', undefined, { numeric: true }),
            name: (a, b) => a.name.localeCompare(b.name),
            progress: (a, b) => (a.attempt ? a.attempt.answered / Math.max(1, a.attempt.total) : -1) - (b.attempt ? b.attempt.answered / Math.max(1, b.attempt.total) : -1),
        };

        return [...list].sort(by[layout.sort]);
    }, [live, filter, layout.only_flagged, layout.sort]);

    const [clock, setClock] = useState(() => new Date());
    useEffect(() => {
        const t = window.setInterval(() => setClock(new Date()), 1000);

        return () => window.clearInterval(t);
    }, []);
    const summary = live?.summary;

    return (
        <>
            <Head title={`Monitoring — ${session.exam}`} />
            <div className="flex min-h-full flex-col bg-[#0d0f12] text-[#e7e9ec]">
                {/* top bar: what is being watched, the room totals and the controls */}
                <div className="sticky top-0 z-20 grid gap-2 border-b border-white/10 bg-[#0d0f12]/95 px-4 py-3 backdrop-blur md:px-6">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="min-w-0">
                            <h1 className="truncate text-base font-semibold text-white">{session.exam}</h1>
                            <p className="text-xs text-white/50">
                                {[session.name, session.venue ?? (session.mode === 'remote' ? 'Online' : null)].filter(Boolean).join(' · ')}
                                {' · '}
                                <span className="tabular-nums">{clock.toLocaleTimeString()}</span>
                                {error && <span className="ml-2 text-[#fbbf24]">{error}</span>}
                            </p>
                        </div>
                        {summary && (
                            <dl className="flex flex-wrap gap-x-5 gap-y-1 text-xs">
                                {[
                                    ['Expected', summary.expected, ''],
                                    ['Present', summary.present, ''],
                                    ['Writing', summary.writing, 'text-[#4ade80]'],
                                    ['Submitted', summary.submitted, ''],
                                    ['Offline', summary.offline, summary.offline ? 'text-[#f87171]' : ''],
                                    ['Alerts', summary.alerts, summary.alerts ? 'text-[#fbbf24]' : ''],
                                ].map(([label, value, tone]) => (
                                    <div key={label as string} className="flex items-baseline gap-1.5">
                                        <dt className="text-white/50">{label}</dt>
                                        <dd className={`text-sm font-semibold tabular-nums text-white ${tone}`}>{value}</dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                        {canManage && (
                            <div className="flex flex-wrap gap-2">
                                {live?.session.paused ? (
                                    <Button size="sm" onClick={() => sessionAct('resume')}><Play className="size-4" /> Resume everyone</Button>
                                ) : (
                                    <Button size="sm" variant="outline" className="border-white/20 bg-transparent text-white hover:bg-white/10" onClick={() => sessionAct('pause')}><Pause className="size-4" /> Emergency pause</Button>
                                )}
                                <Button size="sm" variant="outline" className="border-white/20 bg-transparent text-white hover:bg-white/10" onClick={() => sessionAct('close')}>Close sitting</Button>
                                <Button size="sm" variant="outline" className="border-white/20 bg-transparent text-white hover:bg-white/10" asChild><Link href="/incidents">Incidents</Link></Button>
                            </div>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center gap-3 text-xs text-white/70">
                        <div className="relative w-full max-w-xs">
                            <Search className="absolute top-2 left-2.5 size-3.5 text-white/40" />
                            <input className="h-8 w-full rounded-md border border-white/15 bg-white/5 pr-2 pl-8 text-xs text-white placeholder:text-white/40 focus:border-white/40 focus:outline-none" placeholder="Name, matric number or seat" value={filter} onChange={(e) => setFilter(e.target.value)} />
                        </div>
                        <label className="flex items-center gap-1.5">
                            Screens across
                            <select className="h-8 rounded-md border border-white/15 bg-[#16191d] px-1.5 text-white" value={layout.columns} onChange={(e) => setLayout({ columns: Number(e.target.value) })}>
                                {[2, 3, 4, 6, 8].map((n) => <option key={n} value={n}>{n}</option>)}
                            </select>
                        </label>
                        <label className="flex items-center gap-1.5">
                            Order
                            <select className="h-8 rounded-md border border-white/15 bg-[#16191d] px-1.5 text-white" value={layout.sort} onChange={(e) => setLayout({ sort: e.target.value as Layout['sort'] })}>
                                <option value="risk">Needs attention first</option>
                                <option value="seat">Seat</option>
                                <option value="name">Name</option>
                                <option value="progress">Progress</option>
                            </select>
                        </label>
                        <label className="flex items-center gap-1.5"><input type="checkbox" checked={layout.cameras} onChange={(e) => setLayout({ cameras: e.target.checked })} /> Pictures</label>
                        <label className="flex items-center gap-1.5"><input type="checkbox" checked={layout.only_flagged} onChange={(e) => setLayout({ only_flagged: e.target.checked })} /> Needs attention only</label>
                        <label className="flex items-center gap-1.5"><input type="checkbox" checked={layout.compact} onChange={(e) => setLayout({ compact: e.target.checked })} /> Hide details</label>
                        <Button size="sm" className={liveMode === 'on' ? 'bg-[#dc2626] text-white hover:bg-[#b91c1c]' : 'border border-white/20 bg-transparent text-white hover:bg-white/10'} onClick={toggleLive} disabled={liveMode === 'connecting'}>
                            <span className={`size-2 rounded-full ${liveMode === 'on' ? 'animate-pulse bg-white' : 'bg-white/40'}`} />
                            {liveMode === 'on' ? `Live · ${new Set(tiles.map((t) => t.identity)).size} streams` : liveMode === 'connecting' ? 'Connecting…' : 'Watch live'}
                        </Button>
                        {liveMode === 'unavailable' && <span className="text-[#fbbf24]">Live video is not set up — showing the latest pictures.</span>}
                        <details className="ml-auto">
                            <summary className="cursor-pointer text-white/50">Who to call</summary>
                            <div className="absolute right-4 z-30 mt-2 max-w-md rounded-lg border border-white/10 bg-[#16191d] p-3"><IncidentContacts contacts={contacts} /></div>
                        </details>
                    </div>
                </div>

                {training && (
                    <div className="mx-4 mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-white/15 p-3 text-sm md:mx-6">
                        <div className="grid gap-1">
                            <p className="text-xs text-white/60">Training sandbox · simulated candidates, nothing here is real</p>
                            <p className="flex flex-wrap gap-3">
                                {Object.entries(training.tasks).map(([k, label]) => (
                                    <span key={k} className={training.done.includes(k) ? 'text-[#4ade80]' : 'text-white/50'}>
                                        {training.done.includes(k) ? '✓' : '○'} {label}
                                    </span>
                                ))}
                            </p>
                        </div>
                        <Button size="sm" variant="outline" className="border-white/20 bg-transparent text-white" onClick={() => router.delete(`/proctor/training/${session.id}`)}>
                            {Object.keys(training.tasks).every((k) => training.done.includes(k)) ? 'Finish training' : 'Leave sandbox'}
                        </Button>
                    </div>
                )}
                {live?.session.paused && <div className="mx-4 mt-3 rounded-md bg-[#92400e] px-3 py-2 text-sm text-white md:mx-6">The sitting is paused. Candidates' clocks are stopped.</div>}
                {!!live?.proctors_offline.length && (
                    <div className="mx-4 mt-3 flex flex-wrap items-center justify-between gap-2 rounded-md bg-[#7f1d1d] px-3 py-2 text-sm text-white md:mx-6">
                        <span>Proctors not connected: {live.proctors_offline.join(', ')}</span>
                        {(canManage || isChief) && (
                            <Button size="sm" variant="outline" className="border-white/30 bg-transparent text-white" onClick={() => sessionAct('cover')}>Give their candidates to online proctors</Button>
                        )}
                    </div>
                )}

                {/* the wall */}
                <div className={`grid grid-cols-2 gap-1 p-1 md:p-2 ${GRID[layout.columns] ?? GRID[4]}`}>
                    {rows.map((r) => {
                        const a = r.attempt;
                        const help = (a?.help.length ?? 0) > 0;
                        const offline = a?.client_status === 'offline';
                        const alert = help || a?.status === 'locked' || (a?.risk ?? 0) >= 40 || (a?.incidents ?? 0) > 0;
                        const done = a && ['submitted', 'auto_submitted', 'terminated'].includes(a.status);
                        const cam = layout.cameras ? tileFor(a?.id, 'camera') : undefined;
                        const extras = layout.cameras ? [tileFor(a?.id, 'camera', true), tileFor(a?.id, 'usb_camera'), tileFor(a?.id, 'screen_share')].filter(Boolean) as Tile[] : [];
                        const progress = a && a.total ? Math.round((a.answered / a.total) * 100) : 0;
                        const dot = !a ? 'bg-white/30' : done ? 'bg-white/50' : offline ? 'bg-[#f87171]' : a.status === 'locked' || a.status === 'paused' ? 'bg-[#fbbf24]' : 'bg-[#4ade80]';
                        const state = !a ? (r.attendance === 'present' ? 'present, not started' : r.attendance) : done ? 'submitted' : offline ? `no contact ${a.last_seen ?? ''}` : a.status === 'in_progress' ? 'writing' : a.status;

                        return (
                            <button
                                key={r.candidate_id}
                                onClick={() => setSelected(r)}
                                className={`group relative aspect-video overflow-hidden rounded-sm bg-[#16191d] text-left outline-none ${alert ? 'ring-2 ring-[#ef4444]' : offline ? 'ring-2 ring-[#f59e0b]/70' : ''} ${selected?.candidate_id === r.candidate_id ? 'ring-2 ring-white' : ''} ${done ? 'opacity-60' : ''}`}
                                aria-label={`${r.name}, ${r.identifier ?? ''}, ${state}`}
                            >
                                {cam ? (
                                    <LiveTrack tile={cam} className="absolute inset-0 h-full w-full object-cover" />
                                ) : layout.cameras && a?.snapshot_id ? (
                                    <img src={`/proctor/evidence/${a.snapshot_id}`} alt="" className="absolute inset-0 h-full w-full object-cover" loading="lazy" />
                                ) : (
                                    <div className="absolute inset-0 flex items-center justify-center">
                                        <span className="flex size-14 items-center justify-center rounded-full bg-white/10 text-lg font-semibold text-white/70">
                                            {r.name.split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase()}
                                        </span>
                                    </div>
                                )}
                                {extras.length > 0 && (
                                    <div className="absolute top-7 right-1 grid w-1/4 gap-1">
                                        {extras.map((t) => <LiveTrack key={`${t.identity}${t.source}`} tile={t} className="aspect-video w-full rounded-sm border border-white/20 bg-black object-cover" />)}
                                    </div>
                                )}
                                {cam && <LinkFigures tile={cam} />}

                                {/* top: state and seat; alerts on the right */}
                                <div className="absolute inset-x-0 top-0 flex items-center justify-between gap-2 bg-gradient-to-b from-black/70 to-transparent px-2 pt-1.5 pb-4 text-[11px] text-white">
                                    <span className="flex min-w-0 items-center gap-1.5">
                                        <span className={`size-2 shrink-0 rounded-full ${dot}`} />
                                        <span className="truncate">{state}</span>
                                    </span>
                                    <span className="flex shrink-0 items-center gap-1.5">
                                        {cam && <span className="rounded-sm bg-[#dc2626] px-1 text-[10px] font-semibold">LIVE</span>}
                                        {help && <Hand className="size-3.5 text-[#fbbf24]" aria-label="Asking for help" />}
                                        {a?.status === 'locked' && <Lock className="size-3.5 text-[#fbbf24]" aria-label="Locked" />}
                                        {offline && <WifiOff className="size-3.5 text-[#f87171]" aria-label="Offline" />}
                                        {!!a?.violations && <span className="flex items-center gap-0.5 text-[#fbbf24]"><AlertTriangle className="size-3.5" />{a.violations}</span>}
                                        {r.seat && <span className="rounded-sm bg-white/15 px-1 font-mono">{r.seat}</span>}
                                    </span>
                                </div>

                                {/* bottom: name and matric number, time left and progress */}
                                <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 via-black/60 to-transparent px-2 pt-6 pb-1.5 text-white">
                                    <p className="truncate text-[13px] leading-tight font-semibold">{r.name}</p>
                                    <div className="flex items-center justify-between gap-2 text-[11px] text-white/75">
                                        <span className="truncate font-mono">{r.identifier ?? '—'}</span>
                                        {a && !done && !layout.compact && (
                                            <span className="flex shrink-0 items-center gap-1 tabular-nums"><Timer className="size-3" />{formatClock(a.remaining)} · {a.total ? `${a.answered}/${a.total}` : a.answered}</span>
                                        )}
                                    </div>
                                    {a && !done && <div className="mt-1 h-0.5 w-full bg-white/15"><div className="h-full bg-[#4ade80]" style={{ width: `${progress}%` }} /></div>}
                                </div>
                                {help && !layout.compact && (
                                    <p className="absolute inset-x-2 top-8 line-clamp-2 rounded-sm bg-[#78350f]/90 px-2 py-1 text-[11px] text-white">{a!.help[0].body}</p>
                                )}
                                {liveMode === 'on' && a && tileFor(a.id, 'microphone') && (
                                    <span
                                        role="button"
                                        tabIndex={0}
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            setListening(listening === `c-${a.id}` ? null : `c-${a.id}`);
                                        }}
                                        className="absolute right-1 bottom-12 rounded-sm bg-black/70 px-1.5 py-0.5 text-[10px] text-white"
                                    >
                                        {listening === `c-${a.id}` ? 'Stop listening' : 'Listen'}
                                        <LiveTrack tile={tileFor(a.id, 'microphone')!} muted={listening !== `c-${a.id}`} />
                                    </span>
                                )}
                            </button>
                        );
                    })}
                    {live && rows.length === 0 && <p className="col-span-full p-10 text-center text-sm text-white/50">{layout.only_flagged ? 'Nobody needs attention right now.' : 'No candidates in this sitting.'}</p>}
                </div>

                {selected && (
                    <aside className="fixed inset-y-0 right-0 z-30 flex w-full max-w-md flex-col gap-4 overflow-y-auto border-l border-border bg-card p-6 text-foreground shadow-2xl">
                        <div className="flex items-start justify-between">
                            <div>
                                <p className="eyebrow">Candidate</p>
                                <h2 className="mt-2 text-xl font-semibold">{selected.name}</h2>
                                {selected.attempt && <p className="text-xs text-muted-foreground">{selected.attempt.violations} warnings · risk {selected.attempt.risk} of 100 · {selected.attempt.channel}</p>}
                                <p className="font-mono text-xs text-muted-foreground">{selected.identifier}</p>
                            </div>
                            <button onClick={() => setSelected(null)} aria-label="Close">
                                <X className="size-5" />
                            </button>
                        </div>
                        {selected.attempt ? (
                            <>
                                <div className="flex flex-wrap gap-2">
                                    <Button size="sm" variant="outline" onClick={() => act(selected.attempt!.id, 'warn', { body: prompt('Warning to show the candidate:') || undefined })}>
                                        <AlertTriangle className="size-4" /> Warn
                                    </Button>
                                    <Button size="sm" variant="outline" onClick={() => {
 const body = prompt('Message to the candidate:');

 if (body) {
act(selected.attempt!.id, 'message', { body });
} 
}}>
                                        <MessageSquare className="size-4" /> Message
                                    </Button>
                                    {selected.attempt.status === 'in_progress' && (
                                        <Button size="sm" variant="outline" onClick={() => act(selected.attempt!.id, 'lock', { body: prompt('Reason for locking:') || 'Locked by invigilator' })}>
                                            <Lock className="size-4" /> Lock
                                        </Button>
                                    )}
                                    <Button size="sm" variant="outline" onClick={() => {
                                        const title = prompt('What happened? (opens an incident for review)');

                                        if (title) {
                                            router.post('/incidents', { exam_session_id: session.id, attempt_id: selected.attempt!.id, type: 'malpractice', severity: 'medium', title }, { preserveScroll: true, preserveState: true, onSuccess: () => refresh() });
                                        }
                                    }}>
                                        <Hand className="size-4" /> Open incident
                                    </Button>
                                    {['locked', 'paused'].includes(selected.attempt.status) && (
                                        <Button size="sm" onClick={() => act(selected.attempt!.id, 'unlock')}>
                                            <Unlock className="size-4" /> Unlock / resume
                                        </Button>
                                    )}
                                    {canManage && (
                                        <>
                                            <Button size="sm" variant="outline" onClick={() => {
 const m = Number(prompt('Extra minutes:', '10'));

 if (m > 0) {
act(selected.attempt!.id, 'add_time', { minutes: m, body: prompt('Reason:') || 'Disruption' });
} 
}}>
                                                <Timer className="size-4" /> Add time
                                            </Button>
                                            <Button size="sm" variant="destructive" onClick={() => {
 const body = prompt('Reason for terminating (opens a malpractice case):');

 if (body) {
act(selected.attempt!.id, 'terminate', { body });
} 
}}>
                                                Terminate
                                            </Button>
                                        </>
                                    )}
                                </div>
                                {selected.attempt.help.map((h) => (
                                    <div key={h.id} className="flex items-center justify-between rounded-xl bg-status-warning/15 p-3 text-sm">
                                        {h.body}
                                        <Button size="sm" variant="ghost" onClick={() => act(selected.attempt!.id, 'ack_help', { message_id: h.id })}>
                                            Done
                                        </Button>
                                    </div>
                                ))}
                                {timeline && selected?.attempt && (
                                    <>
                                        {timeline.incidents.length > 0 && (
                                            <div>
                                                <p className="eyebrow mb-2">Incidents</p>
                                                {timeline.incidents.map((i) => (
                                                    <Link key={i.id} href={`/incidents/${i.id}`} className="flex items-center justify-between rounded-lg border border-border p-2 text-sm hover:border-brand/50">
                                                        {i.title} <Status value={i.severity} />
                                                    </Link>
                                                ))}
                                            </div>
                                        )}
                                        {!!timeline.recordings?.length && (
                                            <div className="grid gap-1 text-xs">
                                                <p className="eyebrow">Recordings</p>
                                                {timeline.recordings.filter((r) => r.status === 'complete').length > 0 && (
                                                    <SyncedRecordings recordings={timeline.recordings} markers={timeline.events.filter((e) => e.severity >= 3).map((e) => ({ at: e.created_at, label: e.type.replace(/_/g, ' ') }))} />
                                                )}
                                                {timeline.recordings.map((r, i, all) => (
                                                    <p key={r.id} className="flex items-center justify-between">
                                                        <span>{r.kind} · part {all.filter((x) => x.kind === r.kind).length - all.slice(0, i).filter((x) => x.kind === r.kind).length}{r.started_at ? ` · from ${new Date(r.started_at).toLocaleTimeString()}` : ''}{r.duration_seconds ? ` · ${Math.round(r.duration_seconds / 60)} min` : ''}</span>
                                                        {r.status === 'complete' ? <a className="text-brand" href={`/proctor/recordings/${r.id}`} target="_blank" rel="noreferrer">Play</a> : <Status value={r.status} />}
                                                    </p>
                                                ))}
                                            </div>
                                        )}
                                        {timeline.identity && (timeline.identity.checks.length > 0 || timeline.identity.reference) && (
                                            <div className="grid gap-2">
                                                <p className="eyebrow">Identity</p>
                                                <div className="grid grid-cols-3 gap-2 text-xs">
                                                    {timeline.identity.reference?.id && (
                                                        <figure><img src={`/proctor/attempts/${selected.attempt!.id}/reference/id`} alt="ID photo on record" className="aspect-square w-full rounded-lg object-cover" /><figcaption>ID photo</figcaption></figure>
                                                    )}
                                                    {timeline.identity.reference?.enrolment && (
                                                        <figure><img src={`/proctor/attempts/${selected.attempt!.id}/reference/enrolment`} alt="Enrolled face" className="aspect-square w-full rounded-lg object-cover" /><figcaption>Enrolled ({timeline.identity.reference.status})</figcaption></figure>
                                                    )}
                                                    {timeline.identity.checks.filter((c) => c.evidence_file_id).slice(0, 1).map((c) => (
                                                        <figure key={c.id}><img src={`/proctor/evidence/${c.evidence_file_id}`} alt="Latest identity photo" className="aspect-square w-full rounded-lg object-cover" /><figcaption>Now</figcaption></figure>
                                                    ))}
                                                </div>
                                                {!timeline.identity.reference && <p className="text-xs text-status-warning">No face or ID photo on record — check the candidate's ID card.</p>}
                                                <ul className="grid gap-1 text-xs">
                                                    {timeline.identity.checks.map((c) => (
                                                        <li key={c.id} className="flex justify-between"><span>{c.kind} check</span><Status value={c.result.replace('_', ' ')} /></li>
                                                    ))}
                                                </ul>
                                            </div>
                                        )}
                                        {timeline.evidence.filter((e) => e.kind === 'snapshot').length > 0 && (
                                            <div>
                                                <p className="eyebrow mb-2">Snapshots</p>
                                                <div className="grid grid-cols-3 gap-2">
                                                    {timeline.evidence.filter((e) => e.kind === 'snapshot').slice(0, 9).map((e) => (
                                                        <a key={e.id} href={`/proctor/evidence/${e.id}`} target="_blank" rel="noreferrer">
                                                            <img src={`/proctor/evidence/${e.id}`} alt="" className="aspect-video rounded-md object-cover" loading="lazy" />
                                                        </a>
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                        <div>
                                            <p className="eyebrow mb-2">Timeline</p>
                                            <ol className="space-y-1 font-mono text-xs">
                                                {timeline.events.map((e) => (
                                                    <li key={e.id} className={`flex justify-between gap-3 ${e.severity >= 4 ? 'text-status-critical' : e.severity >= 3 ? 'text-status-warning' : 'text-muted-foreground'}`}>
                                                        <span>{e.type.replace(/_/g, ' ')}</span>
                                                        <span>{new Date(e.created_at).toLocaleTimeString()}</span>
                                                    </li>
                                                ))}
                                            </ol>
                                        </div>
                                    </>
                                )}
                            </>
                        ) : (
                            <p className="text-sm text-muted-foreground">Not started yet.</p>
                        )}
                    </aside>
                )}
            </div>
        </>
    );
}

Console.layout = { breadcrumbs: [{ title: 'Proctoring', href: '/proctor' }, { title: 'Console', href: '#' }] };
