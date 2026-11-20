import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { paperLook } from '@/lib/papers';

export type Sitting = { id: number; exam_id: number; title: string; paper: string | null; type: string; starts_at: string; ends_at: string; where: string; candidates: number; writing: number; submitted: number };
export type Day = { sittings: Sitting[]; candidates: number; writing: number; submitted: number; not_started: number; need_help: number; flagged: number };

const hhmm = (d: Date) => d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });

/** Count up to a number once, when the board first appears (the page's one orchestrated moment). */
function useCountUp(target: number, ms = 900): number {
    const still = typeof window === 'undefined' || !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    const [v, setV] = useState(still ? target : 0);

    useEffect(() => {
        if (still || target === 0) {
            return;
        }

        let raf = 0;
        const t0 = performance.now();
        const tick = (t: number) => {
            const k = Math.min(1, (t - t0) / ms);
            setV(Math.round(target * (1 - Math.pow(1 - k, 3))));

            if (k < 1) {
                raf = requestAnimationFrame(tick);
            }
        };
        raf = requestAnimationFrame(tick);

        return () => cancelAnimationFrame(raf);
    }, [target, ms, still]);

    return still || target === 0 ? target : v;
}

/**
 * Exam day: everyone writing right now on the left; today's sittings on a time line on the right,
 * each bar filled with submitted (blue) and writing (green), and a line for the present moment.
 */
export function ExamDayBoard({ day }: { day: Day }) {
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        const t = window.setInterval(() => setNow(new Date()), 30000);

        return () => window.clearInterval(t);
    }, []);

    // the window shown: today only, from the first start to the last end (at least 8:00–17:00), in whole hours
    const [from, to] = useMemo(() => {
        const day0 = new Date(now);
        day0.setHours(0, 0, 0, 0);
        const dayStart = day0.getTime();
        const dayEnd = dayStart + 24 * 3600000;
        let a = dayStart + 8 * 3600000;
        let b = dayStart + 17 * 3600000;

        for (const s of day.sittings) {
            a = Math.min(a, Math.max(dayStart, new Date(s.starts_at).getTime()));
            b = Math.max(b, Math.min(dayEnd, new Date(s.ends_at).getTime()));
        }

        const h = 3600000;

        return [Math.max(dayStart, Math.floor(a / h) * h), Math.min(dayEnd, Math.ceil(b / h) * h)];
    }, [day.sittings, now]);
    const span = Math.max(3600000, to - from);
    const hours = Math.round(span / 3600000);
    const step = Math.max(1, Math.ceil(hours / 10));
    const ticks = Array.from({ length: Math.floor(hours / step) + 1 }, (_, i) => new Date(from + i * step * 3600000)).filter((t) => t.getTime() < to);
    const pos = (t: number) => `${Math.max(0, Math.min(100, ((t - from) / span) * 100))}%`;
    const nowIn = now.getTime() >= from && now.getTime() <= to;
    const writing = useCountUp(day.writing);

    return (
        <section className="deep-board grid lg:grid-cols-[280px_1fr]" aria-label="Exam day">
            <div className="flex flex-col gap-5 border-b border-white/10 p-6 lg:border-r lg:border-b-0">
                <div>
                    <p className="flex items-center gap-2 text-sm text-[var(--side)]"><span className="live-dot" /> Writing right now</p>
                    <p className="mt-2 font-display text-[3.4rem] leading-none font-semibold tabular-nums">
                        {writing}
                        <span className="ml-2 font-sans text-base font-medium text-[var(--side)]">of {day.candidates} today</span>
                    </p>
                </div>
                <div className="grid grid-cols-2 gap-2.5">
                    {[
                        { n: day.submitted, l: 'Submitted', c: '' },
                        { n: day.not_started, l: 'Not started', c: '' },
                        { n: day.need_help, l: 'Asking for help', c: day.need_help ? 'text-[#ffc75a]' : '' },
                        { n: day.flagged, l: 'Flagged', c: day.flagged ? 'text-[#ff8fa6]' : '' },
                    ].map((x) => (
                        <div key={x.l} className="rounded-2xl border border-white/10 bg-white/[.06] px-3 py-2.5 transition-colors hover:bg-white/10">
                            <b className={`block font-display text-2xl font-semibold tabular-nums ${x.c}`}>{x.n}</b>
                            <span className="text-xs text-[var(--side)]">{x.l}</span>
                        </div>
                    ))}
                </div>
            </div>
            <div className="relative min-w-0 p-6">
                <div className="flex items-center justify-between">
                    <h2 className="text-lg font-semibold">Today's sittings</h2>
                    <Link href="/exam-calendar" className="inline-flex items-center gap-1 text-sm font-medium text-[var(--glow)] hover:underline">Timetable <ArrowUpRight className="size-4" /></Link>
                </div>
                {day.sittings.length === 0 ? (
                    <p className="mt-6 text-sm text-[var(--side)]">No sittings today. Upcoming exams are on the timetable.</p>
                ) : (
                    <div className="mt-2 overflow-x-auto pt-4">
                        <div className="min-w-[620px]">
                            <div className="relative ml-[200px] h-5 text-[11px] text-[var(--side)]/80">
                                {ticks.map((t) => <span key={t.getTime()} className="absolute -translate-x-1/2 whitespace-nowrap" style={{ left: pos(t.getTime()) }}>{t.getHours()}:00</span>)}
                            </div>
                            <div className="relative">
                                {day.sittings.map((s) => {
                                    const a = new Date(s.starts_at).getTime();
                                    const b = new Date(s.ends_at).getTime();
                                    const L = paperLook[s.type] ?? paperLook.cbt;
                                    const total = Math.max(1, s.candidates);
                                    const started = a <= now.getTime();

                                    return (
                                        <Link key={s.id} href={`/proctor/sessions/${s.id}`} className="grid grid-cols-[200px_1fr] items-center rounded-xl py-1.5 transition-colors hover:bg-white/[.05]">
                                            <span className="flex min-w-0 items-center gap-2.5 pr-3 pl-1">
                                                <span className="grid size-8 shrink-0 place-items-center rounded-[10px] bg-white/10"><L.icon className="size-4" /></span>
                                                <span className="min-w-0">
                                                    <b className="block truncate text-[13px] font-semibold">{s.paper ?? s.title}</b>
                                                    <small className="block truncate text-[11.5px] text-[var(--side)]">{s.paper ? `${s.title} · ` : ''}{s.where} · {s.candidates}</small>
                                                </span>
                                            </span>
                                            <span className="relative h-9">
                                                <span className="absolute top-1.5 flex h-6 min-w-1.5 overflow-hidden rounded-lg bg-white/10" style={{ left: pos(a), width: `calc(${pos(b)} - ${pos(a)})` }}>
                                                    <i className="bg-[var(--status-info)]" style={{ width: `${(s.submitted / total) * 100}%` }} />
                                                    <i className="shimmer bg-[linear-gradient(90deg,var(--live),color-mix(in_oklab,var(--live)_70%,white))]" style={{ width: `${(s.writing / total) * 100}%` }} />
                                                </span>
                                                {/* the label sits on top of the bar and may run past its end, so short sittings stay readable */}
                                                <em className="absolute top-[11px] ml-2.5 text-[11.5px] font-semibold whitespace-nowrap not-italic [text-shadow:0_1px_2px_rgb(0_0_0/.45)]" style={{ left: pos(a) }}>
                                                    {!started ? `Starts ${hhmm(new Date(a))}` : s.writing ? `${s.writing} writing${s.submitted ? ` · ${s.submitted} done` : ''}` : s.submitted ? `${s.submitted} submitted` : `${hhmm(new Date(a))}–${hhmm(new Date(b))}`}
                                                </em>
                                            </span>
                                        </Link>
                                    );
                                })}
                                {nowIn && (
                                    <span className="pointer-events-none absolute top-0 bottom-0 ml-[200px] w-[calc(100%-200px)]" aria-hidden>
                                        <span className="absolute top-0 bottom-0 w-0.5 bg-[#f5a524] shadow-[0_0_12px_#f5a524]" style={{ left: pos(now.getTime()) }}>
                                            <span className="absolute -top-5 -left-7 rounded-full bg-[#f5a524] px-2 py-0.5 text-[10.5px] font-bold whitespace-nowrap text-[#1b1200]">{hhmm(now)}</span>
                                        </span>
                                    </span>
                                )}
                            </div>
                            <div className="mt-3 ml-[200px] flex gap-4 text-xs text-[var(--side)]">
                                <span className="flex items-center gap-1.5"><i className="size-2.5 rounded-sm bg-[var(--status-info)]" /> Submitted</span>
                                <span className="flex items-center gap-1.5"><i className="size-2.5 rounded-sm bg-[var(--live)]" /> Writing</span>
                                <span className="flex items-center gap-1.5"><i className="size-2.5 rounded-sm bg-white/20" /> Not started</span>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </section>
    );
}
