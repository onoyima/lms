import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle2,
    Clock,
    Download,
    ExternalLink,
    FileText,
    Pin,
    Trash2,
    Upload,
    Users,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    Flash,
    formatDateTime,
    inputClass,
    PageHeader,
    Status,
    Table,
    Td,
    textareaClass,
} from '@/components/platform/ui';
import { RichContent } from '@/components/rich-content';
import { Button } from '@/components/ui/button';
import { resumableUpload } from '@/lib/resumable';

type Rubric = {
    criterion: string;
    levels: { label: string; points: number }[];
}[];
type ItemData = {
    id: number;
    type: string;
    title: string;
    body_html: string | null;
    url: string | null;
    file_name: string | null;
    file_mime: string | null;
    file_size: number | null;
    release_at: string | null;
    due_at: string | null;
    cutoff_at: string | null;
    visible: boolean;
    requires_completion: boolean;
    prerequisite_item_id: number | null;
    course_section_id: number;
    settings: Record<string, unknown> & {
        max_score?: number;
        rubric?: Rubric;
        group?: boolean;
        anonymous?: boolean;
        file_types?: string[];
        max_mb?: number;
        max_attempts?: number;
        late_penalty_pct?: number;
        peer_review?: boolean;
    };
    version: number;
    exam_id: number | null;
};
type MySub = {
    id: number;
    attempt: number;
    status: string;
    submitted_at: string | null;
    is_late: boolean;
    file_name: string | null;
    file_hash: string | null;
    text: string | null;
    score: number | null;
    raw_score: number | null;
    feedback_html: string | null;
    rubric_scores: number[] | null;
};
type TeacherSub = {
    id: number;
    student: string;
    identifier: string | null;
    attempt: number;
    status: string;
    submitted_at: string;
    is_late: boolean;
    file_name: string | null;
    text: string | null;
    raw_score: number | null;
    score: number | null;
    feedback_html: string | null;
    rubric_scores: number[] | null;
    released: boolean;
    penalty: number;
    group: number | null;
    similarity: number | null;
    similarity_matches: { percent: number; submission_id: number; student: string; same_assignment: boolean }[];
    external_check: { provider: string; status: string; score: number | null; report_url: string | null; error: string | null } | null;
};
type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
};
type Props = {
    item: ItemData;
    offering: { id: number; code: string; title: string };
    teacher: boolean;
    canGrade: boolean;
    sections: { id: number; title: string }[];
    siblings: { id: number; title: string }[];
    revisions: { version: number; created_at: string }[];
    // assignment (student)
    due?: string | null;
    group?: { name: string; members: { id: number; name: string }[] } | null;
    submissions?: MySub[] | Paginated<TeacherSub>;
    peer?: {
        id: number;
        code: string;
        text: string | null;
        file_name: string | null;
        mine: { score: number | null; comment: string } | null;
    }[];
    // assignment (teacher)
    plagiarismService?: string | null;
    summary?: {
        enrolled: number;
        submitted: number;
        marked: number;
        unreleased: number;
    };
    extensions?: {
        name: string;
        identifier: string;
        due_at: string;
        reason: string;
    }[];
    groups?: {
        id: number;
        name: string;
        members: { id: number; name: string }[];
    }[];
    // forum
    threads?: Paginated<{
        id: number;
        title: string;
        author: string;
        posts: number;
        last_post_at: string;
        pinned: boolean;
        locked: boolean;
    }>;
    // quiz
    quiz?: {
        status: string | null;
        duration: number | null;
        attempts: number | null;
        questions: number;
        my_result: {
            final_score: number;
            max_score: number;
            status: string;
        } | null;
    };
};

export default function ItemPage(props: Props) {
    const { item, offering, teacher } = props;
    const [editing, setEditing] = useState(false);

    return (
        <>
            <Head title={item.title} />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <Link
                    href={`/learn/courses/${offering.id}`}
                    className="flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-brand"
                >
                    <ArrowLeft className="size-4" /> {offering.code} ·{' '}
                    {offering.title}
                </Link>
                <PageHeader
                    eyebrow={`${item.type}${item.due_at ? ` · due ${formatDateTime(props.due ?? item.due_at)}` : ''}`}
                    title={item.title}
                    actions={
                        teacher ? (
                            <>
                                <Button
                                    variant="outline"
                                    onClick={() => setEditing(!editing)}
                                >
                                    {editing ? 'Close editor' : 'Edit'}
                                </Button>
                                <Button
                                    variant="ghost"
                                    onClick={() =>
                                        confirm(
                                            'Remove this item? Submissions and marks are kept.',
                                        ) &&
                                        router.delete(`/learn/items/${item.id}`)
                                    }
                                    aria-label="Remove"
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            </>
                        ) : undefined
                    }
                />
                <Flash />
                {editing && (
                    <Editor {...props} onDone={() => setEditing(false)} />
                )}

                {item.body_html && (
                    <div className="surface p-6">
                        <RichContent
                            html={item.body_html}
                            className="leading-relaxed"
                        />
                    </div>
                )}

                {item.file_name && (
                    <div className="surface flex flex-wrap items-center justify-between gap-3 p-5">
                        <span className="flex items-center gap-2 text-sm">
                            <FileText className="size-4" /> {item.file_name}{' '}
                            {item.file_size
                                ? `· ${(item.file_size / 1048576).toFixed(1)} MB`
                                : ''}
                        </span>
                        <a
                            href={`/learn/items/${item.id}/file`}
                            className="flex items-center gap-1 rounded-xl bg-brand px-4 py-2 text-sm font-medium text-brand-foreground"
                        >
                            <Download className="size-4" /> Open
                        </a>
                    </div>
                )}
                {item.file_name && item.file_mime === 'application/pdf' && (
                    <iframe
                        src={`/learn/items/${item.id}/file`}
                        className="h-[75vh] w-full rounded-lg border border-border"
                        title={item.file_name}
                    />
                )}
                {item.file_name && item.file_mime?.startsWith('video/') && (
                    <video
                        src={`/learn/items/${item.id}/file`}
                        controls
                        preload="metadata"
                        className="w-full rounded-lg"
                    />
                )}
                {item.file_name && item.file_mime?.startsWith('audio/') && (
                    <audio
                        src={`/learn/items/${item.id}/file`}
                        controls
                        preload="metadata"
                        className="w-full"
                    />
                )}

                {item.url && (
                    <a
                        href={item.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="surface flex items-center justify-between gap-3 p-5 hover:border-brand/50"
                    >
                        <span className="truncate text-sm">
                            {item.type === 'live'
                                ? 'Join the live class'
                                : item.type === 'video'
                                  ? 'Watch the recording'
                                  : item.url}
                        </span>
                        <ExternalLink className="size-4" />
                    </a>
                )}

                {item.type === 'assignment' &&
                    (teacher ? (
                        <AssignmentTeacher {...props} />
                    ) : (
                        <AssignmentStudent {...props} />
                    ))}
                {item.type === 'quiz' && <Quiz {...props} />}
                {item.type === 'forum' && <Forum {...props} />}

                {!teacher &&
                    item.requires_completion &&
                    ['file'].includes(item.type) && (
                        <Button
                            variant="outline"
                            className="w-fit"
                            onClick={() =>
                                router.post(
                                    `/learn/items/${item.id}/complete`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <CheckCircle2 className="size-4" /> Mark as done
                        </Button>
                    )}
                {teacher && props.revisions.length > 0 && (
                    <details className="text-sm text-muted-foreground">
                        <summary className="cursor-pointer">
                            Version {item.version} · {props.revisions.length}{' '}
                            earlier versions
                        </summary>
                        <ul className="mt-2 grid gap-1">
                            {props.revisions.map((r) => (
                                <li key={r.version}>
                                    v{r.version} ·{' '}
                                    {formatDateTime(r.created_at)}
                                </li>
                            ))}
                        </ul>
                    </details>
                )}
            </div>
        </>
    );
}

function Editor({
    item,
    sections,
    siblings,
    onDone,
}: Props & { onDone: () => void }) {
    const toLocal = (s: string | null) =>
        s ? new Date(s).toISOString().slice(0, 16) : '';

    const form = useForm<Record<string, any>>({
        title: item.title,
        body_html: item.body_html ?? '',
        url: item.url ?? '',
        file: null,
        course_section_id: item.course_section_id,
        release_at: toLocal(item.release_at),
        due_at: toLocal(item.due_at),
        cutoff_at: toLocal(item.cutoff_at),
        visible: item.visible,
        requires_completion: item.requires_completion,
        prerequisite_item_id: item.prerequisite_item_id ?? '',
        settings: item.settings,
    });
    const [rubric, setRubric] = useState<Rubric>(item.settings.rubric ?? []);

    return (
        <form
            className="surface grid gap-4 p-6"
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((d) => {
                    const out: Record<string, unknown> = {
                        ...d,
                        settings:
                            item.type === 'assignment'
                                ? { ...(d.settings as object), rubric }
                                : d.settings,
                        visible: d.visible ? 1 : 0,
                        requires_completion: d.requires_completion ? 1 : 0,
                    };

                    for (const k of [
                        'release_at',
                        'due_at',
                        'cutoff_at',
                        'prerequisite_item_id',
                        'url',
                        'file',
                    ]) {
                        if (out[k] === '' || out[k] === null) {
                            delete out[k];
                        }
                    }

                    return out;
                });
                form.post(`/learn/items/${item.id}`, {
                    forceFormData: true,
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
        >
            <input
                className={inputClass}
                value={String(form.data.title)}
                onChange={(e) => form.setData('title', e.target.value)}
            />
            <textarea
                className={`${textareaClass} min-h-40`}
                value={String(form.data.body_html)}
                onChange={(e) => form.setData('body_html', e.target.value)}
            />
            {['link', 'video', 'live'].includes(item.type) && (
                <input
                    className={inputClass}
                    type="url"
                    value={String(form.data.url)}
                    onChange={(e) => form.setData('url', e.target.value)}
                />
            )}
            <input
                type="file"
                className="text-sm"
                onChange={(e) =>
                    form.setData('file', e.target.files?.[0] ?? null)
                }
            />
            <div className="grid gap-3 md:grid-cols-4">
                <label className="grid gap-1 text-xs">
                    Section
                    <select
                        className={inputClass}
                        value={String(form.data.course_section_id)}
                        onChange={(e) =>
                            form.setData(
                                'course_section_id',
                                Number(e.target.value),
                            )
                        }
                    >
                        {sections.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.title}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="grid gap-1 text-xs">
                    Release
                    <input
                        type="datetime-local"
                        className={inputClass}
                        value={String(form.data.release_at)}
                        onChange={(e) =>
                            form.setData('release_at', e.target.value)
                        }
                    />
                </label>
                <label className="grid gap-1 text-xs">
                    Due
                    <input
                        type="datetime-local"
                        className={inputClass}
                        value={String(form.data.due_at)}
                        onChange={(e) => form.setData('due_at', e.target.value)}
                    />
                </label>
                <label className="grid gap-1 text-xs">
                    Cut-off
                    <input
                        type="datetime-local"
                        className={inputClass}
                        value={String(form.data.cutoff_at)}
                        onChange={(e) =>
                            form.setData('cutoff_at', e.target.value)
                        }
                    />
                </label>
            </div>
            <div className="grid gap-3 md:grid-cols-3">
                <label className="grid gap-1 text-xs">
                    Only after completing
                    <select
                        className={inputClass}
                        value={String(form.data.prerequisite_item_id)}
                        onChange={(e) =>
                            form.setData('prerequisite_item_id', e.target.value)
                        }
                    >
                        <option value="">—</option>
                        {siblings.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.title}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={Boolean(form.data.visible)}
                        onChange={(e) =>
                            form.setData('visible', e.target.checked)
                        }
                    />{' '}
                    Visible to students
                </label>
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={Boolean(form.data.requires_completion)}
                        onChange={(e) =>
                            form.setData(
                                'requires_completion',
                                e.target.checked,
                            )
                        }
                    />{' '}
                    Counts towards progress
                </label>
            </div>
            {item.type === 'assignment' && (
                <div className="grid gap-3 rounded-lg bg-muted/50 p-4">
                    <p className="eyebrow">Rubric (optional)</p>
                    {rubric.map((c, i) => (
                        <div
                            key={i}
                            className="grid gap-2 md:grid-cols-[1fr_2fr_auto]"
                        >
                            <input
                                className={inputClass}
                                value={c.criterion}
                                placeholder="Criterion"
                                onChange={(e) =>
                                    setRubric(
                                        rubric.map((r, j) =>
                                            j === i
                                                ? {
                                                      ...r,
                                                      criterion: e.target.value,
                                                  }
                                                : r,
                                        ),
                                    )
                                }
                            />
                            <input
                                className={inputClass}
                                value={c.levels
                                    .map((l) => `${l.label}:${l.points}`)
                                    .join(', ')}
                                placeholder="Poor:0, Fair:2, Good:4"
                                onChange={(e) =>
                                    setRubric(
                                        rubric.map((r, j) =>
                                            j === i
                                                ? {
                                                      ...r,
                                                      levels: e.target.value
                                                          .split(',')
                                                          .map((p) =>
                                                              p.split(':'),
                                                          )
                                                          .filter((p) =>
                                                              p[0]?.trim(),
                                                          )
                                                          .map(([l, p]) => ({
                                                              label: l.trim(),
                                                              points:
                                                                  Number(p) ||
                                                                  0,
                                                          })),
                                                  }
                                                : r,
                                        ),
                                    )
                                }
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() =>
                                    setRubric(rubric.filter((_, j) => j !== i))
                                }
                            >
                                Remove
                            </Button>
                        </div>
                    ))}
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="w-fit"
                        onClick={() =>
                            setRubric([
                                ...rubric,
                                {
                                    criterion: '',
                                    levels: [
                                        { label: 'Poor', points: 0 },
                                        { label: 'Good', points: 5 },
                                    ],
                                },
                            ])
                        }
                    >
                        Add criterion
                    </Button>
                </div>
            )}
            {Object.values(form.errors).length > 0 && (
                <p className="text-sm text-status-critical">
                    {Object.values(form.errors).join(' ')}
                </p>
            )}
            <Button className="w-fit" disabled={form.processing}>
                Save (keeps the previous version)
            </Button>
        </form>
    );
}

function AssignmentStudent({ item, submissions, due, group, peer }: Props) {
    const subs = (submissions ?? []) as MySub[];
    const latest = subs[0];
    const form = useForm<{ text: string; file: File | null }>({
        text: '',
        file: null,
    });
    const [bigProgress, setBigProgress] = useState<number | null>(null);
    const [bigError, setBigError] = useState<string | null>(null);
    const s = item.settings;
    const closed = item.cutoff_at && new Date(item.cutoff_at) < new Date();

    return (
        <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
            <div className="grid content-start gap-4">
                {latest?.score !== null && latest?.score !== undefined && (
                    <div className="surface border-brand/40 p-6">
                        <p className="eyebrow">Your mark</p>
                        <p className="mt-2 text-3xl font-semibold">
                            {latest.score}{' '}
                            <span className="text-base text-muted-foreground">
                                / {s.max_score}
                            </span>
                        </p>
                        {latest.raw_score !== latest.score && (
                            <p className="text-sm text-muted-foreground">
                                Marked {latest.raw_score}, late penalty applied.
                            </p>
                        )}
                        {s.rubric && latest.rubric_scores && (
                            <ul className="mt-3 grid gap-1 text-sm">
                                {s.rubric.map((c, i) => (
                                    <li key={i}>
                                        {c.criterion}:{' '}
                                        <strong>
                                            {latest.rubric_scores?.[i]}
                                        </strong>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {latest.feedback_html && (
                            <RichContent
                                html={latest.feedback_html}
                                className="mt-4 rounded-xl bg-muted p-4 text-sm"
                            />
                        )}
                    </div>
                )}
                {!closed && (
                    <form
                        className="surface grid gap-3 p-6"
                        onSubmit={async (e) => {
                            e.preventDefault();
                            const f = form.data.file;

                            // big files go up in resumable pieces, so a dropped connection does not lose the upload
                            if (f && f.size > 8 * 1024 * 1024) {
                                setBigError(null);
                                setBigProgress(0);

                                try {
                                    const id = await resumableUpload(f, `assignment:${item.id}`, setBigProgress);
                                    router.post(`/learn/items/${item.id}/submit`, { text: form.data.text, upload_id: id }, {
                                        preserveScroll: true,
                                        onSuccess: () => form.reset(),
                                        onFinish: () => setBigProgress(null),
                                    });
                                } catch (err) {
                                    setBigProgress(null);
                                    setBigError(err instanceof Error ? err.message : 'Upload failed.');
                                }

                                return;
                            }

                            form.post(`/learn/items/${item.id}/submit`, {
                                forceFormData: true,
                                preserveScroll: true,
                                onSuccess: () => form.reset(),
                            });
                        }}
                    >
                        <p className="eyebrow">
                            {latest
                                ? 'Submit again (replaces your previous file until the deadline)'
                                : 'Your submission'}
                        </p>
                        {s.text !== false && (
                            <textarea
                                className={`${textareaClass} min-h-40`}
                                placeholder="Type your answer here, or attach a file"
                                value={form.data.text}
                                onChange={(e) =>
                                    form.setData('text', e.target.value)
                                }
                            />
                        )}
                        {s.file !== false && (
                            <label className="flex cursor-pointer items-center gap-2 rounded-xl border border-dashed border-border p-4 text-sm">
                                <Upload className="size-4" />{' '}
                                {form.data.file
                                    ? form.data.file.name
                                    : `Attach a file (${(s.file_types ?? []).slice(0, 8).join(', ')}… up to ${s.max_mb} MB)`}
                                <input
                                    type="file"
                                    className="hidden"
                                    onChange={(e) =>
                                        form.setData(
                                            'file',
                                            e.target.files?.[0] ?? null,
                                        )
                                    }
                                />
                            </label>
                        )}
                        {form.progress && (
                            <progress
                                value={form.progress.percentage}
                                max={100}
                                className="w-full"
                            />
                        )}
                        {bigProgress !== null && (
                            <p className="grid gap-1 text-xs text-muted-foreground">
                                Uploading in pieces — you can lose the connection and it will carry on. {bigProgress}%
                                <progress value={bigProgress} max={100} className="w-full" />
                            </p>
                        )}
                        {bigError && <p className="text-sm text-status-critical">{bigError}</p>}
                        {Object.values(form.errors).length > 0 && (
                            <p className="text-sm text-status-critical">
                                {Object.values(form.errors).join(' ')}
                            </p>
                        )}
                        <Button className="w-fit" disabled={form.processing}>
                            {form.processing ? 'Uploading…' : 'Submit'}
                        </Button>
                    </form>
                )}
                {peer && peer.length > 0 && (
                    <PeerReviews peer={peer} max={Number(s.max_score)} />
                )}
            </div>
            <aside className="grid content-start gap-4">
                <div className="surface grid gap-2 p-5 text-sm">
                    <p className="eyebrow">Details</p>
                    <p className="flex items-center gap-2">
                        <Clock className="size-4" /> Due{' '}
                        {due ? formatDateTime(due) : '—'}
                    </p>
                    {item.cutoff_at && (
                        <p className="text-muted-foreground">
                            No submissions after{' '}
                            {formatDateTime(item.cutoff_at)}
                        </p>
                    )}
                    <p className="text-muted-foreground">
                        Out of {s.max_score} · late penalty {s.late_penalty_pct}
                        % per day
                    </p>
                    {group && (
                        <p className="flex items-center gap-2">
                            <Users className="size-4" /> {group.name}:{' '}
                            {group.members.map((m) => m.name).join(', ')}
                        </p>
                    )}
                </div>
                {subs.length > 0 && (
                    <div className="surface grid gap-3 p-5 text-sm">
                        <p className="eyebrow">Your submissions</p>
                        {subs.map((x) => (
                            <div key={x.id} className="grid gap-1">
                                <span className="flex items-center gap-2">
                                    Attempt {x.attempt}{' '}
                                    <Status value={x.status} />{' '}
                                    {x.is_late && (
                                        <Status
                                            value="late"
                                            className="text-status-warning"
                                        />
                                    )}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {formatDateTime(x.submitted_at)}
                                </span>
                                {x.file_name && (
                                    <a
                                        href={`/learn/submissions/${x.id}/file`}
                                        className="text-xs text-brand"
                                    >
                                        {x.file_name}
                                    </a>
                                )}
                                {x.file_hash && (
                                    <span className="font-mono text-[10px] break-all text-muted-foreground">
                                        receipt {x.file_hash.slice(0, 16)}
                                    </span>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </aside>
        </div>
    );
}

function PeerReviews({
    peer,
    max,
}: {
    peer: NonNullable<Props['peer']>;
    max: number;
}) {
    return (
        <div className="surface grid gap-4 p-6">
            <p className="eyebrow">Peer review</p>
            {peer.map((p) => (
                <form
                    key={p.id}
                    className="grid gap-2 rounded-xl bg-muted/50 p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const f = new FormData(e.currentTarget);
                        router.post(
                            `/learn/submissions/${p.id}/peer`,
                            {
                                score: f.get('score') || null,
                                comment: f.get('comment'),
                            },
                            { preserveScroll: true },
                        );
                    }}
                >
                    <p className="text-sm font-medium">Submission {p.code}</p>
                    {p.text && (
                        <RichContent html={p.text} className="text-sm" />
                    )}
                    <input
                        name="score"
                        type="number"
                        min={0}
                        max={max}
                        step="0.5"
                        defaultValue={p.mine?.score ?? ''}
                        className={`${inputClass} w-32`}
                        placeholder={`Score /${max}`}
                    />
                    <textarea
                        name="comment"
                        defaultValue={p.mine?.comment ?? ''}
                        className={textareaClass}
                        placeholder="What works well, and what could be better?"
                        required
                    />
                    <Button size="sm" className="w-fit">
                        {p.mine ? 'Update review' : 'Save review'}
                    </Button>
                </form>
            ))}
        </div>
    );
}

function AssignmentTeacher({
    item,
    submissions,
    summary,
    extensions,
    groups,
    canGrade,
    plagiarismService,
}: Props) {
    const subs = submissions as Paginated<TeacherSub>;
    const [marking, setMarking] = useState<TeacherSub | null>(null);
    const ext = useForm({ identifier: '', due_at: '', reason: '' });

    return (
        <div className="grid gap-6">
            <div className="grid gap-4 sm:grid-cols-4">
                {[
                    ['Students', summary?.enrolled],
                    ['Submitted', summary?.submitted],
                    ['Marked', summary?.marked],
                    ['Not yet released', summary?.unreleased],
                ].map(([l, v]) => (
                    <div key={String(l)} className="surface px-5 py-4">
                        <p className="eyebrow">{l}</p>
                        <p className="mt-2 text-2xl font-semibold tabular-nums">
                            {v}
                        </p>
                    </div>
                ))}
            </div>
            <div className="flex flex-wrap gap-2">
                {canGrade && (summary?.unreleased ?? 0) > 0 && (
                    <Button
                        onClick={() =>
                            confirm(
                                `Release ${summary?.unreleased} marks to students now?`,
                            ) &&
                            router.post(
                                `/learn/items/${item.id}/release`,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Release marks
                    </Button>
                )}
                {item.settings.group && (
                    <Button
                        variant="outline"
                        onClick={() => {
                            const size = prompt('Group size?', '4');

                            if (size) {
                                router.post(
                                    `/learn/items/${item.id}/groups`,
                                    { size: Number(size) },
                                    { preserveScroll: true },
                                );
                            }
                        }}
                    >
                        <Users className="size-4" />{' '}
                        {groups?.length
                            ? 'Re-make groups'
                            : 'Make random groups'}
                    </Button>
                )}
                {item.settings.anonymous && (
                    <span className="self-center text-sm text-muted-foreground">
                        Anonymous marking: names appear after release.
                    </span>
                )}
            </div>
            <Table
                head={['Student', 'Submitted', 'File', 'Mark', 'Status', '']}
            >
                {subs.data.map((s) => (
                    <tr key={s.id}>
                        <Td>
                            <span className="font-medium">{s.student}</span>
                            <span className="block text-xs text-muted-foreground">
                                {s.identifier}
                                {s.attempt > 1 ? ` · attempt ${s.attempt}` : ''}
                            </span>
                        </Td>
                        <Td>
                            {formatDateTime(s.submitted_at)}{' '}
                            {s.is_late && (
                                <Status
                                    value={`late −${s.penalty}%`}
                                    className="text-status-warning"
                                />
                            )}
                            {s.similarity !== null && s.similarity >= 15 && (
                                <span
                                    className={`ml-1 rounded px-1.5 py-0.5 text-[11px] ${s.similarity >= 40 ? 'bg-status-critical/15 text-status-critical' : 'bg-status-warning/15 text-status-warning'}`}
                                    title={s.similarity_matches.map((m) => `${m.percent}% like ${m.student}${m.same_assignment ? '' : ' (earlier class)'}`).join('\n')}
                                >
                                    {s.similarity}% similar
                                </span>
                            )}
                            {s.external_check?.status === 'complete' && s.external_check.score !== null && (
                                <span className="ml-1 text-[11px]">
                                    {s.external_check.provider}: {s.external_check.report_url ? <a href={s.external_check.report_url} target="_blank" rel="noreferrer noopener" className="text-brand underline">{s.external_check.score}%</a> : `${s.external_check.score}%`}
                                </span>
                            )}
                            {s.external_check && s.external_check.status !== 'complete' && (
                                <span className="ml-1 text-[11px] text-muted-foreground" title={s.external_check.error ?? undefined}>{s.external_check.provider}: {s.external_check.status}</span>
                            )}
                            {plagiarismService && !s.external_check && (
                                <button type="button" className="ml-1 text-[11px] text-brand underline" onClick={() => router.post(`/learn/submissions/${s.id}/plagiarism`, {}, { preserveScroll: true })}>
                                    Check with {plagiarismService}
                                </button>
                            )}
                        </Td>
                        <Td>
                            {s.file_name ? (
                                <a
                                    href={`/learn/submissions/${s.id}/file`}
                                    className="text-brand"
                                >
                                    Download
                                </a>
                            ) : s.text ? (
                                'Text'
                            ) : (
                                '—'
                            )}
                        </Td>
                        <Td className="tabular-nums">
                            {s.score ?? '—'}
                            {s.raw_score !== null && s.raw_score !== s.score ? (
                                <span className="text-xs text-muted-foreground">
                                    {' '}
                                    ({s.raw_score})
                                </span>
                            ) : null}
                        </Td>
                        <Td>
                            <Status
                                value={s.released ? 'released' : s.status}
                            />
                        </Td>
                        <Td>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setMarking(s)}
                            >
                                {s.score === null ? 'Mark' : 'Edit'}
                            </Button>
                        </Td>
                    </tr>
                ))}
            </Table>
            {subs.links.length > 3 && (
                <div className="flex gap-1">
                    {subs.links.map(
                        (l, i) =>
                            l.url && (
                                <Link
                                    key={i}
                                    href={l.url}
                                    className={`rounded-lg px-3 py-1 text-sm ${l.active ? 'bg-brand text-brand-foreground' : 'border border-border'}`}
                                    dangerouslySetInnerHTML={{
                                        __html: l.label,
                                    }}
                                />
                            ),
                    )}
                </div>
            )}
            {marking && (
                <MarkDialog
                    item={item}
                    sub={marking}
                    onClose={() => setMarking(null)}
                />
            )}

            {canGrade && (
                <form
                    className="surface grid gap-3 p-6 md:grid-cols-[1fr_200px_1fr_auto]"
                    onSubmit={(e) => {
                        e.preventDefault();
                        ext.post(`/learn/items/${item.id}/extensions`, {
                            preserveScroll: true,
                            onSuccess: () => ext.reset(),
                        });
                    }}
                >
                    <p className="eyebrow md:col-span-4">Grant an extension</p>
                    <input
                        className={inputClass}
                        placeholder="Matric number or email"
                        value={ext.data.identifier}
                        onChange={(e) =>
                            ext.setData('identifier', e.target.value)
                        }
                        required
                    />
                    <input
                        type="datetime-local"
                        className={inputClass}
                        value={ext.data.due_at}
                        onChange={(e) => ext.setData('due_at', e.target.value)}
                        required
                    />
                    <input
                        className={inputClass}
                        placeholder="Reason (kept in the audit log)"
                        value={ext.data.reason}
                        onChange={(e) => ext.setData('reason', e.target.value)}
                        required
                    />
                    <Button disabled={ext.processing}>Grant</Button>
                    {Object.values(ext.errors).length > 0 && (
                        <p className="text-sm text-status-critical md:col-span-4">
                            {Object.values(ext.errors).join(' ')}
                        </p>
                    )}
                    {extensions && extensions.length > 0 && (
                        <ul className="grid gap-1 text-sm text-muted-foreground md:col-span-4">
                            {extensions.map((x) => (
                                <li key={x.identifier}>
                                    {x.name} ({x.identifier}) →{' '}
                                    {formatDateTime(x.due_at)} · {x.reason}
                                </li>
                            ))}
                        </ul>
                    )}
                </form>
            )}
        </div>
    );
}

function MarkDialog({
    item,
    sub,
    onClose,
}: {
    item: ItemData;
    sub: TeacherSub;
    onClose: () => void;
}) {
    const rubric = item.settings.rubric ?? [];
    const form = useForm<{
        score: string;
        rubric: number[];
        feedback_html: string;
    }>({
        score: sub.raw_score?.toString() ?? '',
        rubric: sub.rubric_scores ?? rubric.map(() => 0),
        feedback_html: sub.feedback_html ?? '',
    });

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            role="dialog"
            aria-modal="true"
        >
            <form
                className="surface max-h-[90vh] w-full max-w-2xl overflow-y-auto bg-background p-8"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.transform((d) =>
                        rubric.length
                            ? {
                                  rubric: d.rubric,
                                  feedback_html: d.feedback_html,
                              }
                            : {
                                  score: d.score,
                                  feedback_html: d.feedback_html,
                              },
                    );
                    form.post(`/learn/submissions/${sub.id}/mark`, {
                        preserveScroll: true,
                        onSuccess: onClose,
                    });
                }}
            >
                <p className="eyebrow">Marking</p>
                <h3 className="mt-1 text-lg font-medium">{sub.student}</h3>
                {sub.text && (
                    <RichContent
                        html={sub.text}
                        className="mt-4 max-h-64 overflow-y-auto rounded-xl bg-muted p-4 text-sm"
                    />
                )}
                {sub.file_name && (
                    <a
                        href={`/learn/submissions/${sub.id}/file`}
                        className="mt-3 inline-flex items-center gap-1 text-sm text-brand"
                    >
                        <Download className="size-4" /> {sub.file_name}
                    </a>
                )}
                {rubric.length ? (
                    <div className="mt-6 grid gap-4">
                        {rubric.map((c, i) => (
                            <fieldset key={i}>
                                <legend className="text-sm font-medium">
                                    {c.criterion}
                                </legend>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {c.levels.map((l) => (
                                        <button
                                            type="button"
                                            key={l.label}
                                            onClick={() =>
                                                form.setData(
                                                    'rubric',
                                                    form.data.rubric.map(
                                                        (v, j) =>
                                                            j === i
                                                                ? l.points
                                                                : v,
                                                    ),
                                                )
                                            }
                                            className={`rounded-xl border px-3 py-1.5 text-sm ${form.data.rubric[i] === l.points ? 'border-brand bg-brand-soft' : 'border-border'}`}
                                        >
                                            {l.label} · {l.points}
                                        </button>
                                    ))}
                                </div>
                            </fieldset>
                        ))}
                        <p className="text-sm">
                            Total:{' '}
                            <strong>
                                {form.data.rubric.reduce(
                                    (a, b) => a + Number(b),
                                    0,
                                )}
                            </strong>{' '}
                            / {item.settings.max_score}
                        </p>
                    </div>
                ) : (
                    <label className="mt-6 grid gap-1 text-sm">
                        Mark (out of {item.settings.max_score})
                        {sub.penalty > 0 &&
                            `, late penalty −${sub.penalty}% applied automatically`}
                        <input
                            type="number"
                            step="0.25"
                            min={0}
                            max={item.settings.max_score}
                            className={`${inputClass} w-40`}
                            value={form.data.score}
                            onChange={(e) =>
                                form.setData('score', e.target.value)
                            }
                            required
                        />
                    </label>
                )}
                <label className="mt-4 grid gap-1 text-sm">
                    Feedback
                    <textarea
                        className={`${textareaClass} min-h-32`}
                        value={form.data.feedback_html}
                        onChange={(e) =>
                            form.setData('feedback_html', e.target.value)
                        }
                    />
                </label>
                {Object.values(form.errors).length > 0 && (
                    <p className="mt-2 text-sm text-status-critical">
                        {Object.values(form.errors).join(' ')}
                    </p>
                )}
                <div className="mt-6 flex gap-2">
                    <Button disabled={form.processing}>Save mark</Button>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                </div>
            </form>
        </div>
    );
}

type QuizQ = {
    id: number;
    type: string;
    stem_html: string;
    mark: number;
    topic?: string;
};

function Quiz({ item, quiz, teacher, canGrade }: Props) {
    const [data, setData] = useState<{
        attached: QuizQ[];
        found: QuizQ[];
        editable: boolean;
    } | null>(null);
    const [q, setQ] = useState('');
    const load = (search = q) =>
        fetch(
            `/learn/items/${item.id}/quiz/questions?q=${encodeURIComponent(search)}`,
            { headers: { Accept: 'application/json' } },
        )
            .then((r) => r.json())
            .then(setData);
    useEffect(() => {
        if (teacher && canGrade) {
            load('');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [teacher, canGrade]);
    const change = (body: { attach?: number[]; detach?: number[] }) =>
        router.post(`/learn/items/${item.id}/quiz/questions`, body, {
            preserveScroll: true,
            onSuccess: () => load(),
        });

    if (!quiz) {
        return null;
    }

    if (!teacher) {
        return (
            <div className="surface grid gap-4 p-6">
                <p className="text-sm text-muted-foreground">
                    {quiz.duration} minutes · {quiz.attempts} attempt
                    {quiz.attempts === 1 ? '' : 's'} · {quiz.questions}{' '}
                    questions. Your answers save automatically.
                </p>
                {quiz.my_result && (
                    <p>
                        Score: <strong>{quiz.my_result.final_score}</strong> /{' '}
                        {quiz.my_result.max_score}
                    </p>
                )}
                {quiz.status === 'scheduled' ? (
                    <Button
                        className="w-fit"
                        onClick={() =>
                            router.post(`/learn/items/${item.id}/quiz/start`)
                        }
                    >
                        Open quiz
                    </Button>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        Not open yet.
                    </p>
                )}
            </div>
        );
    }

    return (
        <div className="grid gap-4">
            <div className="flex flex-wrap items-center gap-3">
                <Status value={quiz.status ?? 'draft'} />
                <span className="text-sm text-muted-foreground">
                    {quiz.questions} questions · {quiz.duration} min
                </span>
                {quiz.status === 'draft' && canGrade && (
                    <Button
                        onClick={() =>
                            router.post(
                                `/learn/items/${item.id}/quiz/publish`,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Publish quiz
                    </Button>
                )}
                <Link
                    href="/question-bank/import"
                    className="text-sm text-brand"
                >
                    Import questions from Word/PDF
                </Link>
            </div>
            {data && (
                <div className="grid gap-4 lg:grid-cols-2">
                    <div className="surface grid content-start gap-2 p-5">
                        <p className="eyebrow">In this quiz</p>
                        {data.attached.map((x, i) => (
                            <div
                                key={x.id}
                                className="flex items-start gap-3 rounded-xl p-2 hover:bg-muted"
                            >
                                <span className="text-xs text-muted-foreground">
                                    {i + 1}
                                </span>
                                <RichContent
                                    html={x.stem_html}
                                    className="line-clamp-3 flex-1 text-sm"
                                />
                                {data.editable && (
                                    <button
                                        className="text-xs text-status-critical"
                                        onClick={() =>
                                            change({ detach: [x.id] })
                                        }
                                    >
                                        Remove
                                    </button>
                                )}
                            </div>
                        ))}
                    </div>
                    {data.editable && (
                        <div className="surface grid content-start gap-2 p-5">
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    load();
                                }}
                                className="flex gap-2"
                            >
                                <input
                                    className={inputClass}
                                    placeholder="Search the question bank"
                                    value={q}
                                    onChange={(e) => setQ(e.target.value)}
                                />
                                <Button variant="outline">Search</Button>
                            </form>
                            {data.found.map((x) => (
                                <div
                                    key={x.id}
                                    className="flex items-start gap-3 rounded-xl p-2 hover:bg-muted"
                                >
                                    <RichContent
                                        html={x.stem_html}
                                        className="line-clamp-3 flex-1 text-sm"
                                    />
                                    <button
                                        className="text-xs text-brand"
                                        onClick={() =>
                                            change({ attach: [x.id] })
                                        }
                                    >
                                        Add
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

function Forum({ item, threads, teacher }: Props) {
    const form = useForm({ title: '', body_html: '' });

    return (
        <div className="grid gap-6 lg:grid-cols-[1fr_360px]">
            <div className="surface divide-y divide-border">
                {threads?.data.length === 0 && (
                    <p className="p-6 text-sm text-muted-foreground">
                        No discussions yet. Start one.
                    </p>
                )}
                {threads?.data.map((t) => (
                    <Link
                        key={t.id}
                        href={`/learn/threads/${t.id}`}
                        className="flex items-center justify-between gap-3 p-4 hover:bg-muted/50"
                    >
                        <span>
                            <span className="flex items-center gap-2 font-medium">
                                {t.pinned && (
                                    <Pin className="size-3.5 text-brand" />
                                )}
                                {t.title}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {t.author} · {t.posts} posts ·{' '}
                                {formatDateTime(t.last_post_at)}
                            </span>
                        </span>
                        {t.locked && <Status value="closed" />}
                    </Link>
                ))}
            </div>
            <form
                className="surface grid content-start gap-3 p-5"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(`/learn/items/${item.id}/threads`);
                }}
            >
                <p className="eyebrow">
                    {teacher ? 'Post to the class' : 'Ask a question'}
                </p>
                <input
                    className={inputClass}
                    placeholder="Title"
                    value={form.data.title}
                    onChange={(e) => form.setData('title', e.target.value)}
                    required
                />
                <textarea
                    className={`${textareaClass} min-h-32`}
                    placeholder="Write here. Maths: \( x^2 \), chemistry: \( \ce{H2O} \)"
                    value={form.data.body_html}
                    onChange={(e) => form.setData('body_html', e.target.value)}
                    required
                />
                <Button disabled={form.processing}>Post</Button>
            </form>
        </div>
    );
}
