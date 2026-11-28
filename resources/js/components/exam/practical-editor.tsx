import { router } from '@inertiajs/react';
import { AlertTriangle, ChevronDown, ChevronUp, Clock, Plus, Trash2, Upload } from 'lucide-react';
import { useMemo, useState } from 'react';
import { describeTypes, FileTypePicker } from '@/components/lms/file-types';
import { inputClass } from '@/components/platform/ui';
import { Button, buttonVariants } from '@/components/ui/button';
import { parseCsv } from '@/lib/csv';
import { cn } from '@/lib/utils';

export type PracticalCriterion = {
    id?: number; station?: string | null; text: string; kind?: 'score' | 'check' | 'measure' | null; allowed_scores: number[] | null; max_score: number;
    expected?: number | null; tolerance?: number | null; unit?: string | null; critical?: boolean; guide?: string | null;
};
type TemplateLine = { text: string; kind: 'score' | 'check' | 'measure'; allowed_scores: number[] | null; max_score: number; expected?: number; tolerance?: number; unit?: string; critical?: boolean; guide?: string | null };
export type PracticalSetup = {
    settings: { discipline?: string | null; stations?: { name: string; minutes: number }[]; evidence?: boolean; evidence_types?: string[] };
    templates: Record<string, { name: string; hint: string; evidence: boolean; evidence_types?: string[]; stations: { name: string; minutes: number; criteria: TemplateLine[] }[] }>;
};
type Row = { id?: number; station: string; text: string; kind: 'score' | 'check' | 'measure'; allowed_scores: string; max_score: number; expected: string; tolerance: string; unit: string; critical: boolean; guide: string };

const KIND_LABEL = { score: 'Score scale', check: 'Done / not done', measure: 'Measured reading' };

const toRow = (c: PracticalCriterion | TemplateLine, station = ''): Row => ({
    id: 'id' in c ? c.id : undefined, station: ('station' in c ? c.station ?? '' : '') || station, text: c.text, kind: (c.kind ?? 'score') as Row['kind'],
    allowed_scores: c.allowed_scores?.join(',') ?? '', max_score: Number(c.max_score), expected: c.expected != null ? String(c.expected) : '',
    tolerance: c.tolerance != null ? String(c.tolerance) : '', unit: c.unit ?? '', critical: !!c.critical, guide: c.guide ?? '',
});

/**
 * Practical / viva / research set-up. Practicals are built from stations (timed tasks), each with lines an
 * examiner marks on a scale, as done / not done, or from a reading against the expected value. Start from a
 * discipline template (nursing, clinical OSCE, chemistry, physics, hardware, software, biology, pharmacy,
 * workshop) and edit it, or upload a checklist.
 */
export function PracticalEditor({ exam, criteria, practical, saveUrl }: { exam: { id: number; type: string; can_edit: boolean }; criteria: PracticalCriterion[]; practical: PracticalSetup | null; saveUrl?: string }) {
    const isPractical = exam.type === 'practical';
    const [rows, setRows] = useState<Row[]>(criteria.map((c) => toRow(c)));
    const [stations, setStations] = useState<{ name: string; minutes: number }[]>(practical?.settings.stations ?? []);
    const [evidence, setEvidence] = useState(!!practical?.settings.evidence);
    const [types, setTypes] = useState<string[]>(practical?.settings.evidence_types ?? ['pdf', 'jpg', 'jpeg', 'png']);
    const [discipline, setDiscipline] = useState<string | null>(practical?.settings.discipline ?? null);
    const [note, setNote] = useState<string | null>(null);
    const [picking, setPicking] = useState(isPractical && criteria.length === 0);
    const edit = exam.can_edit;

    const lineMax = (r: Row) => (r.kind === 'score' && r.allowed_scores ? Math.max(...r.allowed_scores.split(',').map(Number).filter((x) => !Number.isNaN(x))) : Number(r.max_score || 0));
    const total = rows.reduce((n, r) => n + lineMax(r), 0);
    // stations in the order they first appear, plus any named without lines yet
    const stationNames = useMemo(() => [...new Set([...rows.map((r) => r.station), ...stations.map((s) => s.name)])], [rows, stations]);
    const minutesOf = (name: string) => stations.find((s) => s.name === name)?.minutes ?? 0;
    const setMinutes = (name: string, minutes: number) => setStations((s) => (s.some((x) => x.name === name) ? s.map((x) => (x.name === name ? { ...x, minutes } : x)) : [...s, { name, minutes }]));
    const update = (i: number, patch: Partial<Row>) => setRows(rows.map((r, j) => (j === i ? { ...r, ...patch } : r)));

    const loadTemplate = (key: string) => {
        const t = practical?.templates[key];

        if (!t) {
            return;
        }

        setRows(t.stations.flatMap((st) => st.criteria.map((c) => toRow(c, st.name))));
        setStations(t.stations.map((st) => ({ name: st.name, minutes: st.minutes })));
        setEvidence(t.evidence);
        setTypes(t.evidence_types ?? types);
        setDiscipline(key);
        setPicking(false);
        setNote(`${t.name}: ${t.stations.length} stations loaded. Change anything (expected values, tolerances, wording), then Save.`);
    };

    /** A checklist from a CSV (Station, Step, Score values, Maximum) or a text file (one step per line, "| 0,1,2" for its scores). */
    const readChecklist = async (file: File, replace: boolean) => {
        const text = await file.text();
        let parsed: Row[] = [];

        if (/\.csv$/i.test(file.name)) {
            const table = parseCsv(text);
            const head = (table[0] ?? []).map((h) => h.toLowerCase().replace(/[^a-z]/g, ''));
            const hasHead = head.some((h) => /step|criterion|item|text|task/.test(h));
            const col = (re: RegExp, fallback: number) => (hasHead ? head.findIndex((h) => re.test(h)) : fallback);
            const [cStation, cText, cScores, cMax] = [col(/station/, -1), col(/step|criterion|item|text|task/, 0), col(/score|values|allowed/, 1), col(/max/, 2)];
            parsed = table.slice(hasHead ? 1 : 0).map((r) => toRow({ text: r[cText] ?? '', allowed_scores: null, max_score: Number(cMax >= 0 ? r[cMax] || 1 : 1) }, cStation >= 0 ? r[cStation] ?? '' : ''))
                .map((row, i) => ({ ...row, allowed_scores: (cScores >= 0 ? table.slice(hasHead ? 1 : 0)[i][cScores] ?? '' : '').replace(/\s+/g, '') }));
        } else {
            parsed = text.replace(/^\uFEFF/, '').split(/\r?\n/).map((l) => l.trim()).filter((l) => l && !l.startsWith('//')).map((l) => {
                const [t, scores = ''] = l.split(/\s*[|\t]\s*/);

                return { ...toRow({ text: t.replace(/^\d{1,3}[.)]\s+/, ''), allowed_scores: null, max_score: 1 }), allowed_scores: scores.replace(/\s+/g, '') };
            });
        }

        parsed = parsed.filter((r) => r.text).map((r) => ({ ...r, text: r.text.slice(0, 500) }));
        setRows([...(replace ? [] : rows), ...parsed]);
        setNote(`${parsed.length} steps read from ${file.name}. Check them, then Save.`);
    };

    const save = () => router.put(saveUrl ?? `/exams/${exam.id}/criteria`, {
        criteria: rows.map((r) => ({
            id: r.id, station: r.station || null, text: r.text, kind: r.kind, allowed_scores: r.kind === 'score' ? r.allowed_scores : '', max_score: r.kind === 'score' && r.allowed_scores ? null : r.max_score,
            expected: r.kind === 'measure' && r.expected !== '' ? Number(r.expected) : null, tolerance: r.kind === 'measure' && r.tolerance !== '' ? Number(r.tolerance) : null,
            unit: r.kind === 'measure' ? r.unit || null : null, critical: r.critical, guide: r.guide || null,
        })),
        ...(isPractical ? { stations: stationNames.filter(Boolean).map((name) => ({ name, minutes: minutesOf(name) })), evidence, evidence_types: types, discipline } : {}),
    }, { preserveScroll: true });

    const move = (i: number, d: -1 | 1) => {
        const next = [...rows];
        [next[i], next[i + d]] = [next[i + d], next[i]];
        setRows(next);
    };

    return (
        <section className="grid gap-4">
            {isPractical && edit && (
                <div className="surface grid gap-3 p-5">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p className="font-medium">Start from a template for your discipline</p>
                            <p className="text-sm text-muted-foreground">Loads stations and marking lines you can change. It replaces the lines below.</p>
                        </div>
                        {!picking && <Button variant="outline" size="sm" onClick={() => setPicking(true)}>Choose a template</Button>}
                    </div>
                    {picking && practical && (
                        <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                            {Object.entries(practical.templates).map(([key, t]) => (
                                <button key={key} type="button" onClick={() => (rows.length === 0 || confirm('Replace the lines below with this template?')) && loadTemplate(key)}
                                    className={cn('rounded-xl border p-3 text-left text-sm transition-colors hover:border-brand', discipline === key ? 'border-brand bg-brand-soft' : 'border-border')}>
                                    <b className="block">{t.name}</b>
                                    <span className="text-xs text-muted-foreground">{t.hint}</span>
                                    <span className="mt-1 block text-xs text-muted-foreground">{t.stations.length} station{t.stations.length === 1 ? '' : 's'} · {t.stations.reduce((n, s) => n + s.criteria.length, 0)} lines</span>
                                </button>
                            ))}
                        </div>
                    )}
                </div>
            )}

            <div className="surface grid gap-4 p-5">
                <p className="text-sm text-muted-foreground">
                    {exam.type === 'viva' || exam.type === 'research'
                        ? 'Each examiner gives a score from 0 up to the maximum. Add lines to score separate areas (write-up, presentation, defence).'
                        : 'Group lines into stations. Mark a line on a score scale (e.g. 0,0.5,1), as done / not done, or from a measured reading: within the tolerance earns full marks, within twice the tolerance half. A critical line that is not done scores its whole station 0.'}{' '}
                    Maximum total: <strong>{total}</strong>
                </p>

                {stationNames.map((name) => (
                    <div key={name || 'none'} className="grid gap-2 rounded-xl border border-border p-3">
                        {isPractical && (
                            <div className="flex flex-wrap items-center gap-2">
                                <input className={`${inputClass} !w-auto flex-1 font-medium`} value={name} disabled={!edit} placeholder="Station name (e.g. Titration)"
                                    onChange={(e) => {
                                        const v = e.target.value;
                                        setRows(rows.map((r) => (r.station === name ? { ...r, station: v } : r)));
                                        setStations(stations.map((s) => (s.name === name ? { ...s, name: v } : s)));
                                    }} />
                                <label className="flex items-center gap-1.5 text-sm text-muted-foreground"><Clock className="size-4" />
                                    <input type="number" min={0} max={600} className={`${inputClass} !w-20`} disabled={!edit} value={minutesOf(name)} onChange={(e) => setMinutes(name, Number(e.target.value))} /> min
                                </label>
                            </div>
                        )}
                        {rows.map((r, i) => r.station !== name ? null : (
                            <div key={i} className="grid gap-2 rounded-lg bg-muted/40 p-2.5">
                                <div className="flex flex-wrap items-center gap-2">
                                    <input className={`${inputClass} min-w-56 flex-1`} value={r.text} disabled={!edit} onChange={(e) => update(i, { text: e.target.value })} placeholder="What the examiner checks" />
                                    <select className={`${inputClass} !w-44`} value={r.kind} disabled={!edit} onChange={(e) => update(i, { kind: e.target.value as Row['kind'] })}>
                                        {(isPractical ? (['score', 'check', 'measure'] as const) : (['score'] as const)).map((k) => <option key={k} value={k}>{KIND_LABEL[k]}</option>)}
                                    </select>
                                    {r.kind === 'score' && (
                                        <>
                                            <input className={`${inputClass} !w-28 font-mono`} value={r.allowed_scores} disabled={!edit} onChange={(e) => update(i, { allowed_scores: e.target.value })} placeholder="0,1,2" title="Scores the examiner can pick, or empty for any score up to the maximum" />
                                            <input type="number" className={`${inputClass} !w-20`} value={r.max_score} disabled={!edit || !!r.allowed_scores} onChange={(e) => update(i, { max_score: Number(e.target.value) })} title="Maximum" />
                                        </>
                                    )}
                                    {r.kind === 'check' && <label className="flex items-center gap-1 text-xs">Worth <input type="number" min={0.25} step={0.25} className={`${inputClass} !w-20`} value={r.max_score} disabled={!edit} onChange={(e) => update(i, { max_score: Number(e.target.value) })} /></label>}
                                    {isPractical && (
                                        <label className={cn('flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs', r.critical && 'bg-status-critical/10 text-status-critical')} title="Not done = the whole station scores 0">
                                            <input type="checkbox" checked={r.critical} disabled={!edit} onChange={(e) => update(i, { critical: e.target.checked })} /> Critical
                                        </label>
                                    )}
                                    {edit && (
                                        <span className="flex">
                                            <Button variant="ghost" size="icon" disabled={i === 0} onClick={() => move(i, -1)} aria-label="Up"><ChevronUp className="size-4" /></Button>
                                            <Button variant="ghost" size="icon" disabled={i === rows.length - 1} onClick={() => move(i, 1)} aria-label="Down"><ChevronDown className="size-4" /></Button>
                                            <Button variant="ghost" size="icon" onClick={() => setRows(rows.filter((_, j) => j !== i))} aria-label="Remove"><Trash2 className="size-4" /></Button>
                                        </span>
                                    )}
                                </div>
                                {r.kind === 'measure' && (
                                    <div className="flex flex-wrap items-center gap-2 text-xs">
                                        <label className="flex items-center gap-1">Expected <input type="number" step="any" className={`${inputClass} !w-28`} value={r.expected} disabled={!edit} onChange={(e) => update(i, { expected: e.target.value })} /></label>
                                        <label className="flex items-center gap-1">± <input type="number" step="any" min={0} className={`${inputClass} !w-24`} value={r.tolerance} disabled={!edit} onChange={(e) => update(i, { tolerance: e.target.value })} /></label>
                                        <input className={`${inputClass} !w-24`} placeholder="unit" value={r.unit} disabled={!edit} onChange={(e) => update(i, { unit: e.target.value })} />
                                        <label className="flex items-center gap-1">Worth <input type="number" min={0.5} step={0.5} className={`${inputClass} !w-20`} value={r.max_score} disabled={!edit} onChange={(e) => update(i, { max_score: Number(e.target.value) })} /></label>
                                        {(r.expected === '' || r.tolerance === '') && <span className="flex items-center gap-1 text-status-warning"><AlertTriangle className="size-3.5" /> Give the expected value and tolerance</span>}
                                    </div>
                                )}
                                {(isPractical || r.guide) && <input className={`${inputClass} text-xs`} value={r.guide} disabled={!edit} onChange={(e) => update(i, { guide: e.target.value })} placeholder="Guide for examiners (optional): what earns each mark" />}
                            </div>
                        ))}
                        {edit && (
                            <Button variant="ghost" size="sm" className="w-fit" onClick={() => setRows([...rows, { station: name, text: '', kind: 'score', allowed_scores: '0,1,2', max_score: 2, expected: '', tolerance: '', unit: '', critical: false, guide: '' }])}>
                                <Plus className="size-4" /> Add a line{name ? ` to ${name}` : ''}
                            </Button>
                        )}
                    </div>
                ))}

                {edit && (
                    <div className="flex flex-wrap gap-2">
                        {isPractical && (
                            <Button variant="outline" onClick={() => {
                                const name = `Station ${stationNames.filter(Boolean).length + 1}`;
                                setStations([...stations, { name, minutes: 15 }]);
                                setRows([...rows, { station: name, text: '', kind: 'score', allowed_scores: '0,1,2', max_score: 2, expected: '', tolerance: '', unit: '', critical: false, guide: '' }]);
                            }}><Plus className="size-4" /> Add a station</Button>
                        )}
                        {!isPractical && rows.length === 0 && <Button variant="outline" onClick={() => setRows([{ station: '', text: '', kind: 'score', allowed_scores: '', max_score: 10, expected: '', tolerance: '', unit: '', critical: false, guide: '' }])}><Plus className="size-4" /> Add a line</Button>}
                    </div>
                )}
            </div>

            {isPractical && (
                <div className="surface grid gap-3 p-5">
                    <label className="flex items-start gap-3">
                        <input type="checkbox" className="mt-1" checked={evidence} disabled={!edit} onChange={(e) => setEvidence(e.target.checked)} />
                        <span><b className="block font-medium">Candidates hand in their work</b><span className="text-sm text-muted-foreground">Code, photos of a circuit or a job, a lab report. They upload it from My exams, from the start of their practical until two hours after; examiners see it on the score sheet and can add photos too.</span></span>
                    </label>
                    {evidence && (
                        <div className="grid gap-1.5 text-sm">
                            <span>Accepted files: <b>{describeTypes(types)}</b></span>
                            {edit && <FileTypePicker value={types} onChange={setTypes} />}
                        </div>
                    )}
                </div>
            )}

            {edit && (
                <div className="surface grid gap-2 p-5">
                    <p className="text-sm font-medium">Or upload a checklist</p>
                    <p className="text-xs text-muted-foreground">A CSV with the columns Station, Step, Score values, Maximum (<a className="text-brand underline" href="/templates/practical_checklist">template</a>), or a text file with one step per line and its scores after a bar: <span className="font-mono">Hand hygiene | 0,1,2</span></p>
                    <div className="flex flex-wrap gap-2">
                        <label className={`${buttonVariants({ variant: 'outline', size: 'sm' })} cursor-pointer`}>
                            <Upload className="size-4" /> Add steps from a file
                            <input type="file" className="sr-only" accept=".csv,.txt" onChange={(e) => (e.target.files?.[0] && void readChecklist(e.target.files[0], false), (e.target.value = ''))} />
                        </label>
                        {rows.length > 0 && (
                            <label className={`${buttonVariants({ variant: 'ghost', size: 'sm' })} cursor-pointer`}>
                                Replace the list from a file
                                <input type="file" className="sr-only" accept=".csv,.txt" onChange={(e) => (e.target.files?.[0] && void readChecklist(e.target.files[0], true), (e.target.value = ''))} />
                            </label>
                        )}
                    </div>
                </div>
            )}

            {note && <p className="text-sm text-status-good">{note}</p>}
            {edit && <div><Button onClick={save} disabled={rows.length === 0 || rows.some((r) => !r.text.trim())}>Save</Button></div>}
        </section>
    );
}
