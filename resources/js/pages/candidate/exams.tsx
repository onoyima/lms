import { Head, Link } from '@inertiajs/react';
import { CalendarClock, Laptop, MapPin, MonitorSmartphone, Shield } from 'lucide-react';
import { useState } from 'react';
import { Arrangeable } from '@/components/arrangeable';
import { formatDateTime, PageHeader, Status } from '@/components/platform/ui';

type Row = { candidate_id: number; exam: string; type: string; category: string; starts_at: string; ends_at: string; duration: number; venue: string | null; mode: string; channels: string[]; eligible: boolean; eligibility_reason: string | null; practice: boolean; status: string | null; attempts_used: number; max_attempts: number; has_open_attempt: boolean; can_reattempt: boolean };

const channelLabel: Record<string, { label: string; icon: typeof Laptop }> = {
    web: { label: 'Browser', icon: MonitorSmartphone },
    desktop: { label: 'Desktop app', icon: Laptop },
    seb: { label: 'Safe Exam Browser', icon: Shield },
};

export default function MyExams({ exams }: { exams: Row[] }) {
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
                {exams.length === 0 && <p className="text-muted-foreground">No exams scheduled for you yet.</p>}
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
            </div>
        </>
    );
}

MyExams.layout = { breadcrumbs: [{ title: 'My exams', href: '/my/exams' }] };
