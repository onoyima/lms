import { useMemo, useRef, useState } from 'react';

type Point = { t: string; v: number | null };

/**
 * One measure over time (small multiples: one chart per measure, one axis each).
 * 2px line in the brand colour, recessive grid, optional dashed alert threshold,
 * crosshair + tooltip on hover/focus, and a text summary for screen readers.
 */
export function MetricChart({ title, unit = '', points, threshold, max, height = 120 }: { title: string; unit?: string; points: Point[]; threshold?: number; max?: number; height?: number }) {
    const ref = useRef<SVGSVGElement>(null);
    const [hover, setHover] = useState<number | null>(null);
    const W = 600;
    const H = height;
    const pad = { l: 56, r: 8, t: 8, b: 18 };
    const vals = points.map((p) => p.v).filter((v): v is number => v !== null);
    const top = useMemo(() => {
        const m = Math.max(max ?? 0, threshold ?? 0, ...vals, 1);
        const mag = 10 ** Math.floor(Math.log10(m));

        return Math.ceil(m / mag) * mag;
    }, [vals, max, threshold]);
    const x = (i: number) => pad.l + (points.length <= 1 ? 0 : (i / (points.length - 1)) * (W - pad.l - pad.r));
    const y = (v: number) => pad.t + (1 - v / top) * (H - pad.t - pad.b);
    const path = points.reduce((d, p, i) => (p.v === null ? d : d + `${d === '' || points[i - 1]?.v === null ? 'M' : 'L'}${x(i).toFixed(1)},${y(p.v).toFixed(1)}`), '');
    const last = [...points].reverse().find((p) => p.v !== null);
    const fmt = (v: number | null) => (v === null ? '—' : `${Number.isInteger(v) ? v : v.toFixed(1)}${unit}`);
    const time = (t: string) => new Date(t).toLocaleString(undefined, { hour: '2-digit', minute: '2-digit', day: points.length > 200 ? 'numeric' : undefined, month: points.length > 200 ? 'short' : undefined });
    const move = (clientX: number) => {
        const r = ref.current?.getBoundingClientRect();

        if (!r || points.length === 0) {
            return;
        }

        const px = ((clientX - r.left) / r.width) * W;
        setHover(Math.max(0, Math.min(points.length - 1, Math.round(((px - pad.l) / (W - pad.l - pad.r)) * (points.length - 1)))));
    };
    const h = hover !== null ? points[hover] : null;

    return (
        <figure className="surface grid gap-1 p-4">
            <figcaption className="flex items-baseline justify-between gap-2 text-sm">
                <span className="font-medium">{title}</span>
                <span className="tabular-nums text-muted-foreground">{h ? `${time(h.t)} · ${fmt(h.v)}` : last ? `now ${fmt(last.v)}` : 'no data yet'}</span>
            </figcaption>
            <svg ref={ref} viewBox={`0 0 ${W} ${H}`} className="h-auto w-full touch-none" role="img" tabIndex={0}
                aria-label={`${title}: ${vals.length ? `latest ${fmt(last?.v ?? null)}, highest ${fmt(Math.max(...vals))}` : 'no data yet'}`}
                onMouseMove={(e) => move(e.clientX)} onMouseLeave={() => setHover(null)} onTouchMove={(e) => move(e.touches[0].clientX)}
                onKeyDown={(e) => {
                    if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                        setHover((i) => Math.max(0, Math.min(points.length - 1, (i ?? points.length - 1) + (e.key === 'ArrowLeft' ? -1 : 1))));
                    }
                }}>
                {[0, 0.5, 1].map((f) => (
                    <g key={f}>
                        <line x1={pad.l} x2={W - pad.r} y1={y(top * f)} y2={y(top * f)} stroke="currentColor" strokeOpacity={0.08} />
                        <text x={pad.l - 6} y={y(top * f) + 3} textAnchor="end" fontSize="10" fill="currentColor" fillOpacity={0.55}>{top * f}{unit}</text>
                    </g>
                ))}
                {threshold !== undefined && <line x1={pad.l} x2={W - pad.r} y1={y(threshold)} y2={y(threshold)} stroke="var(--status-warning)" strokeDasharray="4 4" strokeWidth={1} />}
                <path d={path} fill="none" stroke="var(--brand)" strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
                {h && h.v !== null && (
                    <>
                        <line x1={x(hover!)} x2={x(hover!)} y1={pad.t} y2={H - pad.b} stroke="currentColor" strokeOpacity={0.3} />
                        <circle cx={x(hover!)} cy={y(h.v)} r={4} fill="var(--brand)" stroke="var(--card)" strokeWidth={2} />
                    </>
                )}
                {points.length > 0 && (
                    <>
                        <text x={pad.l} y={H - 4} fontSize="10" fill="currentColor" fillOpacity={0.55}>{time(points[0].t)}</text>
                        <text x={W - pad.r} y={H - 4} textAnchor="end" fontSize="10" fill="currentColor" fillOpacity={0.55}>{time(points[points.length - 1].t)}</text>
                    </>
                )}
            </svg>
        </figure>
    );
}

export type SeriesPoint = Record<string, number | string | null> & { t: string };

/** The standard set of performance charts, shared by the platform and institution pages. */
export function PerformanceCharts({ series, include }: { series: SeriesPoint[]; include: string[] }) {
    const defs: Record<string, { title: string; unit?: string; threshold?: number; max?: number }> = {
        candidates_writing: { title: 'Candidates writing' },
        answers_saved: { title: 'Answers saved' },
        requests: { title: 'Requests' },
        p95_ms: { title: 'Response time (95% faster than)', unit: ' ms', threshold: 1000 },
        error_pct: { title: 'Server errors', unit: '%', threshold: 2 },
        cpu_pct: { title: 'CPU', unit: '%', threshold: 85, max: 100 },
        memory_pct: { title: 'Memory used', unit: '%', threshold: 90, max: 100 },
        disk_free_pct: { title: 'Disk free', unit: '%', threshold: 15, max: 100 },
        db_connections: { title: 'Database connections' },
        queue_waiting: { title: 'Background jobs waiting' },
    };

    return (
        <div className="grid gap-4 md:grid-cols-2">
            {include.map((k) => (
                <MetricChart key={k} {...defs[k]} points={series.map((p) => ({ t: p.t, v: p[k] === null || p[k] === undefined ? null : Number(p[k]) }))} />
            ))}
        </div>
    );
}
