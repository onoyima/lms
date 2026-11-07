import { Head, router, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import { inputClass, PageHeader } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Criterion = { id: number; text: string; allowed_scores: number[] | null; max_score: number };
type Candidate = { user_id: number; name: string; identifier: string | null; my_scores: Record<number, { score: number; comment: string | null }>; examiners: number; combined: { raw: number; max: number; complete: boolean } };

/** Practical checklist, viva and research score sheet — the new home of assess.php. */
export default function Panel({ exam, criteria, candidates }: { exam: { id: number; title: string; type: string; total_mark: string | null; marking_policy: string }; criteria: Criterion[]; candidates: Candidate[] }) {
    const { flash } = usePage().props;
    const wanted = typeof window !== 'undefined' ? Number(new URLSearchParams(window.location.search).get('candidate')) : 0;
    const [current, setCurrent] = useState<Candidate | null>(candidates.find((c) => c.user_id === wanted) ?? candidates[0] ?? null);
    const [q, setQ] = useState('');
    const list = candidates.filter((c) => !q || `${c.name} ${c.identifier}`.toLowerCase().includes(q.toLowerCase()));

    return (
        <>
            <Head title={`Score sheet — ${exam.title}`} />
            <div className="grid gap-6 p-6 md:p-10 lg:grid-cols-[320px_1fr]">
                <aside className="grid content-start gap-3">
                    <p className="eyebrow">{exam.type} · combine by {exam.marking_policy}</p>
                    <div className="relative">
                        <Search className="absolute top-3 left-3 size-4 text-muted-foreground" />
                        <input className={`${inputClass} pl-9`} placeholder="Name or matric number" value={q} onChange={(e) => setQ(e.target.value)} />
                    </div>
                    <ul className="max-h-[70vh] divide-y divide-border overflow-y-auto rounded-lg border border-border">
                        {list.map((c) => (
                            <li key={c.user_id}>
                                <button onClick={() => setCurrent(c)} className={`flex w-full items-center justify-between gap-2 p-3 text-left text-sm ${current?.user_id === c.user_id ? 'bg-brand-soft' : 'hover:bg-muted/50'}`}>
                                    <span>
                                        {c.name}
                                        <span className="block font-mono text-xs text-muted-foreground">{c.identifier}</span>
                                    </span>
                                    <span className="text-xs tabular-nums text-muted-foreground">
                                        {Object.keys(c.my_scores).length ? `${c.combined.raw}/${c.combined.max}` : '—'}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </aside>
                <main className="grid content-start gap-4">
                    <PageHeader eyebrow={exam.title} title={current?.name ?? 'Choose a candidate'} lead={current ? `${current.examiners} examiner(s) have scored · combined ${current.combined.raw} of ${current.combined.max}` : undefined} />
                    {flash?.success && <p className="text-sm text-status-good">{flash.success}</p>}
                    {current && <Sheet key={current.user_id} exam={exam} criteria={criteria} candidate={current} />}
                </main>
            </div>
        </>
    );
}

function Sheet({ exam, criteria, candidate }: { exam: { id: number }; criteria: Criterion[]; candidate: Candidate }) {
    const [scores, setScores] = useState<Record<number, string>>(Object.fromEntries(Object.entries(candidate.my_scores).map(([k, v]) => [k, String(v.score)])));
    const total = criteria.reduce((n, c) => n + (scores[c.id] !== undefined && scores[c.id] !== '' ? Number(scores[c.id]) : 0), 0);
    const max = criteria.reduce((n, c) => n + Number(c.max_score), 0);

    return (
        <form className="grid gap-3" onSubmit={(e) => (e.preventDefault(), router.post(`/marking/${exam.id}/panel`, { user_id: candidate.user_id, scores }, { preserveScroll: true }))}>
            {criteria.map((c, i) => (
                <div key={c.id} className="surface flex flex-wrap items-center justify-between gap-4 p-4">
                    <p className="text-sm">
                        <span className="mr-2 font-mono text-xs text-muted-foreground">{i + 1}</span>
                        {c.text}
                    </p>
                    {c.allowed_scores?.length ? (
                        <div className="flex gap-1" role="radiogroup" aria-label={c.text}>
                            {c.allowed_scores.map((v) => (
                                <button
                                    type="button"
                                    key={v}
                                    onClick={() => setScores({ ...scores, [c.id]: String(v) })}
                                    className={`min-w-12 rounded-lg border px-3 py-1.5 text-sm tabular-nums ${scores[c.id] === String(v) ? 'border-brand bg-brand text-brand-foreground' : 'border-border hover:border-brand/50'}`}
                                >
                                    {v}
                                </button>
                            ))}
                        </div>
                    ) : (
                        <label className="flex items-center gap-2 text-sm">
                            <select className={`${inputClass} w-28 tabular-nums`} value={scores[c.id] ?? ''} onChange={(e) => setScores({ ...scores, [c.id]: e.target.value })} aria-label={`Score out of ${c.max_score}`}>
                                <option value="">Score…</option>
                                {scoreChoices(Number(c.max_score)).map((v) => (
                                    <option key={v} value={String(v)}>{v}</option>
                                ))}
                            </select>
                            <span className="text-muted-foreground">of {Number(c.max_score)}</span>
                        </label>
                    )}
                </div>
            ))}
            <div className="flex items-center justify-between">
                <p className="text-sm">
                    My total: <strong className="tabular-nums">{total}</strong> of {max}
                </p>
                <Button>Save scores</Button>
            </div>
        </form>
    );
}

/** Whole numbers from 0 up to the highest mark (and the highest mark itself if it has a fraction). */
function scoreChoices(max: number): number[] {
    const list = Array.from({ length: Math.floor(max) + 1 }, (_, i) => i);

    if (max % 1 !== 0) {
        list.push(max);
    }

    return list;
}

Panel.layout = { breadcrumbs: [{ title: 'Marking', href: '/marking' }, { title: 'Score sheet', href: '#' }] };
