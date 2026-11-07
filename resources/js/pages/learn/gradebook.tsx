import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Download } from 'lucide-react';
import { useState } from 'react';
import {
    Flash,
    inputClass,
    PageHeader,
    Stat,
    Status,
} from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Category = {
    id: number;
    name: string;
    weight: number;
    aggregation: string;
    best_n: number | null;
};
type GItem = {
    id: number;
    title: string;
    source: string;
    max: number;
    category: number;
};
type Row = {
    id: number;
    name: string;
    identifier: string | null;
    entries: Record<number, { score: number | null; overridden: boolean }>;
    total: number | null;
    grade: string | null;
    status: string;
};
type Props = {
    offering: { id: number; label: string; title: string };
    categories: Category[];
    items: GItem[];
    rows: Row[];
    canGrade: boolean;
    canApprove: boolean;
    participation?: Record<number, { score: number; attendance: number | null; completion: number; on_time: number | null; posts: number }>;
    stats: {
        count: number;
        mean: number | null;
        pass_rate: number | null;
        distribution: Record<string, number>;
    };
};

export default function Gradebook({
    offering,
    categories,
    items,
    rows,
    canGrade,
    canApprove,
    stats,
    participation = {},
}: Props) {
    const [weights, setWeights] = useState(categories);
    const column = useForm({
        title: '',
        max_score: 20,
        grade_category_id: categories[0]?.id ?? 0,
        source: 'manual',
    });
    const sum = weights.reduce((a, c) => a + Number(c.weight), 0);
    const statuses = new Set(rows.map((r) => r.status));
    const save = (gi: GItem, row: Row, value: string) => {
        const old = row.entries[gi.id]?.score;
        const next = value === '' ? null : Number(value);

        if (next !== old) {
            router.post(
                `/learn/grade-items/${gi.id}/entry`,
                { user_id: row.id, score: next },
                { preserveScroll: true, preserveState: true },
            );
        }
    };

    return (
        <>
            <Head title={`Gradebook · ${offering.label}`} />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <Link
                    href={`/learn/courses/${offering.id}`}
                    className="flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-brand"
                >
                    <ArrowLeft className="size-4" /> {offering.title}
                </Link>
                <PageHeader
                    title={offering.label}
                    lead="Assignments, quizzes, attendance and central exam results combine by the weights below. Released results are locked."
                    actions={
                        <a
                            href={`/learn/courses/${offering.id}/gradebook/export`}
                            className="flex items-center gap-1 rounded-xl border border-border px-3 py-2 text-sm"
                        >
                            <Download className="size-4" /> Export CSV
                        </a>
                    }
                />
                <Flash />
                <div className="grid gap-4 sm:grid-cols-4">
                    <Stat label="Students" value={stats.count} />
                    <Stat label="Average" value={stats.mean ?? '—'} />
                    <Stat
                        label="Pass rate"
                        value={
                            stats.pass_rate === null
                                ? '—'
                                : `${stats.pass_rate}%`
                        }
                        tone={
                            stats.pass_rate !== null && stats.pass_rate < 50
                                ? 'warning'
                                : undefined
                        }
                    />
                    <Stat
                        label="Grades"
                        value={
                            <span className="text-base">
                                {Object.entries(stats.distribution)
                                    .sort()
                                    .map(([g, n]) => `${g}:${n}`)
                                    .join('  ') || '—'}
                            </span>
                        }
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <form
                        className="surface grid content-start gap-3 p-5"
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.put(
                                `/learn/courses/${offering.id}/gradebook/categories`,
                                { categories: weights } as never,
                                { preserveScroll: true },
                            );
                        }}
                    >
                        <p className="eyebrow">
                            Weighting{' '}
                            {sum !== 100 && (
                                <span className="text-status-critical">
                                    · adds to {sum}, must be 100
                                </span>
                            )}
                        </p>
                        {weights.map((c, i) => (
                            <div
                                key={c.id ?? i}
                                className="grid grid-cols-[1fr_90px_120px] gap-2"
                            >
                                <input
                                    className={inputClass}
                                    value={c.name}
                                    disabled={!canGrade}
                                    onChange={(e) =>
                                        setWeights(
                                            weights.map((w, j) =>
                                                j === i
                                                    ? {
                                                          ...w,
                                                          name: e.target.value,
                                                      }
                                                    : w,
                                            ),
                                        )
                                    }
                                />
                                <input
                                    type="number"
                                    className={inputClass}
                                    value={c.weight}
                                    disabled={!canGrade}
                                    onChange={(e) =>
                                        setWeights(
                                            weights.map((w, j) =>
                                                j === i
                                                    ? {
                                                          ...w,
                                                          weight: Number(
                                                              e.target.value,
                                                          ),
                                                      }
                                                    : w,
                                            ),
                                        )
                                    }
                                    aria-label="Weight %"
                                />
                                <select
                                    className={inputClass}
                                    value={c.aggregation}
                                    disabled={!canGrade}
                                    onChange={(e) =>
                                        setWeights(
                                            weights.map((w, j) =>
                                                j === i
                                                    ? {
                                                          ...w,
                                                          aggregation:
                                                              e.target.value,
                                                      }
                                                    : w,
                                            ),
                                        )
                                    }
                                >
                                    <option value="mean">Average</option>
                                    <option value="sum">Sum of marks</option>
                                    <option value="best_n">Best N</option>
                                </select>
                            </div>
                        ))}
                        {canGrade && (
                            <div className="flex gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    type="button"
                                    onClick={() =>
                                        setWeights([
                                            ...weights,
                                            {
                                                id: undefined as unknown as number,
                                                name: 'New category',
                                                weight: 0,
                                                aggregation: 'mean',
                                                best_n: null,
                                            },
                                        ])
                                    }
                                >
                                    Add category
                                </Button>
                                <Button size="sm" disabled={sum !== 100}>
                                    Save weighting
                                </Button>
                            </div>
                        )}
                    </form>
                    {canGrade && (
                        <form
                            className="surface grid content-start gap-3 p-5"
                            onSubmit={(e) => {
                                e.preventDefault();
                                column.post(
                                    `/learn/courses/${offering.id}/gradebook/items`,
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => column.reset('title'),
                                    },
                                );
                            }}
                        >
                            <p className="eyebrow">
                                Add a column (class test, practical,
                                attendance…)
                            </p>
                            <input
                                className={inputClass}
                                placeholder="Title, e.g. Test 1"
                                value={column.data.title}
                                onChange={(e) =>
                                    column.setData('title', e.target.value)
                                }
                                required
                            />
                            <div className="grid grid-cols-3 gap-2">
                                <input
                                    type="number"
                                    className={inputClass}
                                    value={column.data.max_score}
                                    onChange={(e) =>
                                        column.setData(
                                            'max_score',
                                            Number(e.target.value),
                                        )
                                    }
                                    aria-label="Out of"
                                />
                                <select
                                    className={inputClass}
                                    value={column.data.grade_category_id}
                                    onChange={(e) =>
                                        column.setData(
                                            'grade_category_id',
                                            Number(e.target.value),
                                        )
                                    }
                                >
                                    {categories.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}
                                        </option>
                                    ))}
                                </select>
                                <select
                                    className={inputClass}
                                    value={column.data.source}
                                    onChange={(e) =>
                                        column.setData('source', e.target.value)
                                    }
                                >
                                    <option value="manual">
                                        Marks I enter
                                    </option>
                                    <option value="attendance">
                                        From attendance
                                    </option>
                                </select>
                            </div>
                            <Button size="sm" className="w-fit">
                                Add column
                            </Button>
                        </form>
                    )}
                </div>

                <div className="surface overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-border text-left">
                                <th className="sticky left-0 bg-card p-3 font-medium">
                                    Student
                                </th>
                                {items.map((i) => (
                                    <th
                                        key={i.id}
                                        className="p-3 font-medium whitespace-nowrap"
                                    >
                                        {i.title}
                                        <span className="block text-xs font-normal text-muted-foreground">
                                            /{i.max} ·{' '}
                                            {
                                                categories.find(
                                                    (c) => c.id === i.category,
                                                )?.name
                                            }
                                        </span>
                                    </th>
                                ))}
                                <th className="p-3 font-medium">Total</th>
                                <th className="p-3 font-medium">Grade</th>
                                <th className="p-3 font-medium">Status</th>
                                <th className="p-3 font-medium" title="Attendance, materials, on-time work and forum posts — a guide, not part of the grade">Participation</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((r) => (
                                <tr
                                    key={r.id}
                                    className="border-b border-border/60"
                                >
                                    <td className="sticky left-0 bg-card p-3">
                                        <span className="font-medium">
                                            {r.name}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {r.identifier}
                                        </span>
                                    </td>
                                    {items.map((gi) => (
                                        <td key={gi.id} className="p-2">
                                            {canGrade &&
                                            r.status !== 'released' &&
                                            (gi.source === 'manual' ||
                                                gi.source === 'exam' ||
                                                gi.source === 'item') ? (
                                                <input
                                                    defaultValue={
                                                        r.entries[gi.id]
                                                            ?.score ?? ''
                                                    }
                                                    onBlur={(e) =>
                                                        save(
                                                            gi,
                                                            r,
                                                            e.target.value,
                                                        )
                                                    }
                                                    inputMode="decimal"
                                                    className={`h-9 w-20 rounded-lg border bg-transparent px-2 tabular-nums ${r.entries[gi.id]?.overridden ? 'border-status-warning' : 'border-transparent hover:border-border'}`}
                                                    title={
                                                        r.entries[gi.id]
                                                            ?.overridden
                                                            ? 'Changed by hand (kept when the source is re-synced)'
                                                            : undefined
                                                    }
                                                />
                                            ) : (
                                                <span className="tabular-nums">
                                                    {r.entries[gi.id]?.score ??
                                                        '—'}
                                                </span>
                                            )}
                                        </td>
                                    ))}
                                    <td className="p-3 font-semibold tabular-nums">
                                        {r.total ?? '—'}
                                    </td>
                                    <td className="p-3">{r.grade}</td>
                                    <td className="p-3">
                                        <Status value={r.status} />
                                    </td>
                                    <td className="p-3 tabular-nums" title={participation[r.id] ? `Attendance ${participation[r.id].attendance ?? '—'}% · materials ${participation[r.id].completion}% · on time ${participation[r.id].on_time ?? '—'}% · ${participation[r.id].posts} posts` : ''}>
                                        {participation[r.id]?.score ?? '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="flex flex-wrap gap-2">
                    {canApprove && statuses.has('draft') && (
                        <Button
                            onClick={() =>
                                confirm('Approve these course results?') &&
                                router.post(
                                    `/learn/courses/${offering.id}/gradebook/transition`,
                                    { action: 'approve' },
                                )
                            }
                        >
                            Approve results (HOD / Dean)
                        </Button>
                    )}
                    {canGrade && statuses.has('approved') && (
                        <Button
                            onClick={() =>
                                confirm(
                                    'Release approved results to students? They are locked after this.',
                                ) &&
                                router.post(
                                    `/learn/courses/${offering.id}/gradebook/transition`,
                                    { action: 'release' },
                                )
                            }
                        >
                            Release to students
                        </Button>
                    )}
                    {canGrade && !canApprove && statuses.has('draft') && (
                        <p className="self-center text-sm text-muted-foreground">
                            Results need HOD or Dean approval before release.
                        </p>
                    )}
                </div>
            </div>
        </>
    );
}
