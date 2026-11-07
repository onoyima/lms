import { Pause, Play } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

type Rec = { id: number; kind: string; status: string; duration_seconds: number | null; started_at: string | null };

/**
 * Plays an attempt's camera, phone/desk camera and screen recordings side by side on one clock
 * (Target 144). Each recording started at its own moment; offsets keep them lined up.
 */
export function SyncedRecordings({ recordings, markers = [] }: { recordings: Rec[]; markers?: { at: string; label: string }[] }) {
    const ready = recordings.filter((r) => r.status === 'complete' && r.started_at);
    const t0 = useMemo(() => Math.min(...ready.map((r) => new Date(r.started_at!).getTime())), [ready]);
    const offsets = useMemo(() => ready.map((r) => (new Date(r.started_at!).getTime() - t0) / 1000), [ready, t0]);
    const total = useMemo(() => Math.max(1, ...ready.map((r, i) => offsets[i] + (r.duration_seconds ?? 0))), [ready, offsets]);
    const refs = useRef<(HTMLVideoElement | null)[]>([]);
    const [time, setTime] = useState(0);
    const [playing, setPlaying] = useState(false);
    const clock = useRef<{ start: number; from: number } | null>(null);

    const place = (t: number, play: boolean) => {
        refs.current.forEach((v, i) => {
            if (!v) {
                return;
            }

            const local = t - offsets[i];
            const inside = local >= 0 && (!ready[i].duration_seconds || local <= ready[i].duration_seconds!);

            if (Math.abs(v.currentTime - Math.max(0, local)) > 0.35) {
                v.currentTime = Math.max(0, local);
            }

            if (play && inside) {
                void v.play().catch(() => {});
            } else {
                v.pause();
            }
        });
    };

    useEffect(() => {
        if (!playing) {
            return;
        }

        clock.current = { start: performance.now(), from: time };
        let raf = 0;
        let lastSync = 0;
        const tick = (now: number) => {
            const t = Math.min(total, clock.current!.from + (now - clock.current!.start) / 1000);
            setTime(t);

            if (now - lastSync > 1000) {
                lastSync = now;
                place(t, true); // nudge any video that drifted
            }

            if (t >= total) {
                setPlaying(false);
            } else {
                raf = requestAnimationFrame(tick);
            }
        };
        place(time, true);
        raf = requestAnimationFrame(tick);

        return () => cancelAnimationFrame(raf);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [playing]);

    if (ready.length === 0) {
        return null;
    }

    const fmt = (s: number) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;

    return (
        <div className="grid gap-2">
            <div className={`grid gap-2 ${ready.length > 1 ? 'grid-cols-2' : ''}`}>
                {ready.map((r, i) => (
                    <figure key={r.id} className="relative">
                        <video ref={(el) => {
 refs.current[i] = el; 
}} src={`/proctor/recordings/${r.id}`} preload="metadata" muted={i > 0} playsInline className="aspect-video w-full rounded-lg bg-black" />
                        <figcaption className="absolute top-1 left-1 rounded bg-black/60 px-1 text-[10px] text-white">{r.kind}</figcaption>
                    </figure>
                ))}
            </div>
            <div className="flex items-center gap-2">
                <button type="button" aria-label={playing ? 'Pause' : 'Play'} className="rounded-full border p-1.5" onClick={() => {
                    if (playing) {
                        place(time, false);
                    }

                    setPlaying(!playing);
                }}>
                    {playing ? <Pause className="size-4" /> : <Play className="size-4" />}
                </button>
                <div className="relative flex-1">
                    <input type="range" aria-label="Position" min={0} max={total} step={0.5} value={time} className="w-full" onChange={(e) => {
                        const t = Number(e.target.value);
                        setTime(t);
                        clock.current = { start: performance.now(), from: t };
                        place(t, playing);
                    }} />
                    {markers.map((m) => {
                        const at = (new Date(m.at).getTime() - t0) / 1000;

                        return at >= 0 && at <= total ? (
                            <button key={m.at + m.label} type="button" title={m.label} aria-label={`Go to ${m.label}`} className="absolute top-0 h-2 w-1 -translate-x-1/2 rounded bg-status-critical"
                                style={{ left: `${(at / total) * 100}%` }} onClick={() => {
 setTime(at); clock.current = { start: performance.now(), from: at }; place(at, playing); 
}} />
                        ) : null;
                    })}
                </div>
                <span className="w-24 text-right text-xs tabular-nums">{fmt(time)} / {fmt(total)}</span>
            </div>
        </div>
    );
}
