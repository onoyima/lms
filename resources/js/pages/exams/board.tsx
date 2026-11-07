import { Head, Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { formatDateTime, inputClass, PageHeader, Status, Table, Td } from '@/components/platform/ui';

type Row = {
    id: number; title: string; code: string | null; type: string; status: string; course: string | null;
    first_start: string | null; last_end: string | null; running: boolean; candidates: number; writing: number; finished: number; average: number | null;
};
type Page = { data: Row[]; links: { url: string | null; label: string; active: boolean }[] };

const TABS = [
    ['active', 'Running now'],
    ['upcoming', 'Coming up'],
    ['past', 'Finished'],
    ['all', 'All exams'],
] as const;

/** Every exam this person looks after, with who is writing it right now. */
export default function ExamBoard({ tab, counts, exams, q, managesAll }: { tab: string; counts: Record<string, number>; exams: Page; q: string; managesAll: boolean }) {
    const [search, setSearch] = useState(q);

    // the "running now" list refreshes itself
    useEffect(() => {
        if (tab !== 'active') {
            return;
        }

        const t = window.setInterval(() => router.reload({ only: ['exams', 'counts'] }), 20000);

        return () => window.clearInterval(t);
    }, [tab]);

    return (
        <>
            <Head title="Exam board" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title="Exam board" lead={managesAll ? 'Every exam in the institution: open one for its live statistics, score board and grade spread.' : 'The exams assigned to you, as examiner or as the course lecturer.'} />
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <nav className="flex flex-wrap gap-1 rounded-lg border border-border p-1 text-sm">
                        {TABS.map(([key, label]) => (
                            <Link key={key} href={`/exam-board?show=${key}${search ? `&q=${encodeURIComponent(search)}` : ''}`} preserveScroll className={`rounded-md px-3 py-1.5 ${tab === key ? 'bg-foreground text-background' : 'hover:bg-muted'}`}>
                                {label} <span className="tabular-nums opacity-60">{counts[key] ?? 0}</span>
                            </Link>
                        ))}
                    </nav>
                    <form className="relative w-full max-w-xs" onSubmit={(e) => (e.preventDefault(), router.get('/exam-board', { show: tab, q: search || undefined }, { preserveState: true }))}>
                        <Search className="absolute top-3 left-3 size-4 text-muted-foreground" />
                        <input className={`${inputClass} pl-9`} placeholder="Exam title or code" value={search} onChange={(e) => setSearch(e.target.value)} />
                    </form>
                </div>

                <Table head={['Exam', 'Status', 'When', 'Candidates', 'Writing now', 'Submitted', 'Average']}>
                    {exams.data.map((e) => (
                        <tr key={e.id} className={e.running || e.writing ? 'bg-status-good/5' : ''}>
                            <Td>
                                <Link href={`/exam-board/${e.id}`} className="font-medium text-brand hover:underline">{e.title}</Link>
                                <span className="block text-xs text-muted-foreground">{[e.code, e.course, e.type.toUpperCase()].filter(Boolean).join(' · ')}</span>
                            </Td>
                            <Td>{e.running ? <Status value="live" /> : <Status value={e.status} />}</Td>
                            <Td className="text-xs whitespace-nowrap">{e.first_start ? formatDateTime(e.first_start) : 'Not scheduled'}{e.last_end && e.last_end !== e.first_start && <span className="block text-muted-foreground">until {formatDateTime(e.last_end)}</span>}</Td>
                            <Td className="tabular-nums">{e.candidates}</Td>
                            <Td className="tabular-nums">
                                {e.writing > 0 ? (
                                    <span className="flex items-center gap-1.5 font-semibold text-status-good"><span className="size-2 animate-pulse rounded-full bg-status-good" />{e.writing}</span>
                                ) : (
                                    <span className="text-muted-foreground">0</span>
                                )}
                            </Td>
                            <Td className="tabular-nums">{e.finished}</Td>
                            <Td className="tabular-nums">{e.average !== null ? `${e.average}%` : '—'}</Td>
                        </tr>
                    ))}
                    {exams.data.length === 0 && (
                        <tr><Td className="text-sm text-muted-foreground">{tab === 'active' ? 'No exam is running right now.' : 'No exams here.'}</Td></tr>
                    )}
                </Table>
                {exams.links.length > 3 && (
                    <nav className="flex flex-wrap gap-1 text-sm">
                        {exams.links.map((l, i) => (
                            <Link key={i} href={l.url ?? '#'} preserveScroll className={`rounded-md px-3 py-1.5 ${l.active ? 'bg-foreground text-background' : 'border border-border'} ${!l.url ? 'pointer-events-none opacity-40' : ''}`} dangerouslySetInnerHTML={{ __html: l.label }} />
                        ))}
                    </nav>
                )}
            </div>
        </>
    );
}

ExamBoard.layout = { breadcrumbs: [{ title: 'Exam board', href: '/exam-board' }] };
