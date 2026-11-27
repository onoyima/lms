import { Link, router, useForm } from '@inertiajs/react';
import {
    BarChart3,
    CheckCircle2,
    ChevronUp,
    Lock,
    MessageSquareReply,
    Plus,
    Star,
    Trash2,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { KIND_ICON, KIND_TONE } from '@/components/lms/material';
import {
    formatDateTime,
    inputClass,
    textareaClass,
} from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

export type Feedback = {
    reviews: {
        average: number | null;
        count: number;
        counts: Record<string, number>;
        mine: {
            rating: number;
            comment: string | null;
            anonymous: boolean;
        } | null;
        list: {
            id: number;
            rating: number;
            comment: string | null;
            created_at: string;
            author: string;
            reply: string | null;
            replied_at: string | null;
            hidden: boolean;
            mine: boolean;
        }[];
    };
    polls: {
        id: number;
        question: string;
        options: string[];
        multiple: boolean;
        anonymous: boolean;
        show_results: 'after_vote' | 'after_close' | 'teacher';
        closes_at: string | null;
        closed: boolean;
        created_at: string;
        voters: number;
        mine: number[] | null;
        results: number[] | null;
    }[];
    requests: {
        id: number;
        kind: 'topic' | 'material' | 'explanation';
        title: string;
        details: string | null;
        status: 'open' | 'planned' | 'covered' | 'declined';
        reply: string | null;
        replied_at: string | null;
        votes: number;
        voted: boolean;
        mine: boolean;
        author: string | null;
        created_at: string;
        item: { id: number; title: string | null } | null;
    }[];
};

export type Assessment = {
    id: number;
    type: 'assignment' | 'quiz';
    title: string;
    section: string | null;
    due_at: string | null;
    open: boolean;
    locked_reason: string | null;
    max: number | null;
    // student
    state?: 'to_do' | 'submitted' | 'marked' | 'missed';
    score?: number | null;
    late?: boolean;
    // lecturer
    submitted?: number;
    to_mark?: number;
    enrolled?: number;
};

const empty = (text: string) => (
    <p className="surface p-6 text-sm text-muted-foreground">{text}</p>
);

// ── assessments ──────────────────────────────────────────────────────────────

const STATE: Record<string, [string, string]> = {
    to_do: ['To do', 'bg-amber-500/14 text-amber-700 dark:text-amber-300'],
    submitted: ['Submitted', 'bg-sky-500/12 text-sky-700 dark:text-sky-300'],
    marked: ['Marked', 'bg-status-good/12 text-status-good'],
    missed: ['Missed', 'bg-status-critical/10 text-status-critical'],
};

export function Assessments({
    list,
    teacher,
}: {
    list: Assessment[];
    teacher: boolean;
}) {
    if (list.length === 0) {
        return empty(
            teacher
                ? 'No assignments or quizzes yet. Add them to a week with “Add”.'
                : 'No assignments or quizzes yet.',
        );
    }

    const marked = list.filter(
        (a) => a.state === 'marked' && a.score != null && a.max,
    );
    const total = marked.reduce((n, a) => n + (a.score ?? 0), 0);
    const outOf = marked.reduce((n, a) => n + (a.max ?? 0), 0);

    return (
        <div className="grid gap-4">
            {!teacher && (
                <div className="grid grid-cols-3 gap-3 text-center">
                    {[
                        [
                            'To do',
                            list.filter((a) => a.state === 'to_do').length,
                        ],
                        [
                            'Submitted',
                            list.filter(
                                (a) =>
                                    a.state === 'submitted' ||
                                    a.state === 'marked',
                            ).length,
                        ],
                        [
                            'Score so far',
                            outOf
                                ? `${Math.round((total / outOf) * 100)}%`
                                : '—',
                        ],
                    ].map(([l, v]) => (
                        <div key={l} className="surface p-3 sm:p-4">
                            <p className="text-xl font-semibold tabular-nums sm:text-2xl">
                                {v}
                            </p>
                            <p className="text-xs text-muted-foreground">{l}</p>
                        </div>
                    ))}
                </div>
            )}
            <ul className="grid gap-2">
                {list.map((a) => {
                    const Icon = KIND_ICON[a.type];
                    const [label, tone] = a.state ? STATE[a.state] : ['', ''];
                    const row = (
                        <>
                            <span
                                className={`grid size-10 shrink-0 place-items-center rounded-xl ${KIND_TONE[a.type]}`}
                            >
                                {a.open ? (
                                    <Icon className="size-5" />
                                ) : (
                                    <Lock className="size-4" />
                                )}
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block font-medium">
                                    {a.title}
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    {a.type === 'quiz' ? 'Quiz' : 'Assignment'}
                                    {a.section && ` · ${a.section}`}
                                    {a.due_at &&
                                        ` · due ${formatDateTime(a.due_at)}`}
                                    {a.locked_reason && ` · ${a.locked_reason}`}
                                </span>
                            </span>
                            <span className="shrink-0 text-right text-sm">
                                {teacher ? (
                                    <>
                                        <span className="block tabular-nums">
                                            {a.submitted}/{a.enrolled} in
                                        </span>
                                        {!!a.to_mark && (
                                            <span className="block text-xs font-medium text-status-warning">
                                                {a.to_mark} to mark
                                            </span>
                                        )}
                                    </>
                                ) : (
                                    <>
                                        <span
                                            className={`inline-block rounded-full px-2.5 py-0.5 text-xs font-medium ${tone}`}
                                        >
                                            {label}
                                        </span>
                                        {a.state === 'marked' &&
                                            a.score != null && (
                                                <span className="mt-1 block text-sm font-semibold tabular-nums">
                                                    {a.score}
                                                    {a.max ? ` / ${a.max}` : ''}
                                                </span>
                                            )}
                                        {a.late && (
                                            <span className="block text-xs text-status-warning">
                                                late
                                            </span>
                                        )}
                                    </>
                                )}
                            </span>
                        </>
                    );

                    return (
                        <li key={a.id}>
                            {a.open ? (
                                <Link
                                    href={`/learn/items/${a.id}`}
                                    className="surface flex items-center gap-3 p-3 hover:border-brand/50 sm:p-4"
                                >
                                    {row}
                                </Link>
                            ) : (
                                <div className="surface flex items-center gap-3 p-3 opacity-75 sm:p-4">
                                    {row}
                                </div>
                            )}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

// ── reviews ──────────────────────────────────────────────────────────────────

function Stars({ value, size = 'size-4' }: { value: number; size?: string }) {
    return (
        <span
            className="inline-flex text-amber-500"
            aria-label={`${value} out of 5`}
        >
            {[1, 2, 3, 4, 5].map((n) => (
                <Star
                    key={n}
                    className={`${size} ${n <= Math.round(value) ? 'fill-current' : 'opacity-35'}`}
                />
            ))}
        </span>
    );
}

export function Reviews({
    offeringId,
    reviews,
    teacher,
}: {
    offeringId: number;
    reviews: Feedback['reviews'];
    teacher: boolean;
}) {
    const [editing, setEditing] = useState(!reviews.mine && !teacher);
    const form = useForm({
        rating: reviews.mine?.rating ?? 0,
        comment: reviews.mine?.comment ?? '',
        anonymous: reviews.mine?.anonymous ?? false,
    });
    const [hover, setHover] = useState(0);
    const max = Math.max(1, ...Object.values(reviews.counts));

    return (
        <div className="grid gap-5 lg:grid-cols-[300px_1fr]">
            <aside className="surface grid content-start gap-4 p-5">
                <div>
                    <p className="text-4xl font-semibold tabular-nums">
                        {reviews.average ?? '—'}
                    </p>
                    {reviews.average && (
                        <Stars value={reviews.average} size="size-5" />
                    )}
                    <p className="mt-1 text-sm text-muted-foreground">
                        {reviews.count} review{reviews.count === 1 ? '' : 's'}
                    </p>
                </div>
                <div className="grid gap-1.5">
                    {[5, 4, 3, 2, 1].map((n) => (
                        <div
                            key={n}
                            className="flex items-center gap-2 text-xs"
                        >
                            <span className="w-3 tabular-nums">{n}</span>
                            <Star className="size-3 fill-amber-500 text-amber-500" />
                            <span className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                <span
                                    className="block h-full rounded-full bg-amber-500"
                                    style={{
                                        width: `${((reviews.counts[n] ?? 0) / max) * 100}%`,
                                    }}
                                />
                            </span>
                            <span className="w-5 text-right text-muted-foreground tabular-nums">
                                {reviews.counts[n] ?? 0}
                            </span>
                        </div>
                    ))}
                </div>
            </aside>

            <div className="grid content-start gap-4">
                {!teacher && editing && (
                    <form
                        className="surface grid gap-3 p-5"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(`/learn/courses/${offeringId}/reviews`, {
                                preserveScroll: true,
                                onSuccess: () => setEditing(false),
                            });
                        }}
                    >
                        <p className="font-medium">
                            {reviews.mine
                                ? 'Change your review'
                                : 'How is this course going for you?'}
                        </p>
                        <div
                            className="flex items-center gap-1"
                            role="radiogroup"
                            aria-label="Your rating"
                            onMouseLeave={() => setHover(0)}
                        >
                            {[1, 2, 3, 4, 5].map((n) => (
                                <button
                                    key={n}
                                    type="button"
                                    role="radio"
                                    aria-checked={form.data.rating === n}
                                    aria-label={`${n} star${n === 1 ? '' : 's'}`}
                                    className="p-1 text-amber-500"
                                    onMouseEnter={() => setHover(n)}
                                    onClick={() => form.setData('rating', n)}
                                >
                                    <Star
                                        className={`size-7 ${n <= (hover || form.data.rating) ? 'fill-current' : 'opacity-35'}`}
                                    />
                                </button>
                            ))}
                        </div>
                        <textarea
                            className={textareaClass}
                            placeholder="What works well? What could be better? (optional)"
                            value={form.data.comment}
                            onChange={(e) =>
                                form.setData('comment', e.target.value)
                            }
                            maxLength={3000}
                        />
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={form.data.anonymous}
                                onChange={(e) =>
                                    form.setData('anonymous', e.target.checked)
                                }
                            />{' '}
                            Post without my name
                        </label>
                        {form.errors.rating && (
                            <p className="text-sm text-status-critical">
                                Choose 1 to 5 stars.
                            </p>
                        )}
                        <div className="flex gap-2">
                            <Button
                                disabled={
                                    form.processing || form.data.rating === 0
                                }
                            >
                                {reviews.mine ? 'Save review' : 'Post review'}
                            </Button>
                            {reviews.mine && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => setEditing(false)}
                                >
                                    Cancel
                                </Button>
                            )}
                        </div>
                    </form>
                )}
                {!teacher && !editing && reviews.mine && (
                    <div className="flex flex-wrap items-center gap-3 text-sm">
                        <span className="text-muted-foreground">
                            You rated this course
                        </span>{' '}
                        <Stars value={reviews.mine.rating} />
                        <button
                            className="text-brand hover:underline"
                            onClick={() => setEditing(true)}
                        >
                            Change
                        </button>
                    </div>
                )}

                {reviews.list.length === 0 &&
                    empty(
                        teacher
                            ? 'No reviews yet. Students can rate the course from this tab.'
                            : 'No reviews yet. Be the first.',
                    )}
                {reviews.list.map((r) => (
                    <ReviewCard key={r.id} r={r} teacher={teacher} />
                ))}
            </div>
        </div>
    );
}

function ReviewCard({
    r,
    teacher,
}: {
    r: Feedback['reviews']['list'][number];
    teacher: boolean;
}) {
    const [replying, setReplying] = useState(false);
    const [reply, setReply] = useState(r.reply ?? '');

    return (
        <article
            className={`surface grid gap-2 p-5 ${r.hidden ? 'opacity-60' : ''}`}
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="flex items-center gap-2">
                    <Stars value={r.rating} />
                    <span className="text-sm font-medium">{r.author}</span>
                </span>
                <span className="text-xs text-muted-foreground">
                    {formatDateTime(r.created_at)}
                </span>
            </div>
            {r.comment && (
                <p className="text-sm leading-relaxed whitespace-pre-line">
                    {r.comment}
                </p>
            )}
            {r.hidden && (
                <p className="text-xs text-status-warning">
                    Hidden from students
                </p>
            )}
            {r.reply && !replying && (
                <div className="mt-1 rounded-xl bg-muted/60 p-3 text-sm">
                    <p className="text-xs font-medium text-muted-foreground">
                        Lecturer’s reply
                    </p>
                    <p className="whitespace-pre-line">{r.reply}</p>
                </div>
            )}
            {teacher && (
                <div className="flex flex-wrap gap-3 text-xs">
                    <button
                        className="flex items-center gap-1 text-brand hover:underline"
                        onClick={() => setReplying(!replying)}
                    >
                        <MessageSquareReply className="size-3.5" />{' '}
                        {r.reply ? 'Edit reply' : 'Reply'}
                    </button>
                    <button
                        className="text-muted-foreground hover:underline"
                        onClick={() =>
                            router.post(
                                `/learn/reviews/${r.id}/reply`,
                                { hidden: !r.hidden },
                                { preserveScroll: true },
                            )
                        }
                    >
                        {r.hidden
                            ? 'Show to students'
                            : 'Hide (offensive or off-topic)'}
                    </button>
                </div>
            )}
            {!teacher && r.mine && (
                <button
                    className="w-fit text-xs text-muted-foreground hover:text-status-critical"
                    onClick={() =>
                        confirm('Remove your review?') &&
                        router.delete(`/learn/reviews/${r.id}`, {
                            preserveScroll: true,
                        })
                    }
                >
                    Remove my review
                </button>
            )}
            {replying && (
                <form
                    className="grid gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.post(
                            `/learn/reviews/${r.id}/reply`,
                            { reply },
                            {
                                preserveScroll: true,
                                onSuccess: () => setReplying(false),
                            },
                        );
                    }}
                >
                    <textarea
                        className={textareaClass}
                        value={reply}
                        onChange={(e) => setReply(e.target.value)}
                        placeholder="Thank the student, answer the point, say what will change…"
                        maxLength={3000}
                    />
                    <Button size="sm" className="w-fit">
                        Send reply
                    </Button>
                </form>
            )}
        </article>
    );
}

// ── polls ────────────────────────────────────────────────────────────────────

export function Polls({
    offeringId,
    polls,
    teacher,
}: {
    offeringId: number;
    polls: Feedback['polls'];
    teacher: boolean;
}) {
    const [creating, setCreating] = useState(false);

    return (
        <div className="grid gap-4">
            {teacher && !creating && (
                <Button className="w-fit" onClick={() => setCreating(true)}>
                    <Plus className="size-4" /> New poll
                </Button>
            )}
            {teacher && creating && (
                <NewPoll
                    offeringId={offeringId}
                    onDone={() => setCreating(false)}
                />
            )}
            {polls.length === 0 &&
                !creating &&
                empty(
                    teacher
                        ? 'Ask the class something quick: a date for revision, which topic was hardest, how the pace feels.'
                        : 'No polls yet.',
                )}
            <div className="grid gap-4 md:grid-cols-2">
                {polls.map((p) => (
                    <PollCard key={p.id} p={p} teacher={teacher} />
                ))}
            </div>
        </div>
    );
}

function NewPoll({
    offeringId,
    onDone,
}: {
    offeringId: number;
    onDone: () => void;
}) {
    const form = useForm({
        question: '',
        options: ['', ''],
        multiple: false,
        anonymous: true,
        show_results: 'after_vote',
        closes_at: '',
        notify: true,
    });

    return (
        <form
            className="surface grid gap-3 p-5"
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((d) => ({
                    ...d,
                    options: d.options.map((o) => o.trim()).filter(Boolean),
                    closes_at: d.closes_at || null,
                }));
                form.post(`/learn/courses/${offeringId}/polls`, {
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
        >
            <input
                className={inputClass}
                placeholder="Question, e.g. When should we hold the revision class?"
                value={form.data.question}
                onChange={(e) => form.setData('question', e.target.value)}
                required
                maxLength={300}
            />
            <div className="grid gap-2">
                {form.data.options.map((o, i) => (
                    <div key={i} className="flex gap-2">
                        <input
                            className={inputClass}
                            placeholder={`Option ${i + 1}`}
                            value={o}
                            onChange={(e) =>
                                form.setData(
                                    'options',
                                    form.data.options.map((x, j) =>
                                        j === i ? e.target.value : x,
                                    ),
                                )
                            }
                            maxLength={200}
                        />
                        {form.data.options.length > 2 && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label="Remove option"
                                onClick={() =>
                                    form.setData(
                                        'options',
                                        form.data.options.filter(
                                            (_, j) => j !== i,
                                        ),
                                    )
                                }
                            >
                                <X className="size-4" />
                            </Button>
                        )}
                    </div>
                ))}
                {form.data.options.length < 12 && (
                    <button
                        type="button"
                        className="w-fit text-sm text-brand hover:underline"
                        onClick={() =>
                            form.setData('options', [...form.data.options, ''])
                        }
                    >
                        + Add an option
                    </button>
                )}
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="grid gap-1 text-xs text-muted-foreground">
                    Students see the results
                    <select
                        className={inputClass}
                        value={form.data.show_results}
                        onChange={(e) =>
                            form.setData('show_results', e.target.value)
                        }
                    >
                        <option value="after_vote">after they vote</option>
                        <option value="after_close">
                            when the poll closes
                        </option>
                        <option value="teacher">never (only lecturers)</option>
                    </select>
                </label>
                <label className="grid gap-1 text-xs text-muted-foreground">
                    Closes (optional)
                    <input
                        type="datetime-local"
                        className={inputClass}
                        value={form.data.closes_at}
                        onChange={(e) =>
                            form.setData('closes_at', e.target.value)
                        }
                    />
                </label>
            </div>
            <div className="flex flex-wrap gap-x-5 gap-y-2 text-sm">
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        checked={form.data.multiple}
                        onChange={(e) =>
                            form.setData('multiple', e.target.checked)
                        }
                    />{' '}
                    More than one choice allowed
                </label>
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        checked={form.data.notify}
                        onChange={(e) =>
                            form.setData('notify', e.target.checked)
                        }
                    />{' '}
                    Tell the students
                </label>
            </div>
            {Object.values(form.errors).length > 0 && (
                <p className="text-sm text-status-critical">
                    {Object.values(form.errors)[0]}
                </p>
            )}
            <div className="flex gap-2">
                <Button disabled={form.processing}>Post poll</Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

function PollCard({
    p,
    teacher,
}: {
    p: Feedback['polls'][number];
    teacher: boolean;
}) {
    const [choice, setChoice] = useState<number[]>(p.mine ?? []);
    const [changing, setChanging] = useState(false);
    const voted = !!p.mine && !changing;
    const total = p.results ? Math.max(1, p.voters) : 1;
    const canVote = !teacher && !p.closed && (!p.mine || changing);

    return (
        <article className="surface grid content-start gap-3 p-5">
            <div className="flex items-start justify-between gap-2">
                <h3 className="font-medium">{p.question}</h3>
                {p.closed ? (
                    <span className="shrink-0 rounded-full bg-muted px-2 py-0.5 text-xs">
                        Closed
                    </span>
                ) : (
                    <span className="shrink-0 rounded-full bg-status-good/12 px-2 py-0.5 text-xs text-status-good">
                        Open
                    </span>
                )}
            </div>
            <div
                className="grid gap-2"
                role={p.multiple ? 'group' : 'radiogroup'}
                aria-label={p.question}
            >
                {p.options.map((o, i) => {
                    const n = p.results?.[i] ?? 0;
                    const pct = p.results ? Math.round((n / total) * 100) : 0;
                    const picked = choice.includes(i);

                    return canVote ? (
                        <button
                            key={i}
                            type="button"
                            role={p.multiple ? 'checkbox' : 'radio'}
                            aria-checked={picked}
                            onClick={() =>
                                setChoice(
                                    p.multiple
                                        ? picked
                                            ? choice.filter((c) => c !== i)
                                            : [...choice, i]
                                        : [i],
                                )
                            }
                            className={`flex items-center gap-3 rounded-xl border px-3 py-2.5 text-left text-sm transition ${picked ? 'border-brand bg-brand-soft/60' : 'border-border hover:border-brand/50'}`}
                        >
                            <span
                                className={`grid size-5 shrink-0 place-items-center border ${p.multiple ? 'rounded-md' : 'rounded-full'} ${picked ? 'border-brand bg-brand text-brand-foreground' : 'border-input'}`}
                            >
                                {picked && (
                                    <CheckCircle2 className="size-3.5" />
                                )}
                            </span>
                            {o}
                        </button>
                    ) : (
                        <div
                            key={i}
                            className="relative overflow-hidden rounded-xl border border-border px-3 py-2.5 text-sm"
                        >
                            {p.results && (
                                <span
                                    className="absolute inset-y-0 left-0 bg-brand-soft"
                                    style={{ width: `${pct}%` }}
                                />
                            )}
                            <span className="relative flex justify-between gap-3">
                                <span
                                    className={
                                        p.mine?.includes(i)
                                            ? 'font-semibold'
                                            : ''
                                    }
                                >
                                    {o}
                                    {p.mine?.includes(i) ? ' · your vote' : ''}
                                </span>
                                {p.results && (
                                    <span className="text-muted-foreground tabular-nums">
                                        {pct}% ({n})
                                    </span>
                                )}
                            </span>
                        </div>
                    );
                })}
            </div>
            <p className="text-xs text-muted-foreground">
                {p.voters} vote{p.voters === 1 ? '' : 's'}
                {p.multiple && ' · several choices allowed'}
                {p.closes_at &&
                    !p.closed &&
                    ` · closes ${formatDateTime(p.closes_at)}`}
                {!p.results &&
                    !teacher &&
                    (p.show_results === 'after_close'
                        ? ' · results when the poll closes'
                        : p.show_results === 'teacher'
                          ? ' · results go to the lecturer'
                          : '')}
            </p>
            {canVote && (
                <div className="flex gap-2">
                    <Button
                        size="sm"
                        disabled={choice.length === 0}
                        onClick={() =>
                            router.post(
                                `/learn/polls/${p.id}/vote`,
                                { choices: choice },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => setChanging(false),
                                },
                            )
                        }
                    >
                        Vote
                    </Button>
                    {changing && (
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => (
                                setChanging(false),
                                setChoice(p.mine ?? [])
                            )}
                        >
                            Cancel
                        </Button>
                    )}
                </div>
            )}
            {!teacher && voted && !p.closed && (
                <button
                    className="w-fit text-xs text-brand hover:underline"
                    onClick={() => setChanging(true)}
                >
                    Change my vote
                </button>
            )}
            {teacher && (
                <div className="flex gap-3 text-xs">
                    <button
                        className="flex items-center gap-1 text-brand hover:underline"
                        onClick={() =>
                            router.post(
                                `/learn/polls/${p.id}/close`,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <BarChart3 className="size-3.5" />{' '}
                        {p.closed ? 'Open again' : 'Close poll'}
                    </button>
                    <button
                        className="flex items-center gap-1 text-muted-foreground hover:text-status-critical"
                        onClick={() =>
                            confirm('Delete this poll and its votes?') &&
                            router.delete(`/learn/polls/${p.id}`, {
                                preserveScroll: true,
                            })
                        }
                    >
                        <Trash2 className="size-3.5" /> Delete
                    </button>
                </div>
            )}
        </article>
    );
}

// ── requests for topics and material ─────────────────────────────────────────

const REQ_STATUS: Record<string, [string, string]> = {
    open: ['Waiting for the lecturer', 'bg-muted text-foreground'],
    planned: ['Planned', 'bg-sky-500/12 text-sky-700 dark:text-sky-300'],
    covered: ['Covered', 'bg-status-good/12 text-status-good'],
    declined: ['Answered', 'bg-muted text-muted-foreground'],
};
const KINDS = {
    topic: 'A topic not covered',
    material: 'Material (notes, slides, past questions)',
    explanation: 'More explanation on something',
} as const;

export function Requests({
    offeringId,
    requests,
    teacher,
    items,
    ask,
}: {
    offeringId: number;
    requests: Feedback['requests'];
    teacher: boolean;
    items: { id: number; title: string }[];
    ask?: string | null;
}) {
    const form = useForm<{
        kind: keyof typeof KINDS;
        title: string;
        details: string;
    }>({
        kind: ask ? 'explanation' : 'topic',
        title: ask ? `About “${ask}”` : '',
        details: '',
    });
    const [filter, setFilter] = useState<'all' | 'open' | 'done'>('all');
    const shown = requests.filter(
        (r) =>
            filter === 'all' ||
            (filter === 'open'
                ? ['open', 'planned'].includes(r.status)
                : ['covered', 'declined'].includes(r.status)),
    );

    return (
        <div className="grid gap-5 lg:grid-cols-[1fr_340px]">
            <div className="order-2 grid content-start gap-3 lg:order-1">
                <div className="flex flex-wrap gap-2 text-sm">
                    {(['all', 'open', 'done'] as const).map((f) => (
                        <button
                            key={f}
                            onClick={() => setFilter(f)}
                            className={`rounded-full border px-3 py-1 ${filter === f ? 'border-brand bg-brand text-brand-foreground' : 'border-border hover:border-brand/50'}`}
                        >
                            {f === 'all'
                                ? `All (${requests.length})`
                                : f === 'open'
                                  ? 'Waiting or planned'
                                  : 'Covered or answered'}
                        </button>
                    ))}
                </div>
                {shown.length === 0 &&
                    empty(
                        teacher
                            ? 'No requests. Students can ask here for topics or material they need.'
                            : 'Nothing here yet. Ask for a topic or material the course has not covered.',
                    )}
                {shown.map((r) => (
                    <RequestCard
                        key={r.id}
                        r={r}
                        teacher={teacher}
                        items={items}
                    />
                ))}
            </div>
            {!teacher && (
                <form
                    className="surface order-1 grid content-start gap-3 p-5 lg:order-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/learn/courses/${offeringId}/requests`, {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <p className="font-medium">Ask for something not covered</p>
                    <select
                        className={inputClass}
                        value={form.data.kind}
                        onChange={(e) =>
                            form.setData(
                                'kind',
                                e.target.value as keyof typeof KINDS,
                            )
                        }
                    >
                        {Object.entries(KINDS).map(([k, l]) => (
                            <option key={k} value={k}>
                                {l}
                            </option>
                        ))}
                    </select>
                    <input
                        className={inputClass}
                        placeholder="e.g. Limiting reagents with worked examples"
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                        required
                        maxLength={200}
                    />
                    <textarea
                        className={textareaClass}
                        placeholder="What exactly do you need? (optional)"
                        value={form.data.details}
                        onChange={(e) =>
                            form.setData('details', e.target.value)
                        }
                        maxLength={3000}
                    />
                    {Object.values(form.errors).length > 0 && (
                        <p className="text-sm text-status-critical">
                            {Object.values(form.errors)[0]}
                        </p>
                    )}
                    <Button disabled={form.processing}>
                        Send to the lecturer
                    </Button>
                    <p className="text-xs text-muted-foreground">
                        Classmates see your request (not your name) and can add
                        their vote.
                    </p>
                </form>
            )}
        </div>
    );
}

function RequestCard({
    r,
    teacher,
    items,
}: {
    r: Feedback['requests'][number];
    teacher: boolean;
    items: { id: number; title: string }[];
}) {
    const [answering, setAnswering] = useState(false);
    const [data, setData] = useState({
        status: r.status === 'open' ? 'planned' : r.status,
        reply: r.reply ?? '',
        course_item_id: r.item?.id ?? '',
    });
    const [label, tone] = REQ_STATUS[r.status];

    return (
        <article className="surface flex gap-3 p-4 sm:p-5">
            <button
                type="button"
                disabled={teacher}
                onClick={() =>
                    router.post(
                        `/learn/topic-requests/${r.id}/vote`,
                        {},
                        { preserveScroll: true },
                    )
                }
                className={`flex h-fit w-12 shrink-0 flex-col items-center rounded-xl border py-1.5 text-sm ${r.voted ? 'border-brand bg-brand-soft text-brand-ink' : 'border-border'} ${teacher ? 'cursor-default' : 'hover:border-brand/50'}`}
                aria-pressed={r.voted}
                aria-label={r.voted ? 'Remove my vote' : 'I need this too'}
                title={
                    teacher
                        ? `${r.votes} students asked for this`
                        : r.voted
                          ? 'Remove my vote'
                          : 'I need this too'
                }
            >
                <ChevronUp className="size-4" />
                <span className="font-semibold tabular-nums">{r.votes}</span>
            </button>
            <div className="grid min-w-0 flex-1 gap-1.5">
                <div className="flex flex-wrap items-center gap-2">
                    <span
                        className={`rounded-full px-2 py-0.5 text-xs font-medium ${tone}`}
                    >
                        {label}
                    </span>
                    <span className="text-xs text-muted-foreground">
                        {KINDS[r.kind]} · {formatDateTime(r.created_at)}
                        {r.author && ` · ${r.author}`}
                        {r.mine && ' · yours'}
                    </span>
                </div>
                <h3 className="font-medium">{r.title}</h3>
                {r.details && (
                    <p className="text-sm whitespace-pre-line text-muted-foreground">
                        {r.details}
                    </p>
                )}
                {(r.reply || r.item) && !answering && (
                    <div className="mt-1 rounded-xl bg-muted/60 p-3 text-sm">
                        <p className="text-xs font-medium text-muted-foreground">
                            Lecturer
                        </p>
                        {r.reply && (
                            <p className="whitespace-pre-line">{r.reply}</p>
                        )}
                        {r.item && (
                            <Link
                                href={`/learn/items/${r.item.id}`}
                                className="mt-1 inline-block font-medium text-brand hover:underline"
                            >
                                Open: {r.item.title}
                            </Link>
                        )}
                    </div>
                )}
                {teacher && !answering && (
                    <button
                        className="w-fit text-xs text-brand hover:underline"
                        onClick={() => setAnswering(true)}
                    >
                        {r.reply ? 'Change the answer' : 'Answer'}
                    </button>
                )}
                {!teacher && r.mine && r.status === 'open' && (
                    <button
                        className="w-fit text-xs text-muted-foreground hover:text-status-critical"
                        onClick={() =>
                            confirm('Withdraw this request?') &&
                            router.delete(`/learn/topic-requests/${r.id}`, {
                                preserveScroll: true,
                            })
                        }
                    >
                        Withdraw
                    </button>
                )}
                {answering && (
                    <form
                        className="mt-1 grid gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.post(
                                `/learn/topic-requests/${r.id}/respond`,
                                {
                                    ...data,
                                    course_item_id: data.course_item_id || null,
                                },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => setAnswering(false),
                                },
                            );
                        }}
                    >
                        <div className="grid gap-2 sm:grid-cols-2">
                            <select
                                className={inputClass}
                                value={data.status}
                                onChange={(e) =>
                                    setData({
                                        ...data,
                                        status: e.target
                                            .value as typeof data.status,
                                    })
                                }
                            >
                                <option value="planned">
                                    Planned: I will cover it
                                </option>
                                <option value="covered">
                                    Covered: it is in the course now
                                </option>
                                <option value="declined">
                                    Answered: not adding it (say why)
                                </option>
                                <option value="open">Still open</option>
                            </select>
                            <select
                                className={inputClass}
                                value={data.course_item_id}
                                onChange={(e) =>
                                    setData({
                                        ...data,
                                        course_item_id: e.target.value,
                                    })
                                }
                                aria-label="Material that covers it"
                            >
                                <option value="">No material linked</option>
                                {items.map((i) => (
                                    <option key={i.id} value={i.id}>
                                        {i.title}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <textarea
                            className={textareaClass}
                            placeholder="Your answer to the students who asked"
                            value={data.reply}
                            onChange={(e) =>
                                setData({ ...data, reply: e.target.value })
                            }
                            maxLength={3000}
                        />
                        <div className="flex gap-2">
                            <Button size="sm">
                                Send to {r.votes} student
                                {r.votes === 1 ? '' : 's'}
                            </Button>
                            <Button
                                size="sm"
                                type="button"
                                variant="ghost"
                                onClick={() => setAnswering(false)}
                            >
                                Cancel
                            </Button>
                        </div>
                    </form>
                )}
            </div>
        </article>
    );
}
