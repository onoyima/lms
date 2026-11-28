import { Head, router, usePage } from '@inertiajs/react';
import { AlertTriangle, Camera, Check, ChevronDown, Clock, FileText, MessageSquare, Search, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { inputClass, PageHeader } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type Criterion = {
    id: number; station: string | null; text: string; kind: 'score' | 'check' | 'measure' | null; allowed_scores: number[] | null; max_score: number;
    expected: number | null; tolerance: number | null; unit: string | null; critical: boolean; guide: string | null;
};
type Evidence = { id: number; user_id: number; kind: 'candidate' | 'examiner'; file_name: string; file_size: number; note: string | null; created_at: string };
type Mark = { score: number; reading: number | null; comment: string | null };
type Candidate = {
    user_id: number; name: string; identifier: string | null; evidence: Evidence[]; my_scores: Record<number, Mark>; examiners: number;
    combined: { raw: number; max: number; complete: boolean; failed_stations?: string[] };
};
type Station = { name: string; minutes: number };
type Exam = { id: number; title: string; type: string; total_mark: string | null; marking_policy: string };

/**
 * The examiner's score sheet for practicals, vivas and research defences. Lines are grouped by station;
 * each is marked on a scale, as done / not done, or from a reading compared with the expected value.
 * A failed critical step scores its station 0.
 */
export default function Panel({ exam, criteria, candidates, stations = [], evidenceOn = false }: { exam: Exam; criteria: Criterion[]; candidates: Candidate[]; stations?: Station[]; evidenceOn?: boolean }) {
    const { flash } = usePage().props;
    const wanted = typeof window !== 'undefined' ? Number(new URLSearchParams(window.location.search).get('candidate')) : 0;
    const [currentId, setCurrentId] = useState<number | null>((candidates.find((c) => c.user_id === wanted) ?? candidates[0])?.user_id ?? null);
    const current = candidates.find((c) => c.user_id === currentId) ?? null;
    const [q, setQ] = useState('');
    const list = candidates.filter((c) => !q || `${c.name} ${c.identifier}`.toLowerCase().includes(q.toLowerCase()));
    const at = current ? candidates.indexOf(current) : -1;

    return (
        <>
            <Head title={`Score sheet — ${exam.title}`} />
            <div className="grid gap-6 p-4 md:p-8 lg:grid-cols-[300px_1fr]">
                <aside className="grid content-start gap-3">
                    <p className="eyebrow capitalize">{exam.type} · combine examiners by {exam.marking_policy}</p>
                    <div className="relative">
                        <Search className="absolute top-3 left-3 size-4 text-muted-foreground" />
                        <input className={`${inputClass} pl-9`} placeholder="Name or matric number" value={q} onChange={(e) => setQ(e.target.value)} />
                    </div>
                    <ul className="max-h-[70vh] divide-y divide-border overflow-y-auto rounded-xl border border-border">
                        {list.map((c) => (
                            <li key={c.user_id}>
                                <button onClick={() => setCurrentId(c.user_id)} className={`flex w-full items-center justify-between gap-2 p-3 text-left text-sm ${currentId === c.user_id ? 'bg-brand-soft' : 'hover:bg-muted/50'}`}>
                                    <span>
                                        {c.name}
                                        <span className="block font-mono text-xs text-muted-foreground">{c.identifier}</span>
                                    </span>
                                    <span className="text-right text-xs tabular-nums text-muted-foreground">
                                        {Object.keys(c.my_scores).length ? `${c.combined.raw}/${c.combined.max}` : '—'}
                                        {c.evidence.length > 0 && <span className="block">{c.evidence.length} file{c.evidence.length === 1 ? '' : 's'}</span>}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </aside>
                <main className="grid min-w-0 content-start gap-4">
                    <PageHeader eyebrow={exam.title} title={current?.name ?? 'Choose a candidate'}
                        lead={current ? `${current.identifier ?? ''} · ${current.examiners} examiner(s) have scored · combined ${current.combined.raw} of ${current.combined.max}` : undefined}
                        actions={current && (
                            <div className="flex gap-2">
                                <Button variant="outline" disabled={at <= 0} onClick={() => setCurrentId(candidates[at - 1].user_id)}>Previous candidate</Button>
                                <Button variant="outline" disabled={at < 0 || at >= candidates.length - 1} onClick={() => setCurrentId(candidates[at + 1].user_id)}>Next candidate</Button>
                            </div>
                        )} />
                    {flash?.success && <p className="text-sm text-status-good">{flash.success}</p>}
                    {current && (
                        <>
                            {(evidenceOn || current.evidence.length > 0) && <EvidencePanel exam={exam} candidate={current} />}
                            <Sheet key={current.user_id} exam={exam} criteria={criteria} stations={stations} candidate={current}
                                onSaved={() => at < candidates.length - 1 && setCurrentId(candidates[at + 1].user_id)} />
                        </>
                    )}
                </main>
            </div>
        </>
    );
}

/** What the candidate handed in, and photos examiners took. */
function EvidencePanel({ exam, candidate }: { exam: Exam; candidate: Candidate }) {
    const img = (n: string) => /\.(png|jpe?g|gif|webp)$/i.test(n);

    return (
        <section className="surface grid gap-3 p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="font-medium">Work handed in</p>
                <label className="inline-flex cursor-pointer items-center gap-2 rounded-xl border border-border px-3 py-2 text-sm hover:border-brand">
                    <Camera className="size-4" /> Add a photo
                    <input type="file" accept="image/*,video/*,.pdf" capture="environment" className="sr-only"
                        onChange={(e) => e.target.files?.[0] && router.post(`/marking/${exam.id}/evidence`, { user_id: candidate.user_id, file: e.target.files[0] }, { forceFormData: true, preserveScroll: true })} />
                </label>
            </div>
            {candidate.evidence.length === 0 ? (
                <p className="text-sm text-muted-foreground">Nothing yet.</p>
            ) : (
                <ul className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                    {candidate.evidence.map((e) => (
                        <li key={e.id} className="overflow-hidden rounded-xl border border-border">
                            <a href={`/my/practicals/evidence/${e.id}`} target="_blank" rel="noreferrer" className="block hover:bg-muted/40">
                                {img(e.file_name) ? <img src={`/my/practicals/evidence/${e.id}`} alt={e.file_name} className="h-40 w-full bg-muted object-contain" loading="lazy" /> : <span className="grid h-20 place-items-center bg-muted"><FileText className="size-6 text-muted-foreground" /></span>}
                                <span className="block truncate p-2 text-xs">
                                    {e.kind === 'examiner' ? 'Examiner photo · ' : ''}{e.file_name}
                                    {e.note && <span className="block text-muted-foreground">{e.note}</span>}
                                </span>
                            </a>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function Sheet({ exam, criteria, stations, candidate, onSaved }: { exam: Exam; criteria: Criterion[]; stations: Station[]; candidate: Candidate; onSaved: () => void }) {
    const [marks, setMarks] = useState<Record<number, { score: string; reading: string; comment: string }>>(() =>
        Object.fromEntries(criteria.map((c) => {
            const m = candidate.my_scores[c.id];

            return [c.id, { score: m ? String(m.score) : '', reading: m?.reading !== null && m?.reading !== undefined ? String(m.reading) : '', comment: m?.comment ?? '' }];
        })));
    const set = (id: number, patch: Partial<{ score: string; reading: string; comment: string }>) => setMarks((m) => ({ ...m, [id]: { ...m[id], ...patch } }));

    // the score each line gives right now (readings are scored against the expected value)
    const lineScore = (c: Criterion): number | null => {
        const m = marks[c.id];

        if (c.kind === 'measure') {
            if (m.reading === '' || c.expected === null) {
                return null;
            }

            const off = Math.abs(Number(m.reading) - Number(c.expected));
            const tol = Number(c.tolerance ?? 0);

            return off <= tol + 1e-9 ? Number(c.max_score) : off <= 2 * tol + 1e-9 ? Math.round((Number(c.max_score) / 2) * 100) / 100 : 0;
        }

        return m.score === '' ? null : Number(m.score);
    };

    const groups = useMemo(() => {
        const g: { name: string; items: Criterion[] }[] = [];
        criteria.forEach((c) => {
            const name = c.station ?? '';
            const found = g.find((x) => x.name === name);

            if (found) {
                found.items.push(c);
            } else {
                g.push({ name, items: [c] });
            }
        });

        return g;
    }, [criteria]);

    const stationTotal = (items: Criterion[]) => {
        const failed = items.some((c) => c.critical && lineScore(c) === 0);

        return { failed, score: failed ? 0 : items.reduce((n, c) => n + (lineScore(c) ?? 0), 0), max: items.reduce((n, c) => n + Number(c.max_score), 0) };
    };
    const total = groups.reduce((n, g) => n + stationTotal(g.items).score, 0);
    const max = criteria.reduce((n, c) => n + Number(c.max_score), 0);
    const left = criteria.filter((c) => lineScore(c) === null).length;

    const save = (next: boolean) => {
        const scores = Object.fromEntries(criteria.map((c) => [c.id, { score: marks[c.id].score, reading: marks[c.id].reading, comment: marks[c.id].comment }]));
        router.post(`/marking/${exam.id}/panel`, { user_id: candidate.user_id, scores }, { preserveScroll: true, onSuccess: () => next && onSaved() });
    };

    return (
        <form className="grid gap-4" onSubmit={(e) => (e.preventDefault(), save(false))}>
            {groups.map((g) => {
                const st = stations.find((s) => s.name === g.name);
                const t = stationTotal(g.items);

                return (
                    <section key={g.name || 'all'} className={cn('surface grid gap-2 p-4', t.failed && 'border-status-critical/50')}>
                        {(g.name || groups.length > 1) && (
                            <header className="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-2">
                                <h3 className="font-semibold">{g.name || 'General'}</h3>
                                <span className="flex items-center gap-3 text-sm text-muted-foreground">
                                    {st?.minutes ? <span className="flex items-center gap-1"><Clock className="size-3.5" /> {st.minutes} min</span> : null}
                                    <span className={cn('tabular-nums', t.failed ? 'font-semibold text-status-critical' : 'text-foreground')}>{t.score} / {t.max}</span>
                                </span>
                            </header>
                        )}
                        {t.failed && <p className="flex items-center gap-2 rounded-lg bg-status-critical/10 p-2 text-sm text-status-critical"><AlertTriangle className="size-4" /> A critical step was not done: this station scores 0.</p>}
                        {g.items.map((c) => (
                            <Line key={c.id} c={c} n={criteria.indexOf(c) + 1} mark={marks[c.id]} score={lineScore(c)} set={(p) => set(c.id, p)} />
                        ))}
                    </section>
                );
            })}
            <div className="surface sticky bottom-3 flex flex-wrap items-center justify-between gap-3 p-4">
                <p className="text-sm">
                    My total: <strong className="text-lg tabular-nums">{Math.round(total * 100) / 100}</strong> of {max}
                    {left > 0 && <span className="ml-2 text-muted-foreground">· {left} line{left === 1 ? '' : 's'} not marked yet</span>}
                </p>
                <div className="flex gap-2">
                    <Button type="submit" variant="outline">Save</Button>
                    <Button type="button" onClick={() => save(true)}>Save and next candidate</Button>
                </div>
            </div>
        </form>
    );
}

function Line({ c, n, mark, score, set }: { c: Criterion; n: number; mark: { score: string; reading: string; comment: string }; score: number | null; set: (p: Partial<{ score: string; reading: string; comment: string }>) => void }) {
    const [open, setOpen] = useState(!!mark.comment);
    const big = 'min-w-14 rounded-xl border px-3 py-2.5 text-sm font-medium tabular-nums transition-colors';

    return (
        <div className="grid gap-2 rounded-xl px-1 py-2">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                    <p className="text-[15px]">
                        <span className="mr-2 font-mono text-xs text-muted-foreground">{n}</span>
                        {c.text}
                        {c.critical && <span className="ml-2 rounded-full bg-status-critical/12 px-2 py-0.5 text-[11px] font-semibold text-status-critical">Critical</span>}
                    </p>
                    {c.guide && <p className="mt-0.5 text-xs text-muted-foreground">{c.guide}</p>}
                    {c.kind === 'measure' && <p className="mt-0.5 text-xs text-muted-foreground">Expected {c.expected} {c.unit} ± {c.tolerance} for full marks ({c.max_score}); within ± {Number(c.tolerance) * 2} for half.</p>}
                </div>
                <div className="flex flex-wrap items-center gap-1.5">
                    {c.kind === 'check' ? (
                        <>
                            <button type="button" onClick={() => set({ score: String(c.max_score) })} className={cn(big, mark.score === String(c.max_score) ? 'border-status-good bg-status-good text-white' : 'border-border hover:border-status-good')}>
                                <Check className="mr-1 inline size-4" />Done
                            </button>
                            <button type="button" onClick={() => set({ score: '0' })} className={cn(big, mark.score === '0' ? 'border-status-critical bg-status-critical text-white' : 'border-border hover:border-status-critical')}>
                                <X className="mr-1 inline size-4" />Not done
                            </button>
                        </>
                    ) : c.kind === 'measure' ? (
                        <label className="flex items-center gap-2 text-sm">
                            <input type="number" step="any" inputMode="decimal" className={`${inputClass} !w-32 text-right tabular-nums`} value={mark.reading} placeholder="Reading"
                                onChange={(e) => set({ reading: e.target.value })} aria-label={`Reading for ${c.text}`} />
                            <span className="text-muted-foreground">{c.unit}</span>
                            {score !== null && <span className={cn('rounded-full px-2.5 py-1 text-xs font-semibold tabular-nums', score === Number(c.max_score) ? 'bg-status-good/15 text-status-good' : score > 0 ? 'bg-status-warning/15 text-status-warning' : 'bg-status-critical/12 text-status-critical')}>{score} / {c.max_score}</span>}
                        </label>
                    ) : c.allowed_scores?.length ? (
                        c.allowed_scores.map((v) => (
                            <button type="button" key={v} onClick={() => set({ score: String(v) })} className={cn(big, mark.score === String(v) ? 'border-brand bg-brand text-brand-foreground' : 'border-border hover:border-brand/50')}>{v}</button>
                        ))
                    ) : (
                        <label className="flex items-center gap-2 text-sm">
                            <input type="number" step="0.25" min={0} max={c.max_score} inputMode="decimal" className={`${inputClass} !w-24 text-right tabular-nums`} value={mark.score} onChange={(e) => set({ score: e.target.value })} aria-label={`Score out of ${c.max_score}`} />
                            <span className="text-muted-foreground">of {Number(c.max_score)}</span>
                        </label>
                    )}
                    <button type="button" onClick={() => setOpen(!open)} className="grid size-10 place-items-center rounded-xl text-muted-foreground hover:bg-muted" aria-label="Comment" aria-expanded={open}>
                        {open ? <ChevronDown className="size-4" /> : <MessageSquare className="size-4" />}
                    </button>
                </div>
            </div>
            {open && <input className={inputClass} value={mark.comment} placeholder="Comment (optional)" maxLength={500} onChange={(e) => set({ comment: e.target.value })} />}
        </div>
    );
}

Panel.layout = { breadcrumbs: [{ title: 'Marking', href: '/marking' }, { title: 'Score sheet', href: '#' }] };
