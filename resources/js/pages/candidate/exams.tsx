import { Head, Link, router } from '@inertiajs/react';
import { CalendarClock, Laptop, MapPin, MonitorSmartphone, Shield, Upload, X } from 'lucide-react';
import { useState } from 'react';
import { Arrangeable } from '@/components/arrangeable';
import { acceptFor, describeTypes } from '@/components/lms/file-types';
import { formatDateTime, PageHeader, Status } from '@/components/platform/ui';

type Row = { candidate_id: number; exam: string; type: string; category: string; starts_at: string; ends_at: string; duration: number; venue: string | null; mode: string; channels: string[]; eligible: boolean; eligibility_reason: string | null; practice: boolean; status: string | null; attempts_used: number; max_attempts: number; has_open_attempt: boolean; can_reattempt: boolean };

const channelLabel: Record<string, { label: string; icon: typeof Laptop }> = {
    web: { label: 'Browser', icon: MonitorSmartphone },
    desktop: { label: 'Desktop app', icon: Laptop },
    seb: { label: 'Safe Exam Browser', icon: Shield },
};

type Practical = {
    candidate_id: number; exam: string; type: string; starts_at: string; ends_at: string; venue: string | null; stations: number;
    evidence: boolean; evidence_types: string[]; can_hand_in: boolean; handed_in: { id: number; file_name: string; file_size: number; created_at: string }[];
};

/** A practical, viva or defence: when and where, and handing in work when the practical asks for it. */
function PracticalCard({ p }: { p: Practical }) {
    const [busy, setBusy] = useState(false);

    return (
        <article className="surface flex h-full flex-col gap-3 p-6">
            <div className="flex items-start justify-between gap-3">
                <h3 className="font-medium">{p.exam}</h3>
                <span className="rounded-full bg-foreground/[.06] px-2.5 py-0.5 text-xs font-medium capitalize">{p.type}</span>
            </div>
            <ul className="space-y-1.5 text-sm text-muted-foreground">
                <li className="flex items-center gap-2"><CalendarClock className="size-4" /> {formatDateTime(p.starts_at)}</li>
                <li className="flex items-center gap-2"><MapPin className="size-4" /> {p.venue ?? 'Venue to be announced'}</li>
                {p.stations > 0 && <li>{p.stations} station{p.stations === 1 ? '' : 's'}; examiners mark you as you work.</li>}
            </ul>
            {p.evidence && (
                <div className="mt-auto grid gap-2 rounded-xl bg-foreground/[.04] p-3 text-sm">
                    <p className="font-medium">Hand in your work</p>
                    <p className="text-xs text-muted-foreground">{describeTypes(p.evidence_types)}. Open from the start of the practical until two hours after.</p>
                    {p.handed_in.map((f) => (
                        <span key={f.id} className="flex items-center justify-between gap-2 rounded-lg bg-background px-2.5 py-1.5 text-xs">
                            <a href={`/my/practicals/evidence/${f.id}`} target="_blank" rel="noreferrer" className="truncate text-brand">{f.file_name}</a>
                            {p.can_hand_in && <button type="button" onClick={() => router.delete(`/my/practicals/evidence/${f.id}`, { preserveScroll: true })} className="text-muted-foreground hover:text-status-critical" aria-label={`Remove ${f.file_name}`}><X className="size-3.5" /></button>}
                        </span>
                    ))}
                    {p.can_hand_in ? (
                        <label className={`inline-flex w-fit cursor-pointer items-center gap-2 rounded-lg bg-brand px-3 py-2 text-sm font-medium text-brand-foreground ${busy ? 'opacity-60' : ''}`}>
                            <Upload className="size-4" /> {busy ? 'Uploading…' : 'Add a file'}
                            <input type="file" className="sr-only" accept={acceptFor(p.evidence_types)} disabled={busy}
                                onChange={(e) => {
                                    const f = e.target.files?.[0];

                                    if (f) {
                                        setBusy(true);
                                        router.post(`/my/practicals/${p.candidate_id}/evidence`, { file: f }, { forceFormData: true, preserveScroll: true, onFinish: () => setBusy(false) });
                                    }

                                    e.target.value = '';
                                }} />
                        </label>
                    ) : (
                        <p className="text-xs text-muted-foreground">{new Date(p.starts_at) > new Date() ? 'Opens when your practical starts.' : 'Handing in has closed.'}</p>
                    )}
                </div>
            )}
        </article>
    );
}

export default function MyExams({ exams, practicals = [] }: { exams: Row[]; practicals?: Practical[] }) {
    const [now] = useState(() => Date.now());
    const isOpen = (e: Row) => now >= new Date(e.starts_at).getTime() - 15 * 60000 && now <= new Date(e.ends_at).getTime();
    const finished = (e: Row) => ['submitted', 'auto_submitted', 'terminated'].includes(e.status ?? '') && !e.can_reattempt && !e.has_open_attempt;
    // exams open now come first, then the rest by date
    const sorted = [...exams].sort((a, b) => Number(isOpen(b) && !finished(b)) - Number(isOpen(a) && !finished(a)) || a.starts_at.localeCompare(b.starts_at));

    return (
        <>
            <Head title="My exams" />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader title="My exams" lead="Exams open now are at the top. Open one a few minutes early if it has checks to pass." />
                {exams.length === 0 && practicals.length === 0 && <p className="text-muted-foreground">No exams scheduled for you yet.</p>}
                <Arrangeable page="my-exams" label="exams" className="grid gap-4 md:grid-cols-2 xl:grid-cols-3" cards={sorted.map((e) => {
                        const open = isOpen(e);

                        return { key: String(e.candidate_id), title: e.exam, node: (
                            <article className={`surface flex h-full flex-col gap-4 p-6 ${open && !finished(e) ? 'ring-2 ring-brand/60' : ''}`}>
                                {open && !finished(e) && <span className="-mb-2 inline-flex w-fit items-center gap-1.5 rounded-full bg-brand px-2.5 py-0.5 text-xs font-semibold text-brand-foreground"><span className="size-1.5 animate-pulse rounded-full bg-current" /> Open now</span>}
                                <div className="flex items-start justify-between gap-3">
                                    <h3 className="font-medium">{e.exam}</h3>
                                    {e.status ? <Status value={e.status} /> : e.practice ? <Status value="practice" /> : null}
                                </div>
                                <ul className="space-y-1.5 text-sm text-muted-foreground">
                                    <li className="flex items-center gap-2"><CalendarClock className="size-4" /> {formatDateTime(e.starts_at)} · {e.duration} min</li>
                                    <li className="flex items-center gap-2"><MapPin className="size-4" /> {e.venue ?? (e.mode === 'remote' ? 'Online (proctored)' : '—')}</li>
                                </ul>
                                <div className="flex flex-wrap gap-2">
                                    {e.channels.map((c) => {
                                        const C = channelLabel[c];

                                        return C ? <span key={c} className="flex items-center gap-1 rounded-full border border-border px-2 py-0.5 text-xs"><C.icon className="size-3" />{C.label}</span> : null;
                                    })}
                                </div>
                                {e.max_attempts > 1 && (
                                    <p className="text-xs text-muted-foreground">
                                        {e.attempts_used === 0 ? `${e.max_attempts} attempts allowed` : `Attempt ${Math.min(e.attempts_used, e.max_attempts)} of ${e.max_attempts} used`}
                                    </p>
                                )}
                                {!e.eligible ? (
                                    <p className="text-sm text-status-critical">Not eligible{e.eligibility_reason ? `: ${e.eligibility_reason}` : ''}. Contact the exams office.</p>
                                ) : e.has_open_attempt ? (
                                    <Link href={`/my/exams/${e.candidate_id}`} className="mt-auto rounded-lg bg-brand px-4 py-2.5 text-center text-sm font-medium text-brand-foreground">
                                        Continue exam
                                    </Link>
                                ) : e.can_reattempt ? (
                                    <Link href={`/my/exams/${e.candidate_id}`} className="mt-auto rounded-lg bg-[#b45309] px-4 py-2.5 text-center text-sm font-semibold text-white hover:bg-[#92400e]">
                                        Re-attempt ({e.attempts_used + 1} of {e.max_attempts})
                                    </Link>
                                ) : ['submitted', 'auto_submitted', 'terminated'].includes(e.status ?? '') ? (
                                    <p className="text-sm text-muted-foreground">Submitted{e.max_attempts > 1 ? ' · no attempts left' : ''}.</p>
                                ) : (
                                    <Link href={`/my/exams/${e.candidate_id}`} className={`mt-auto rounded-lg px-4 py-2.5 text-center text-sm font-medium ${open || e.practice ? 'bg-brand text-brand-foreground' : 'border border-border'}`}>
                                        {open || e.practice ? 'Go to exam room' : 'System check & details'}
                                    </Link>
                                )}
                                {!e.practice && e.eligible && (
                                    <a href={`/my/documents/exam-slip/${e.candidate_id}`} className="text-center text-xs text-muted-foreground underline-offset-4 hover:underline">
                                        Download exam slip (PDF)
                                    </a>
                                )}
                            </article>
                        ) };
                    })} />
                {practicals.length > 0 && (
                    <section className="grid gap-4">
                        <h2 className="text-lg font-semibold">Practicals and vivas</h2>
                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {practicals.map((p) => <PracticalCard key={p.candidate_id} p={p} />)}
                        </div>
                    </section>
                )}
            </div>
        </>
    );
}

MyExams.layout = { breadcrumbs: [{ title: 'My exams', href: '/my/exams' }] };
