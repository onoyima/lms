import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    BarChart3,
    CalendarCheck,
    Check,
    ClipboardList,
    Copy,
    Download,
    Eye,
    EyeOff,
    FileText,
    LinkIcon,
    Lock,
    MessagesSquare,
    PenLine,
    Plus,
    Video,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    Flash,
    formatDateTime,
    inputClass,
    PageHeader,
    Status,
    textareaClass,
} from '@/components/platform/ui';
import { RichContent } from '@/components/rich-content';
import { Button } from '@/components/ui/button';

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
type Slot = { weekday: number; starts_at: string; ends_at: string; kind: string; venue: string | null; group_label: string | null };
const DAYS = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/** Weekly class times; teachers edit them, students are told about changes. */
function Timetable({ offeringId, schedule, teacher }: { offeringId: number; schedule: Slot[]; teacher: boolean }) {
    const [editing, setEditing] = useState(false);
    const [slots, setSlots] = useState<Slot[]>(schedule);

    if (!teacher && schedule.length === 0) {
        return null;
    }

    return (
        <div className="surface grid gap-2 p-5 text-sm">
            <div className="flex items-center justify-between">
                <p className="eyebrow">Class times</p>
                {teacher && !editing && <button className="text-xs text-brand" onClick={() => setEditing(true)}>Edit</button>}
            </div>
            {!editing && schedule.map((x, i) => (
                <p key={i} className="flex justify-between gap-2">
                    <span>{DAYS[x.weekday]} {x.starts_at}–{x.ends_at} · {x.kind}{x.group_label ? ` (${x.group_label})` : ''}</span>
                    <span className="text-muted-foreground">{x.venue}</span>
                </p>
            ))}
            {!editing && teacher && schedule.length === 0 && <p className="text-muted-foreground">No class times yet.</p>}
            {editing && (
                <div className="grid gap-2">
                    {slots.map((x, i) => (
                        <div key={i} className="grid grid-cols-6 gap-1 text-xs">
                            <select className="rounded border border-input bg-background p-1" value={x.weekday} onChange={(e) => setSlots(slots.map((y, j) => (j === i ? { ...y, weekday: Number(e.target.value) } : y)))}>
                                {DAYS.slice(1).map((d, k) => <option key={d} value={k + 1}>{d}</option>)}
                            </select>
                            <input type="time" className="rounded border border-input bg-background p-1" value={x.starts_at} onChange={(e) => setSlots(slots.map((y, j) => (j === i ? { ...y, starts_at: e.target.value } : y)))} />
                            <input type="time" className="rounded border border-input bg-background p-1" value={x.ends_at} onChange={(e) => setSlots(slots.map((y, j) => (j === i ? { ...y, ends_at: e.target.value } : y)))} />
                            <select className="rounded border border-input bg-background p-1" value={x.kind} onChange={(e) => setSlots(slots.map((y, j) => (j === i ? { ...y, kind: e.target.value } : y)))}>
                                {['lecture', 'practical', 'tutorial', 'seminar'].map((k) => <option key={k}>{k}</option>)}
                            </select>
                            <input className="rounded border border-input bg-background p-1" placeholder="Venue" value={x.venue ?? ''} onChange={(e) => setSlots(slots.map((y, j) => (j === i ? { ...y, venue: e.target.value } : y)))} />
                            <button className="text-muted-foreground" onClick={() => setSlots(slots.filter((_, j) => j !== i))}>Remove</button>
                        </div>
                    ))}
                    <div className="flex gap-2">
                        <Button size="sm" variant="outline" onClick={() => setSlots([...slots, { weekday: 1, starts_at: '08:00', ends_at: '10:00', kind: 'lecture', venue: '', group_label: null }])}>Add time</Button>
                        <Button size="sm" onClick={() => router.put(`/learn/offerings/${offeringId}/schedule`, { slots, notify: true } as never, { preserveScroll: true, onSuccess: () => setEditing(false) })}>Save and tell students</Button>
                        <Button size="sm" variant="ghost" onClick={() => {
 setSlots(schedule); setEditing(false); 
}}>Cancel</Button>
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

export const itemIcon: Record<string, typeof FileText> = {
    page: FileText,
    file: Download,
    link: LinkIcon,
    video: Video,
    live: Video,
    assignment: PenLine,
    quiz: ClipboardList,
    forum: MessagesSquare,
};
export const ITEM_TYPES = [
    ['page', 'Page / lecture note'],
    ['file', 'File (PDF, Word, slides, audio, video)'],
    ['link', 'Web link'],
    ['video', 'Recorded lecture (link)'],
    ['live', 'Live class link'],
    ['assignment', 'Assignment'],
    ['quiz', 'Quiz / test'],
    ['forum', 'Discussion forum'],
] as const;

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
}: Props & { recordings?: { id: number; held_at: string; topic: string | null; published: boolean; url: string }[] }) {
    // Coming back with the browser's Back button restores an old copy of this page; refresh
    // progress and unlocked items so a just-completed prerequisite shows as done.
    useEffect(() => {
        if (Date.now() - rendered_at > 3000) {
            router.reload({ only: ['sections', 'progress', 'rendered_at'] });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
    const hasMeetings = ((usePage().props as { modules?: string[] }).modules ?? []).includes('conferencing');
    const [adding, setAdding] = useState<number | null>(null);
    const [editOutline, setEditOutline] = useState(false);
    const section = useForm({ title: '', summary: '', release_at: '' });
    const weeks = useForm({ weeks: 13, starts_on: '', release: true });
    const outline = useForm({ outline_html: offering.outline_html ?? '' });

    return (
        <>
            <Head title={offering.code} />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader
                    eyebrow={`${offering.code} · ${offering.units} units · ${offering.semester}`}
                    title={offering.title}
                    lead={offering.staff
                        .map((s) => `${s.name} (${s.role})`)
                        .join(' · ')}
                    actions={
                        teacher ? (
                            <>
                                {hasMeetings && (
                                    <Link href={`/meetings?course=${offering.id}`} className="flex items-center gap-1 rounded-md border border-border px-3 py-2 text-sm">
                                        <Video className="size-4" /> Live classes
                                    </Link>
                                )}
                                <Link
                                    href={`/learn/courses/${offering.id}/gradebook`}
                                    className="flex items-center gap-1 rounded-xl border border-border px-3 py-2 text-sm"
                                >
                                    <BarChart3 className="size-4" /> Gradebook
                                </Link>
                                <Link
                                    href={`/learn/courses/${offering.id}/attendance`}
                                    className="flex items-center gap-1 rounded-xl border border-border px-3 py-2 text-sm"
                                >
                                    <CalendarCheck className="size-4" />{' '}
                                    Attendance
                                </Link>
                                <a
                                    href={`/learn/courses/${offering.id}/class-list`}
                                    className="flex items-center gap-1 rounded-xl border border-border px-3 py-2 text-sm"
                                >
                                    <Download className="size-4" /> Class list
                                </a>
                            </>
                        ) : hasMeetings ? (
                            <Link href={`/meetings?course=${offering.id}`} className="flex items-center gap-1 rounded-md border border-border px-3 py-2 text-sm">
                                <Video className="size-4" /> Live classes
                            </Link>
                        ) : undefined
                    }
                />
                <Flash />

                <div className="grid gap-8 lg:grid-cols-[1fr_320px]">
                    <main className="grid content-start gap-6">
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
                                    weeks.post(`/learn/courses/${offering.id}/weeks`, { preserveScroll: true });
                                }}
                            >
                                <div className="grid gap-1">
                                    <span className="font-medium">Set up the course week by week</span>
                                    <span className="text-xs text-muted-foreground">Adds Week 1, Week 2, … — each opens to students on its week. Existing weeks are kept.</span>
                                </div>
                                <label className="grid gap-1">
                                    <span className="text-xs text-muted-foreground">Weeks</span>
                                    <input type="number" min={1} max={30} className={`${inputClass} w-24`} value={weeks.data.weeks} onChange={(e) => weeks.setData('weeks', Number(e.target.value))} />
                                </label>
                                <label className="grid gap-1">
                                    <span className="text-xs text-muted-foreground">First week starts (default: semester start)</span>
                                    <input type="date" className={inputClass} value={weeks.data.starts_on} onChange={(e) => weeks.setData('starts_on', e.target.value)} />
                                </label>
                                <label className="flex items-center gap-2 pb-2">
                                    <input type="checkbox" checked={weeks.data.release} onChange={(e) => weeks.setData('release', e.target.checked)} /> Open each week on its date
                                </label>
                                <Button disabled={weeks.processing}>Create weeks</Button>
                            </form>
                        )}
                        {sections.map((s, si) => (
                            <section
                                key={s.id}
                                className={`surface p-6 ${!s.visible ? 'opacity-60' : ''}`}
                            >
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p className="eyebrow">
                                            {String(si + 1).padStart(2, '0')}
                                        </p>
                                        <h2 className="mt-1 text-lg font-medium">
                                            {s.title}
                                        </h2>
                                        {s.summary && (
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                {s.summary}
                                            </p>
                                        )}
                                        {teacher &&
                                            s.release_at &&
                                            new Date(s.release_at) >
                                                new Date() && (
                                                <p className="mt-1 text-xs text-status-warning">
                                                    Students see this from{' '}
                                                    {formatDateTime(
                                                        s.release_at,
                                                    )}
                                                </p>
                                            )}
                                    </div>
                                    {teacher && (
                                        <div className="flex gap-1">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    router.put(
                                                        `/learn/courses/${offering.id}/sections/${s.id}`,
                                                        { visible: !s.visible },
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
                                                <Plus className="size-4" /> Add
                                            </Button>
                                        </div>
                                    )}
                                </div>
                                {adding === s.id && (
                                    <AddItem
                                        offeringId={offering.id}
                                        sectionId={s.id}
                                        onDone={() => setAdding(null)}
                                    />
                                )}
                                <ul className="mt-4 grid gap-1">
                                    {s.items.map((i) => {
                                        const Icon =
                                            itemIcon[i.type] ?? FileText;
                                        const content = (
                                            <>
                                                <span
                                                    className={`flex size-9 shrink-0 items-center justify-center rounded-xl ${i.done ? 'bg-brand text-brand-foreground' : 'bg-muted'}`}
                                                >
                                                    {i.done ? (
                                                        <Check className="size-4" />
                                                    ) : !i.open ? (
                                                        <Lock className="size-4" />
                                                    ) : (
                                                        <Icon className="size-4" />
                                                    )}
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate font-medium">
                                                        {i.title}
                                                    </span>
                                                    <span className="block text-xs text-muted-foreground">
                                                        {i.type}
                                                        {i.due_at &&
                                                            ` · due ${formatDateTime(i.due_at)}`}
                                                        {i.locked_reason &&
                                                            ` · ${i.locked_reason}`}
                                                        {teacher &&
                                                            i.prerequisite &&
                                                            ` · after "${i.prerequisite}"`}
                                                        {teacher &&
                                                            i.release_at &&
                                                            new Date(
                                                                i.release_at,
                                                            ) > new Date() &&
                                                            ` · opens ${formatDateTime(i.release_at)}`}
                                                    </span>
                                                </span>
                                                {teacher && !i.visible && (
                                                    <Status value="hidden" />
                                                )}
                                            </>
                                        );

                                        return (
                                            <li key={i.id}>
                                                {i.open ? (
                                                    <Link
                                                        href={`/learn/items/${i.id}`}
                                                        className="flex items-center gap-3 rounded-xl p-2 hover:bg-muted"
                                                    >
                                                        {content}
                                                    </Link>
                                                ) : (
                                                    <div className="flex items-center gap-3 rounded-xl p-2 text-muted-foreground">
                                                        {content}
                                                    </div>
                                                )}
                                            </li>
                                        );
                                    })}
                                </ul>
                            </section>
                        ))}

                        {teacher && (
                            <form
                                className="surface grid gap-3 p-6 md:grid-cols-[1fr_1fr_200px_auto]"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    section.post(
                                        `/learn/courses/${offering.id}/sections`,
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => section.reset(),
                                        },
                                    );
                                }}
                            >
                                <input
                                    className={inputClass}
                                    placeholder="New week or topic, e.g. Week 3: Stoichiometry"
                                    value={section.data.title}
                                    onChange={(e) =>
                                        section.setData('title', e.target.value)
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
                        {progress && (
                            <div className="surface p-5">
                                <p className="eyebrow">Your progress</p>
                                <p className="mt-2 text-3xl font-semibold tabular-nums">
                                    {progress.percent}%
                                </p>
                                <div className="mt-3 h-1.5 overflow-hidden rounded-full bg-muted">
                                    <div
                                        className="h-full rounded-full bg-brand"
                                        style={{
                                            width: `${progress.percent}%`,
                                        }}
                                    />
                                </div>
                                <p className="mt-2 text-xs text-muted-foreground">
                                    {progress.done} of {progress.total}{' '}
                                    activities completed
                                </p>
                            </div>
                        )}
                        <Timetable offeringId={offering.id} schedule={schedule ?? []} teacher={teacher} />
                        {recordings.length > 0 && (
                            <section className="surface grid gap-2 p-5" aria-label="Recorded lectures">
                                <p className="eyebrow">Recorded lectures</p>
                                {recordings.map((r) => (
                                    <a key={r.id} href={r.url} target="_blank" rel="noreferrer noopener" className="rounded-lg px-2 py-1.5 text-sm hover:bg-muted">
                                        {r.topic ?? 'Class'}
                                        <span className="block text-xs text-muted-foreground">{formatDateTime(r.held_at)}{teacher && !r.published ? ' · not published' : ''}</span>
                                    </a>
                                ))}
                            </section>
                        )}
                        {announcements.length > 0 && (
                            <div className="surface grid gap-3 p-5">
                                <p className="eyebrow">Announcements</p>
                                {announcements.map((a) => (
                                    <div
                                        key={a.id}
                                        className={
                                            a.urgent
                                                ? 'rounded-xl bg-status-critical/10 p-3'
                                                : ''
                                        }
                                    >
                                        <p className="text-sm font-medium">
                                            {a.title}
                                        </p>
                                        <RichContent
                                            html={a.body_html}
                                            className="text-sm text-muted-foreground"
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            {formatDateTime(a.publish_at)}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        )}
                        <div className="surface grid gap-3 p-5">
                            <div className="flex items-center justify-between">
                                <p className="eyebrow">Course outline</p>
                                {teacher && (
                                    <button
                                        className="text-xs text-brand"
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
                                    <Button size="sm">Save outline</Button>
                                </form>
                            ) : offering.outline_html ? (
                                <RichContent
                                    html={offering.outline_html}
                                    className="text-sm"
                                />
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    {offering.description ?? 'No outline yet.'}
                                </p>
                            )}
                        </div>
                        <div className="surface grid gap-2 p-5 text-sm">
                            <p className="eyebrow">Lecturers</p>
                            {offering.staff.map((s) => (
                                <p key={s.email}>
                                    {s.name}{' '}
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
                                    <Status value={offering.review_status} />
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
                                                        { action: 'approve' },
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
                                                        { action: 'return' },
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
                                                <option key={p.id} value={p.id}>
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
            </div>
        </>
    );
}

type Settings = Record<string, any>;

function AddItem({
    offeringId,
    sectionId,
    onDone,
}: {
    offeringId: number;
    sectionId: number;
    onDone: () => void;
}) {
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
                    {ITEM_TYPES.map(([k, l]) => (
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
            {['file', 'assignment', 'page'].includes(t) && (
                <input
                    type="file"
                    className="text-sm"
                    onChange={(e) =>
                        form.setData('file', e.target.files?.[0] ?? null)
                    }
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
