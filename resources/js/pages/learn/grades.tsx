import { Head } from '@inertiajs/react';
import { formatDateTime, PageHeader } from '@/components/platform/ui';

type Grade = {
    course: string;
    title: string;
    units: number;
    semester: string;
    total: number;
    grade: string;
    breakdown: { category: string; weight: number; percent: number | null }[];
    released_at: string;
};

export default function Grades({ grades, cgpa }: { grades: Grade[]; cgpa: number | null }) {
    // CGPA comes from the server so it uses the institution's own grade points
    const gp = cgpa === null ? '—' : cgpa.toFixed(2);

    return (
        <>
            <Head title="My course results" />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader
                    title="Course results,"
                    accent={`CGPA ${gp}`}
                    lead="Released results only. Each shows how continuous assessment and the exam made up your total."
                    actions={
                        grades.length > 0 ? (
                            <>
                                <a href="/my/documents/result-statement" className="rounded-xl border border-border px-4 py-2 text-sm">Statement of results (PDF)</a>
                                <a href="/my/documents/transcript" className="rounded-xl bg-brand px-4 py-2 text-sm font-medium text-brand-foreground">Transcript (PDF)</a>
                            </>
                        ) : undefined
                    }
                />
                {grades.length === 0 && (
                    <div className="surface p-8 text-sm text-muted-foreground">
                        No results have been released yet.
                    </div>
                )}
                <div className="grid gap-4 md:grid-cols-2">
                    {grades.map((g) => (
                        <article
                            key={g.course + g.semester}
                            className="surface grid gap-3 p-6"
                        >
                            <div className="flex items-start justify-between">
                                <div>
                                    <span className="font-mono text-sm text-brand">
                                        {g.course}
                                    </span>
                                    <h3 className="font-medium">{g.title}</h3>
                                    <p className="text-xs text-muted-foreground">
                                        {g.semester} · {g.units} units
                                    </p>
                                </div>
                                <div className="text-right">
                                    <p className="text-3xl font-semibold">
                                        {g.grade}
                                    </p>
                                    <p className="text-sm text-muted-foreground tabular-nums">
                                        {g.total}
                                    </p>
                                </div>
                            </div>
                            <ul className="grid gap-1 text-sm">
                                {g.breakdown?.map((b) => (
                                    <li
                                        key={b.category}
                                        className="flex justify-between"
                                    >
                                        <span>
                                            {b.category} ({b.weight}%)
                                        </span>
                                        <span className="tabular-nums">
                                            {b.percent ?? '—'}%
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            <p className="text-xs text-muted-foreground">
                                Released {formatDateTime(g.released_at)}
                            </p>
                        </article>
                    ))}
                </div>
            </div>
        </>
    );
}
