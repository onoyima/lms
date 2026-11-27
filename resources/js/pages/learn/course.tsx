import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Bookmark,
    CalendarCheck,
    Check,
    ChevronDown,
    ChevronRight,
    Copy,
    Download,
    Eye,
    EyeOff,
    ImagePlus,
    Lock,
    PlayCircle,
    Plus,
    Search,
    Video,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    Assessments,
    Polls,
    Requests,
    Reviews,
} from '@/components/lms/course-feedback';
import type { Assessment, Feedback } from '@/components/lms/course-feedback';
import {
    fileSize,
    KIND_ICON,
    KIND_LABEL,
    KIND_TONE,
    materialKind,
} from '@/components/lms/material';
import {
    Flash,
    formatDateTime,
    inputClass,
    Status,
    textareaClass,
} from '@/components/platform/ui';
import { RichContent } from '@/components/rich-content';
import { Button } from '@/components/ui/button';
import { usePlatform } from '@/hooks/use-platform';

type Item = {
    id: number;
    type: string;
    title: string;
    visible: boolean;
    release_at: string | null;
    due_at: string | null;
    open: boolean;
    locked_reason: string | null;
    done: boolean;
    file_name: string | null;
    file_mime?: string | null;
    file_size?: number | null;
    url?: string | null;
    requires_completion?: boolean;
    bookmarked?: boolean;
    prerequisite: string | null;
    version: number;
};
type Section = {
    id: number;
    title: string;
    summary: string | null;
    visible: boolean;
    release_at: string | null;
    items: Item[];
};
type Slot = {
    weekday: number;
    starts_at: string;
    ends_at: string;
    kind: string;
    venue: string | null;
    group_label: string | null;
};
const DAYS = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/** Weekly class times; teachers edit them, students are told about changes. */
function Timetable({
    offeringId,
    schedule,
    teacher,
}: {
    offeringId: number;
    schedule: Slot[];
    teacher: boolean;
}) {
    const [editing, setEditing] = useState(false);
    const [slots, setSlots] = useState<Slot[]>(schedule);

    if (!teacher && schedule.length === 0) {
        return null;
    }

    return (
        <div className="surface grid gap-2 p-5 text-sm">
            <div className="flex items-center justify-between">
                <p className="eyebrow">Class times</p>
                {teacher && !editing && (
                    <button
                        className="text-xs text-brand"
                        onClick={() => setEditing(true)}
                    >
                        Edit
                    </button>
                )}
            </div>
            {!editing &&
                schedule.map((x, i) => (
                    <p key={i} className="flex justify-between gap-2">
                        <span>
                            {DAYS[x.weekday]} {x.starts_at}–{x.ends_at} ·{' '}
                            {x.kind}
                            {x.group_label ? ` (${x.group_label})` : ''}
                        </span>
                        <span className="text-muted-foreground">{x.venue}</span>
                    </p>
                ))}
            {!editing && teacher && schedule.length === 0 && (
                <p className="text-muted-foreground">No class times yet.</p>
            )}
            {editing && (
                <div className="grid gap-2">
                    {slots.map((x, i) => (
                        <div key={i} className="grid grid-cols-6 gap-1 text-xs">
                            <select
                                className="rounded border border-input bg-background p-1"
                                value={x.weekday}
                                onChange={(e) =>
                                    setSlots(
                                        slots.map((y, j) =>
                                            j === i
                                                ? {
                                                      ...y,
                                                      weekday: Number(
                                                          e.target.value,
                                                      ),
                                                  }
                                                : y,
                                        ),
                                    )
                                }
                            >
                                {DAYS.slice(1).map((d, k) => (
                                    <option key={d} value={k + 1}>
                                        {d}
                                    </option>
                                ))}
                            </select>
                            <input
                                type="time"
                                className="rounded border border-input bg-background p-1"
                                value={x.starts_at}
                                onChange={(e) =>
                                    setSlots(
                                        slots.map((y, j) =>
                                            j === i
                                                ? {
                                                      ...y,
                                                      starts_at: e.target.value,
                                                  }
                                                : y,
                                        ),
                                    )
                                }
                            />
                            <input
                                type="time"
                                className="rounded border border-input bg-background p-1"
                                value={x.ends_at}
                                onChange={(e) =>
                                    setSlots(
                                        slots.map((y, j) =>
                                            j === i
                                                ? {
                                                      ...y,
                                                      ends_at: e.target.value,
                                                  }
                                                : y,
                                        ),
                                    )
                                }
                            />
                            <select
                                className="rounded border border-input bg-background p-1"
                                value={x.kind}
                                onChange={(e) =>
                                    setSlots(
                                        slots.map((y, j) =>
                                            j === i
                                                ? { ...y, kind: e.target.value }
                                                : y,
                                        ),
                                    )
                                }
                            >
                                {[
                                    'lecture',
                                    'practical',
                                    'tutorial',
                                    'seminar',
                                ].map((k) => (
                                    <option key={k}>{k}</option>
                                ))}
                            </select>
                            <input
                                className="rounded border border-input bg-background p-1"
                                placeholder="Venue"
                                value={x.venue ?? ''}
                                onChange={(e) =>
                                    setSlots(
                                        slots.map((y, j) =>
                                            j === i
                                                ? {
                                                      ...y,
                                                      venue: e.target.value,
                                                  }
                                                : y,
                                        ),
                                    )
                                }
                            />
                            <button
                                className="text-muted-foreground"
                                onClick={() =>
                                    setSlots(slots.filter((_, j) => j !== i))
                                }
                            >
                                Remove
                            </button>
                        </div>
                    ))}
                    <div className="flex gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                setSlots([
                                    ...slots,
                                    {
                                        weekday: 1,
                                        starts_at: '08:00',
                                        ends_at: '10:00',
                                        kind: 'lecture',
                                        venue: '',
                                        group_label: null,
                                    },
                                ])
                            }
                        >
                            Add time
                        </Button>
                        <Button
                            size="sm"
                            onClick={() =>
                                router.put(
                                    `/learn/offerings/${offeringId}/schedule`,
                                    { slots, notify: true } as never,
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setEditing(false),
                                    },
                                )
                            }
                        >
                            Save and tell students
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => {
                                setSlots(schedule);
                                setEditing(false);
                            }}
                        >
                            Cancel
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}

type Props = {
    offering: {
        id: number;
        code: string;
        title: string;
        units: number;
        semester: string;
        outline_html: string | null;
        description: string | null;
        review_status: string;
        staff: { name: string; email: string; role: string }[];
    };
    sections: Section[];
    schedule: Slot[];
    teacher: boolean;
    canGrade: boolean;
    progress: { done: number; total: number; percent: number } | null;
    previous: { id: number; label: string }[];
    announcements: {
        id: number;
        title: string;
        body_html: string;
        publish_at: string;
        urgent: boolean;
    }[];
    rendered_at: number;
};

export const ITEM_TYPES = [
    ['page', 'Page / lecture note'],
    ['file', 'File: PDF, Word, slides, Excel, picture, audio'],
    ['video', 'Video: upload one or paste a YouTube/Vimeo link'],
    ['link', 'Web link'],
    ['live', 'Live class link'],
    ['assignment', 'Assignment'],
    ['quiz', 'Quiz / test'],
    ['forum', 'Discussion forum'],
] as const;

type Tab =
    | 'content'
    | 'assessments'
    | 'announcements'
    | 'polls'
    | 'requests'
    | 'reviews'
    | 'about';
type Filter = 'all' | 'materials' | 'videos' | 'tasks' | 'todo' | 'saved';

const FILTERS: [Filter, string][] = [
    ['all', 'Everything'],
    ['materials', 'Notes & files'],
    ['videos', 'Videos & audio'],
    ['tasks', 'Assessments'],
    ['todo', 'Not done yet'],
    ['saved', 'Saved'],
];

function matches(i: Item, f: Filter, q: string): boolean {
    const k = materialKind(i);

    if (
        q &&
        !i.title.toLowerCase().includes(q.toLowerCase()) &&
        !(i.file_name ?? '').toLowerCase().includes(q.toLowerCase())
    ) {
        return false;
    }

    switch (f) {
        case 'materials':
            return [
                'pdf',
                'word',
                'slides',
                'sheet',
                'image',
                'text',
                'archive',
                'file',
                'page',
                'link',
                'drive',
            ].includes(k);
        case 'videos':
            return ['video', 'youtube', 'vimeo', 'audio', 'live'].includes(k);
        case 'tasks':
            return ['assignment', 'quiz'].includes(k);
        case 'todo':
            return (
                !i.done &&
                i.open &&
                (i.requires_completion || ['assignment', 'quiz'].includes(k))
            );
        case 'saved':
            return !!i.bookmarked;
        default:
            return true;
    }
}

export default function Course({
    offering,
    sections,
    teacher,
    canGrade,
    progress,
    previous,
    announcements,
    rendered_at,
    schedule,
    recordings = [],
    feedback,
    assessments = [],
    students,
}: Props & {
    recordings?: {
        id: number;
        held_at: string;
        topic: string | null;
        published: boolean;
        url: string;
    }[];
    feedback?: Feedback;
    assessments?: Assessment[];
    students?: number | null;
}) {
    // Coming back with the browser's Back button restores an old copy of this page; refresh
    // progress and unlocked items so a just-completed prerequisite shows as done.
    useEffect(() => {
        if (Date.now() - rendered_at > 3000) {
            router.reload({
                only: ['sections', 'progress', 'rendered_at', 'assessments'],
            });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
    const params =
        typeof window !== 'undefined'
            ? new URLSearchParams(window.location.search)
            : new URLSearchParams();
    const [tab, setTabState] = useState<Tab>(
        (params.get('tab') as Tab) || 'content',
    );
    const ask = params.get('ask');
    const setTab = (t: Tab) => {
        setTabState(t);
        const u = new URL(window.location.href);

        if (t === 'content') {
            u.searchParams.delete('tab');
        } else {
            u.searchParams.set('tab', t);
        }

        u.searchParams.delete('ask');
        window.history.replaceState(window.history.state, '', u.toString());
    };
    const hasMeetings = (
        (usePage().props as { modules?: string[] }).modules ?? []
    ).includes('conferencing');
    const [adding, setAdding] = useState<number | null>(null);
    const [editOutline, setEditOutline] = useState(false);
    const [filter, setFilter] = useState<Filter>('all');
    const [q, setQ] = useState('');
    const [collapsed, setCollapsed] = useState<number[]>([]);
    const section = useForm({ title: '', summary: '', release_at: '' });
    const weeks = useForm({ weeks: 13, starts_on: '', release: true });
    const outline = useForm({ outline_html: offering.outline_html ?? '' });
    const fb = feedback ?? {
        reviews: { average: null, count: 0, counts: {}, mine: null, list: [] },
        polls: [],
        requests: [],
    };
    const allItems = sections.flatMap((s) => s.items);
    const handedIn = new Set(
        assessments
            .filter((a) => a.state && a.state !== 'to_do')
            .map((a) => a.id),
    );
    const nextUp = teacher
        ? null
        : allItems.find(
              (i) =>
                  i.open &&
                  !i.done &&
                  !handedIn.has(i.id) &&
                  (i.requires_completion ||
                      ['assignment', 'quiz'].includes(i.type)),
          );
    const openPolls = fb.polls.filter(
        (p) => !p.closed && (teacher || !p.mine),
    ).length;
    const openRequests = fb.requests.filter((r) => r.status === 'open').length;
    const toDo = assessments.filter((a) => a.state === 'to_do').length;
    const toMark = assessments.reduce((n, a) => n + (a.to_mark ?? 0), 0);

    const TABS: [Tab, string, number | string | null][] = [
        ['content', 'Content', null],
        ['assessments', 'Assessments', teacher ? toMark || null : toDo || null],
        ['announcements', 'Announcements', announcements.length || null],
        ...((feedback
            ? [
                  ['polls', 'Polls', openPolls || null],
                  ['requests', 'Requests', teacher ? openRequests || null : null],
                  ['reviews', 'Reviews', fb.reviews.average ? `${fb.reviews.average}★` : null],
              ]
            : []) as [Tab, string, number | string | null][]),
        ['about', 'About', null],
    ];

    const link =
        'flex shrink-0 items-center gap-1.5 rounded-xl border border-border bg-card/80 px-3 py-2 text-sm hover:border-brand/50';

    return (
        <>
            <Head title={offering.code} />
            <div className="flex flex-col gap-5 p-4 sm:p-6 md:gap-6 md:p-10">
                {/* the course at a glance */}
                <header className="surface relative overflow-hidden p-5 sm:p-7">
                    <div className="flex flex-col gap-5 md:flex-row md:items-end md:justify-between">
                        <div className="min-w-0">
                            <p className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                <span className="rounded-md bg-brand-soft px-2 py-0.5 font-mono font-semibold text-brand-ink">
                                    {offering.code}
                                </span>
                                <span>{offering.units} units</span>
                                <span>· {offering.semester}</span>
                            </p>
                            <h1 className="mt-2 text-2xl leading-tight font-semibold text-balance sm:text-3xl">
                                {offering.title}
                            </h1>
                            <p className="mt-1.5 text-sm text-muted-foreground">
                                {offering.staff.map((s) => s.name).join(', ')}
                            </p>
                        </div>
                        {progress && (
                            <div className="w-full shrink-0 md:w-64">
                                <div className="flex items-baseline justify-between text-sm">
                                    <span className="text-muted-foreground">
                                        Your progress
                                    </span>
                                    <span className="text-xl font-semibold tabular-nums">
                                        {progress.percent}%
                                    </span>
                                </div>
                                <div className="mt-2 h-2 overflow-hidden rounded-full bg-muted">
                                    <div
                                        className="h-full rounded-full bg-brand transition-[width]"
                                        style={{
                                            width: `${progress.percent}%`,
                                        }}
                                    />
                                </div>
                                <p className="mt-1.5 text-xs text-muted-foreground">
                                    {progress.done} of {progress.total}{' '}
                                    activities done
                                </p>
                            </div>
                        )}
                        {teacher && (
                            <div className="flex shrink-0 gap-6 text-sm">
                                <div>
                                    <p className="text-2xl font-semibold tabular-nums">
                                        {students ?? 0}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        students
                                    </p>
                                </div>
                                <div>
                                    <p className="text-2xl font-semibold tabular-nums">
                                        {allItems.length}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        items
                                    </p>
                                </div>
                                <div>
                                    <p
                                        className={`text-2xl font-semibold tabular-nums ${toMark ? 'text-status-warning' : ''}`}
                                    >
                                        {toMark}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        to mark
                                    </p>
                                </div>
                            </div>
                        )}
                    </div>
                    <div className="-mx-1 mt-5 flex gap-2 overflow-x-auto px-1 pb-1">
                        {nextUp && (
                            <Link
                                href={`/learn/items/${nextUp.id}`}
                                className={`${link} !border-brand bg-brand text-brand-foreground hover:!bg-brand/90`}
                            >
                                <PlayCircle className="size-4" /> Continue:{' '}
                                <span className="max-w-48 truncate">
                                    {nextUp.title}
                                </span>
                            </Link>
                        )}
                        {hasMeetings && (
                            <Link
                                href={`/meetings?course=${offering.id}`}
                                className={link}
                            >
                                <Video className="size-4" /> Live classes
                            </Link>
                        )}
                        {teacher ? (
                            <>
                                <Link
                                    href={`/learn/courses/${offering.id}/gradebook`}
                                    className={link}
                                >
                                    <BarChart3 className="size-4" /> Gradebook
                                </Link>
                                <Link
                                    href={`/learn/courses/${offering.id}/attendance`}
                                    className={link}
                                >
                                    <CalendarCheck className="size-4" />{' '}
                                    Attendance
                                </Link>
                                <a
                                    href={`/learn/courses/${offering.id}/class-list`}
                                    className={link}
                                >
                                    <Download className="size-4" /> Class list
                                </a>
                            </>
                        ) : (
                            <Link href="/learn/grades" className={link}>
                                <BarChart3 className="size-4" /> My grades
                            </Link>
                        )}
                    </div>
                </header>

                <Flash />

                {/* tabs: scroll sideways on a phone */}
                <div
                    role="tablist"
                    aria-label="Course"
                    className="-mx-4 flex gap-1 overflow-x-auto border-b border-border px-4 sm:mx-0 sm:px-0"
                >
                    {TABS.map(([t, label, badge]) => (
                        <button
                            key={t}
                            role="tab"
                            aria-selected={tab === t}
                            onClick={() => setTab(t)}
                            className={`-mb-px flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium transition ${tab === t ? 'border-brand text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'}`}
                        >
                            {label}
                            {badge !== null && (
                                <span className="rounded-full bg-brand-soft px-1.5 text-[11px] font-semibold text-brand-ink tabular-nums">
                                    {badge}
                                </span>
                            )}
                        </button>
                    ))}
                </div>

                {tab === 'content' && (
                    <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_300px]">
                        <main className="grid min-w-0 content-start gap-4">
                            {allItems.length > 0 && (
                                <div className="grid gap-3">
                                    <label className="relative">
                                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                        <input
                                            className={`${inputClass} !h-11 pl-9`}
                                            placeholder="Find in this course"
                                            value={q}
                                            onChange={(e) =>
                                                setQ(e.target.value)
                                            }
                                            aria-label="Find in this course"
                                        />
                                    </label>
                                    <div className="-mx-4 flex gap-2 overflow-x-auto px-4 sm:mx-0 sm:flex-wrap sm:px-0">
                                        {FILTERS.filter(
                                            ([f]) =>
                                                !teacher ||
                                                !['todo', 'saved'].includes(f),
                                        ).map(([f, label]) => (
                                            <button
                                                key={f}
                                                onClick={() => setFilter(f)}
                                                className={`shrink-0 rounded-full border px-3 py-1.5 text-sm ${filter === f ? 'border-brand bg-brand text-brand-foreground' : 'border-border bg-card hover:border-brand/50'}`}
                                            >
                                                {label}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            )}
                            {sections.length === 0 && (
                                <div className="surface p-8 text-sm text-muted-foreground">
                                    {teacher
                                        ? 'Add the first week or topic to start building this course.'
                                        : 'Your lecturer has not added any material yet.'}
                                </div>
                            )}
                            {teacher && sections.length === 0 && (
                                <form
                                    className="surface flex flex-wrap items-end gap-3 p-5 text-sm"
                                    aria-label="Set up weekly sections"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        weeks.post(
                                            `/learn/courses/${offering.id}/weeks`,
                                            { preserveScroll: true },
                                        );
                                    }}
                                >
                                    <div className="grid gap-1">
                                        <span className="font-medium">
                                            Set up the course week by week
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            Adds Week 1, Week 2, … — each opens
                                            to students on its week. Existing
                                            weeks are kept.
                                        </span>
                                    </div>
                                    <label className="grid gap-1">
                                        <span className="text-xs text-muted-foreground">
                                            Weeks
                                        </span>
                                        <input
                                            type="number"
                                            min={1}
                                            max={30}
                                            className={`${inputClass} !w-24`}
                                            value={weeks.data.weeks}
                                            onChange={(e) =>
                                                weeks.setData(
                                                    'weeks',
                                                    Number(e.target.value),
                                                )
                                            }
                                        />
                                    </label>
                                    <label className="grid gap-1">
                                        <span className="text-xs text-muted-foreground">
                                            First week starts (default: semester
                                            start)
                                        </span>
                                        <input
                                            type="date"
                                            className={inputClass}
                                            value={weeks.data.starts_on}
                                            onChange={(e) =>
                                                weeks.setData(
                                                    'starts_on',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </label>
                                    <label className="flex items-center gap-2 pb-2">
                                        <input
                                            type="checkbox"
                                            checked={weeks.data.release}
                                            onChange={(e) =>
                                                weeks.setData(
                                                    'release',
                                                    e.target.checked,
                                                )
                                            }
                                        />{' '}
                                        Open each week on its date
                                    </label>
                                    <Button disabled={weeks.processing}>
                                        Create weeks
                                    </Button>
                                </form>
                            )}
                            {sections.map((s, si) => {
                                const items = s.items.filter((i) =>
                                    matches(i, filter, q),
                                );
                                const done = s.items.filter(
                                    (i) => i.done,
                                ).length;
                                const required = s.items.filter(
                                    (i) =>
                                        i.requires_completion ||
                                        ['assignment', 'quiz'].includes(i.type),
                                ).length;
                                const isCollapsed = collapsed.includes(s.id);

                                if (
                                    (filter !== 'all' || q) &&
                                    items.length === 0
                                ) {
                                    return null;
                                }

                                return (
                                    <section
                                        key={s.id}
                                        className={`surface overflow-hidden ${!s.visible ? 'opacity-60' : ''}`}
                                        aria-labelledby={`sec-${s.id}`}
                                    >
                                        <div className="flex items-start gap-3 p-4 sm:p-5">
                                            <button
                                                type="button"
                                                className="flex min-w-0 flex-1 items-start gap-3 text-left"
                                                aria-expanded={!isCollapsed}
                                                onClick={() =>
                                                    setCollapsed(
                                                        isCollapsed
                                                            ? collapsed.filter(
                                                                  (x) =>
                                                                      x !==
                                                                      s.id,
                                                              )
                                                            : [
                                                                  ...collapsed,
                                                                  s.id,
                                                              ],
                                                    )
                                                }
                                            >
                                                <span className="mt-0.5 grid size-8 shrink-0 place-items-center rounded-lg bg-muted text-xs font-semibold tabular-nums">
                                                    {si + 1}
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span
                                                        id={`sec-${s.id}`}
                                                        className="block font-semibold"
                                                    >
                                                        {s.title}
                                                    </span>
                                                    {s.summary && (
                                                        <span className="mt-0.5 block text-sm text-muted-foreground">
                                                            {s.summary}
                                                        </span>
                                                    )}
                                                    <span className="mt-1 block text-xs text-muted-foreground">
                                                        {s.items.length} item
                                                        {s.items.length === 1
                                                            ? ''
                                                            : 's'}
                                                        {!teacher &&
                                                            required > 0 &&
                                                            ` · ${done} done`}
                                                        {teacher &&
                                                            s.release_at &&
                                                            new Date(
                                                                s.release_at,
                                                            ) > new Date() && (
                                                                <span className="text-status-warning">
                                                                    {' '}
                                                                    · students
                                                                    see it from{' '}
                                                                    {formatDateTime(
                                                                        s.release_at,
                                                                    )}
                                                                </span>
                                                            )}
                                                    </span>
                                                </span>
                                                <ChevronDown
                                                    className={`mt-1 size-4 shrink-0 text-muted-foreground transition ${isCollapsed ? '-rotate-90' : ''}`}
                                                />
                                            </button>
                                            {teacher && (
                                                <div className="flex shrink-0 gap-1">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            router.put(
                                                                `/learn/courses/${offering.id}/sections/${s.id}`,
                                                                {
                                                                    visible:
                                                                        !s.visible,
                                                                },
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                        aria-label={
                                                            s.visible
                                                                ? 'Hide section'
                                                                : 'Show section'
                                                        }
                                                    >
                                                        {s.visible ? (
                                                            <Eye className="size-4" />
                                                        ) : (
                                                            <EyeOff className="size-4" />
                                                        )}
                                                    </Button>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            setAdding(
                                                                adding === s.id
                                                                    ? null
                                                                    : s.id,
                                                            )
                                                        }
                                                    >
                                                        <Plus className="size-4" />{' '}
                                                        <span className="hidden sm:inline">
                                                            Add
                                                        </span>
                                                    </Button>
                                                </div>
                                            )}
                                        </div>
                                        {adding === s.id && (
                                            <div className="px-4 pb-4 sm:px-5">
                                                <AddItem
                                                    offeringId={offering.id}
                                                    sectionId={s.id}
                                                    onDone={() =>
                                                        setAdding(null)
                                                    }
                                                />
                                            </div>
                                        )}
                                        {!isCollapsed && items.length > 0 && (
                                            <ul className="grid border-t border-border">
                                                {items.map((i) => (
                                                    <ItemRow
                                                        key={i.id}
                                                        i={i}
                                                        teacher={teacher}
                                                    />
                                                ))}
                                            </ul>
                                        )}
                                        {!isCollapsed &&
                                            s.items.length === 0 &&
                                            teacher && (
                                                <p className="border-t border-border px-5 py-4 text-sm text-muted-foreground">
                                                    Nothing here yet. Use Add.
                                                </p>
                                            )}
                                    </section>
                                );
                            })}

                            {teacher && (
                                <form
                                    className="surface grid gap-3 p-5 md:grid-cols-[1fr_1fr_200px_auto]"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        section.post(
                                            `/learn/courses/${offering.id}/sections`,
                                            {
                                                preserveScroll: true,
                                                onSuccess: () =>
                                                    section.reset(),
                                            },
                                        );
                                    }}
                                >
                                    <input
                                        className={inputClass}
                                        placeholder="New week or topic, e.g. Week 3: Stoichiometry"
                                        value={section.data.title}
                                        onChange={(e) =>
                                            section.setData(
                                                'title',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                    <input
                                        className={inputClass}
                                        placeholder="Short summary (optional)"
                                        value={section.data.summary}
                                        onChange={(e) =>
                                            section.setData(
                                                'summary',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <input
                                        type="datetime-local"
                                        className={inputClass}
                                        value={section.data.release_at}
                                        onChange={(e) =>
                                            section.setData(
                                                'release_at',
                                                e.target.value,
                                            )
                                        }
                                        aria-label="Release on"
                                    />
                                    <Button disabled={section.processing}>
                                        Add section
                                    </Button>
                                </form>
                            )}
                        </main>

                        <aside className="grid content-start gap-4">
                            {!teacher &&
                                assessments.some(
                                    (a) => a.state === 'to_do' && a.due_at,
                                ) && (
                                    <div className="surface grid gap-2 p-5">
                                        <p className="eyebrow">Due soon</p>
                                        {assessments
                                            .filter(
                                                (a) =>
                                                    a.state === 'to_do' &&
                                                    a.due_at,
                                            )
                                            .slice(0, 4)
                                            .map((a) => (
                                                <Link
                                                    key={a.id}
                                                    href={`/learn/items/${a.id}`}
                                                    className="rounded-lg px-2 py-1.5 text-sm hover:bg-muted"
                                                >
                                                    {a.title}
                                                    <span className="block text-xs text-muted-foreground">
                                                        due{' '}
                                                        {formatDateTime(
                                                            a.due_at,
                                                        )}
                                                    </span>
                                                </Link>
                                            ))}
                                    </div>
                                )}
                            <Timetable
                                offeringId={offering.id}
                                schedule={schedule ?? []}
                                teacher={teacher}
                            />
                            {recordings.length > 0 && (
                                <section
                                    className="surface grid gap-2 p-5"
                                    aria-label="Recorded lectures"
                                >
                                    <p className="eyebrow">Recorded lectures</p>
                                    {recordings.map((r) => (
                                        <a
                                            key={r.id}
                                            href={r.url}
                                            target="_blank"
                                            rel="noreferrer noopener"
                                            className="rounded-lg px-2 py-1.5 text-sm hover:bg-muted"
                                        >
                                            {r.topic ?? 'Class'}
                                            <span className="block text-xs text-muted-foreground">
                                                {formatDateTime(r.held_at)}
                                                {teacher && !r.published
                                                    ? ' · not published'
                                                    : ''}
                                            </span>
                                        </a>
                                    ))}
                                </section>
                            )}
                            {announcements[0] && (
                                <button
                                    type="button"
                                    onClick={() => setTab('announcements')}
                                    className={`surface grid gap-1 p-5 text-left ${announcements[0].urgent ? '!border-status-critical/40' : ''}`}
                                >
                                    <p className="eyebrow">
                                        Latest announcement
                                    </p>
                                    <p className="text-sm font-medium">
                                        {announcements[0].title}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {formatDateTime(
                                            announcements[0].publish_at,
                                        )}
                                    </p>
                                </button>
                            )}
                        </aside>
                    </div>
                )}

                {tab === 'assessments' && (
                    <Assessments list={assessments} teacher={teacher} />
                )}

                {tab === 'announcements' && (
                    <div className="grid max-w-3xl gap-3">
                        {announcements.length === 0 && (
                            <p className="surface p-6 text-sm text-muted-foreground">
                                No announcements for this course.
                            </p>
                        )}
                        {announcements.map((a) => (
                            <article
                                key={a.id}
                                className={`surface grid gap-2 p-5 ${a.urgent ? '!border-status-critical/40' : ''}`}
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <h3 className="font-semibold">{a.title}</h3>
                                    <span className="text-xs text-muted-foreground">
                                        {formatDateTime(a.publish_at)}
                                    </span>
                                </div>
                                {a.urgent && (
                                    <span className="w-fit rounded-full bg-status-critical/10 px-2 py-0.5 text-xs text-status-critical">
                                        Urgent
                                    </span>
                                )}
                                <RichContent
                                    html={a.body_html}
                                    className="lesson text-sm"
                                />
                            </article>
                        ))}
                        {teacher && (
                            <Link
                                href="/learn/announcements"
                                className="w-fit text-sm text-brand hover:underline"
                            >
                                Post an announcement
                            </Link>
                        )}
                    </div>
                )}

                {tab === 'polls' && (
                    <Polls
                        offeringId={offering.id}
                        polls={fb.polls}
                        teacher={teacher}
                    />
                )}
                {tab === 'requests' && (
                    <Requests
                        offeringId={offering.id}
                        requests={fb.requests}
                        teacher={teacher}
                        ask={ask}
                        items={allItems.map((i) => ({
                            id: i.id,
                            title: i.title,
                        }))}
                    />
                )}
                {tab === 'reviews' && (
                    <Reviews
                        offeringId={offering.id}
                        reviews={fb.reviews}
                        teacher={teacher}
                    />
                )}

                {tab === 'about' && (
                    <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
                        <div className="surface grid content-start gap-3 p-5 sm:p-7">
                            <div className="flex items-center justify-between">
                                <h2 className="text-lg font-semibold">
                                    Course outline
                                </h2>
                                {teacher && (
                                    <button
                                        className="text-sm text-brand"
                                        onClick={() =>
                                            setEditOutline(!editOutline)
                                        }
                                    >
                                        {editOutline ? 'Cancel' : 'Edit'}
                                    </button>
                                )}
                            </div>
                            {editOutline ? (
                                <form
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        outline.put(
                                            `/learn/courses/${offering.id}/outline`,
                                            {
                                                onSuccess: () =>
                                                    setEditOutline(false),
                                            },
                                        );
                                    }}
                                    className="grid gap-2"
                                >
                                    <textarea
                                        className={`${textareaClass} min-h-60`}
                                        value={outline.data.outline_html}
                                        onChange={(e) =>
                                            outline.setData(
                                                'outline_html',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Objectives, reading list, assessment plan… (HTML and \( maths \) allowed)"
                                    />
                                    <Button size="sm" className="w-fit">
                                        Save outline
                                    </Button>
                                </form>
                            ) : offering.outline_html ? (
                                <RichContent
                                    html={offering.outline_html}
                                    className="lesson"
                                />
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    {offering.description ?? 'No outline yet.'}
                                </p>
                            )}
                        </div>
                        <aside className="grid content-start gap-4">
                            <div className="surface grid gap-3 p-5 text-sm">
                                <p className="eyebrow">Lecturers</p>
                                {offering.staff.map((s) => (
                                    <p key={s.email}>
                                        {s.name}{' '}
                                        <span className="text-xs text-muted-foreground">
                                            ({s.role})
                                        </span>
                                        <a
                                            href={`mailto:${s.email}`}
                                            className="block text-xs text-muted-foreground hover:text-brand"
                                        >
                                            {s.email}
                                        </a>
                                    </p>
                                ))}
                            </div>
                            {teacher && (
                                <div className="surface grid gap-3 p-5 text-sm">
                                    <p className="eyebrow">Academic review</p>
                                    <p>
                                        Status:{' '}
                                        <Status
                                            value={offering.review_status}
                                        />
                                    </p>
                                    {offering.review_status === 'draft' && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    `/learn/courses/${offering.id}/review`,
                                                    { action: 'submit' },
                                                )
                                            }
                                        >
                                            Submit for HOD review
                                        </Button>
                                    )}
                                    {offering.review_status === 'submitted' &&
                                        !canGrade && (
                                            <div className="flex gap-2">
                                                <Button
                                                    size="sm"
                                                    onClick={() =>
                                                        router.post(
                                                            `/learn/courses/${offering.id}/review`,
                                                            {
                                                                action: 'approve',
                                                            },
                                                        )
                                                    }
                                                >
                                                    Approve
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        router.post(
                                                            `/learn/courses/${offering.id}/review`,
                                                            {
                                                                action: 'return',
                                                            },
                                                        )
                                                    }
                                                >
                                                    Return
                                                </Button>
                                            </div>
                                        )}
                                    {previous.length > 0 && (
                                        <form
                                            className="mt-2 grid gap-2"
                                            onSubmit={(e) => {
                                                e.preventDefault();
                                                const from = new FormData(
                                                    e.currentTarget,
                                                ).get('from');

                                                if (
                                                    confirm(
                                                        'Copy all sections, materials, assignments and quizzes from that semester into this course?',
                                                    )
                                                ) {
                                                    router.post(
                                                        `/learn/courses/${offering.id}/copy`,
                                                        { from: Number(from) },
                                                    );
                                                }
                                            }}
                                        >
                                            <p className="eyebrow">
                                                Reuse a previous semester
                                            </p>
                                            <select
                                                name="from"
                                                className={inputClass}
                                            >
                                                {previous.map((p) => (
                                                    <option
                                                        key={p.id}
                                                        value={p.id}
                                                    >
                                                        {p.label}
                                                    </option>
                                                ))}
                                            </select>
                                            <Button size="sm" variant="outline">
                                                <Copy className="size-4" /> Copy
                                                content
                                            </Button>
                                        </form>
                                    )}
                                </div>
                            )}
                        </aside>
                    </div>
                )}
            </div>
        </>
    );
}

/** One row of a week: what it is (icon and label), its size or due date, and where the student stands. */
function ItemRow({ i, teacher }: { i: Item; teacher: boolean }) {
    const kind = materialKind(i);
    const Icon = KIND_ICON[kind];
    const meta = [
        KIND_LABEL[kind],
        i.file_size ? fileSize(i.file_size) : null,
        i.due_at ? `due ${formatDateTime(i.due_at)}` : null,
        i.locked_reason,
        teacher && i.prerequisite ? `after "${i.prerequisite}"` : null,
        teacher && i.release_at && new Date(i.release_at) > new Date()
            ? `opens ${formatDateTime(i.release_at)}`
            : null,
    ].filter(Boolean);
    const body = (
        <>
            <span
                className={`grid size-10 shrink-0 place-items-center rounded-xl ${i.open ? KIND_TONE[kind] : 'bg-muted text-muted-foreground'}`}
            >
                {i.open ? (
                    <Icon className="size-5" />
                ) : (
                    <Lock className="size-4" />
                )}
            </span>
            <span className="min-w-0 flex-1">
                <span className="block font-medium [overflow-wrap:anywhere]">
                    {i.title}
                </span>
                <span className="block text-xs text-muted-foreground">
                    {meta.join(' · ')}
                </span>
            </span>
            {teacher && !i.visible && <Status value="hidden" />}
            {!teacher && i.bookmarked && (
                <Bookmark
                    className="size-4 shrink-0 fill-current text-brand"
                    aria-label="Saved"
                />
            )}
            {!teacher && i.done && (
                <span
                    className="grid size-6 shrink-0 place-items-center rounded-full bg-status-good text-white"
                    aria-label="Done"
                >
                    <Check className="size-3.5" />
                </span>
            )}
            {i.open && !i.done && (
                <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
            )}
        </>
    );

    return (
        <li className="border-b border-border last:border-b-0">
            {i.open ? (
                <Link
                    href={`/learn/items/${i.id}`}
                    className="flex items-center gap-3 px-4 py-3 transition hover:bg-muted/60 sm:px-5"
                >
                    {body}
                </Link>
            ) : (
                <div className="flex items-center gap-3 px-4 py-3 text-muted-foreground sm:px-5">
                    {body}
                </div>
            )}
        </li>
    );
}

type Settings = Record<string, any>;

/** Put a picture or diagram into a page: uploaded to the course (private to it) and added to the text. */
function InsertPicture({
    offeringId,
    onInsert,
}: {
    offeringId: number;
    onInsert: (html: string) => void;
}) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    return (
        <div className="flex flex-wrap items-center gap-2 text-xs">
            <label
                className={`inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-border bg-card px-3 py-1.5 font-medium hover:border-brand/50 ${busy ? 'opacity-60' : ''}`}
            >
                <ImagePlus className="size-4" />{' '}
                {busy ? 'Adding the picture…' : 'Insert a picture or diagram'}
                <input
                    type="file"
                    className="sr-only"
                    accept="image/png,image/jpeg,image/gif,image/webp"
                    disabled={busy}
                    onChange={async (e) => {
                        const f = e.target.files?.[0];
                        e.target.value = '';

                        if (!f) {
                            return;
                        }

                        setBusy(true);
                        setError(null);

                        try {
                            const body = new FormData();
                            body.append('file', f);
                            const token = decodeURIComponent(
                                document.cookie.match(
                                    /XSRF-TOKEN=([^;]+)/,
                                )?.[1] ?? '',
                            );
                            const r = await fetch(
                                `/learn/courses/${offeringId}/media`,
                                {
                                    method: 'POST',
                                    body,
                                    credentials: 'same-origin',
                                    headers: {
                                        Accept: 'application/json',
                                        'X-XSRF-TOKEN': token,
                                    },
                                },
                            );
                            const d = await r.json();

                            if (!r.ok) {
                                throw new Error(
                                    d?.errors?.file?.[0] ??
                                        d?.message ??
                                        'The picture could not be added.',
                                );
                            }

                            onInsert(
                                `\n<p><img src="${d.url}" alt="${f.name.replace(/[^\w .-]/g, '').replace(/\.[a-z]+$/i, '')}"></p>\n`,
                            );
                        } catch (err) {
                            setError((err as Error).message);
                        } finally {
                            setBusy(false);
                        }
                    }}
                />
            </label>
            {error && <span className="text-status-critical">{error}</span>}
        </div>
    );
}

function AddItem({
    offeringId,
    sectionId,
    onDone,
}: {
    offeringId: number;
    sectionId: number;
    onDone: () => void;
}) {
    const quizzes = usePlatform().hasModule('question_bank');
    const form = useForm<{
        course_section_id: number;
        type: string;
        title: string;
        body_html: string;
        url: string;
        file: File | null;
        release_at: string;
        due_at: string;
        cutoff_at: string;
        settings: Settings;
    }>({
        course_section_id: sectionId,
        type: 'page',
        title: '',
        body_html: '',
        url: '',
        file: null,
        release_at: '',
        due_at: '',
        cutoff_at: '',
        settings: {},
    });
    const t = form.data.type;
    const setSetting = (k: string, v: unknown) =>
        form.setData('settings', { ...form.data.settings, [k]: v });

    return (
        <form
            className="mt-4 grid gap-3 rounded-lg bg-muted/50 p-4"
            onSubmit={(e) => {
                e.preventDefault();
                form.transform(
                    (d) =>
                        Object.fromEntries(
                            Object.entries(d).filter(
                                ([, v]) => v !== '' && v !== null,
                            ),
                        ) as typeof d,
                );
                form.post(`/learn/courses/${offeringId}/items`, {
                    forceFormData: true,
                    onSuccess: onDone,
                });
            }}
        >
            <div className="grid gap-3 md:grid-cols-[220px_1fr]">
                <select
                    className={inputClass}
                    value={t}
                    onChange={(e) => form.setData('type', e.target.value)}
                >
                    {ITEM_TYPES.filter(([k]) => k !== 'quiz' || quizzes).map(([k, l]) => (
                        <option key={k} value={k}>
                            {l}
                        </option>
                    ))}
                </select>
                <input
                    className={inputClass}
                    placeholder="Title"
                    value={form.data.title}
                    onChange={(e) => form.setData('title', e.target.value)}
                    required
                />
            </div>
            {['page', 'assignment', 'forum', 'quiz', 'live', 'video'].includes(
                t,
            ) && (
                <textarea
                    className={textareaClass}
                    placeholder={
                        t === 'assignment'
                            ? 'Instructions for students'
                            : 'Text (HTML, \\( maths \\) and \\ce{chemistry} allowed)'
                    }
                    value={form.data.body_html}
                    onChange={(e) => form.setData('body_html', e.target.value)}
                />
            )}
            {['link', 'video', 'live'].includes(t) && (
                <input
                    className={inputClass}
                    placeholder="https://…"
                    value={form.data.url}
                    onChange={(e) => form.setData('url', e.target.value)}
                    type="url"
                />
            )}
            {t === 'page' && (
                <InsertPicture
                    offeringId={offeringId}
                    onInsert={(html) =>
                        form.setData(
                            'body_html',
                            `${form.data.body_html}${html}`,
                        )
                    }
                />
            )}
            {['file', 'assignment', 'page', 'video'].includes(t) && (
                <label className="grid gap-1 text-xs text-muted-foreground">
                    {t === 'video'
                        ? 'Or upload the video (MP4 plays everywhere)'
                        : t === 'file'
                          ? 'The file'
                          : 'Attach a file (optional)'}
                    <input
                        type="file"
                        className="text-sm text-foreground"
                        accept={
                            t === 'video'
                                ? 'video/mp4,video/webm,video/quicktime,.mp4,.webm,.mov'
                                : '.pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.csv,.txt,.rtf,.odt,.odp,.zip,.png,.jpg,.jpeg,.gif,.webp,.mp3,.m4a,.wav,.ogg,.mp4,.webm,.mov'
                        }
                        onChange={(e) =>
                            form.setData('file', e.target.files?.[0] ?? null)
                        }
                    />
                </label>
            )}
            {form.progress && (
                <progress
                    className="h-1.5 w-full overflow-hidden rounded-full"
                    value={form.progress.percentage}
                    max={100}
                    aria-label="Uploading"
                />
            )}
            <div className="grid gap-3 md:grid-cols-3">
                <label className="grid gap-1 text-xs">
                    Visible to students from
                    <input
                        type="datetime-local"
                        className={inputClass}
                        value={form.data.release_at}
                        onChange={(e) =>
                            form.setData('release_at', e.target.value)
                        }
                    />
                </label>
                {['assignment', 'quiz'].includes(t) && (
                    <label className="grid gap-1 text-xs">
                        Due
                        <input
                            type="datetime-local"
                            className={inputClass}
                            value={form.data.due_at}
                            onChange={(e) =>
                                form.setData('due_at', e.target.value)
                            }
                        />
                    </label>
                )}
                {t === 'assignment' && (
                    <label className="grid gap-1 text-xs">
                        No submissions after
                        <input
                            type="datetime-local"
                            className={inputClass}
                            value={form.data.cutoff_at}
                            onChange={(e) =>
                                form.setData('cutoff_at', e.target.value)
                            }
                        />
                    </label>
                )}
            </div>
            {t === 'assignment' && (
                <div className="grid gap-3 text-sm md:grid-cols-4">
                    <label className="grid gap-1 text-xs">
                        Out of
                        <input
                            type="number"
                            min={1}
                            className={inputClass}
                            defaultValue={100}
                            onChange={(e) =>
                                setSetting('max_score', Number(e.target.value))
                            }
                        />
                    </label>
                    <label className="grid gap-1 text-xs">
                        Late penalty % per day
                        <input
                            type="number"
                            min={0}
                            max={100}
                            className={inputClass}
                            defaultValue={10}
                            onChange={(e) =>
                                setSetting(
                                    'late_penalty_pct',
                                    Number(e.target.value),
                                )
                            }
                        />
                    </label>
                    <label className="flex items-center gap-2 text-xs">
                        <input
                            type="checkbox"
                            onChange={(e) =>
                                setSetting('group', e.target.checked)
                            }
                        />{' '}
                        Group work
                    </label>
                    <label className="flex items-center gap-2 text-xs">
                        <input
                            type="checkbox"
                            onChange={(e) =>
                                setSetting('anonymous', e.target.checked)
                            }
                        />{' '}
                        Anonymous marking
                    </label>
                </div>
            )}
            {t === 'quiz' && (
                <div className="grid gap-3 text-sm md:grid-cols-3">
                    <label className="grid gap-1 text-xs">
                        Minutes
                        <input
                            type="number"
                            min={1}
                            className={inputClass}
                            defaultValue={30}
                            onChange={(e) =>
                                setSetting('duration', Number(e.target.value))
                            }
                        />
                    </label>
                    <label className="grid gap-1 text-xs">
                        Attempts
                        <input
                            type="number"
                            min={1}
                            className={inputClass}
                            defaultValue={1}
                            onChange={(e) =>
                                setSetting('attempts', Number(e.target.value))
                            }
                        />
                    </label>
                    <label className="grid gap-1 text-xs">
                        Marks
                        <input
                            type="number"
                            min={1}
                            className={inputClass}
                            defaultValue={20}
                            onChange={(e) =>
                                setSetting('max_score', Number(e.target.value))
                            }
                        />
                    </label>
                </div>
            )}
            {Object.values(form.errors).length > 0 && (
                <p className="text-sm text-status-critical">
                    {Object.values(form.errors).join(' ')}
                </p>
            )}
            <div className="flex gap-2">
                <Button disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Add'}
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}
