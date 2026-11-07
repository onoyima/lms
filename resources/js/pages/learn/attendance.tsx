import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import {
    Flash,
    formatDateTime,
    inputClass,
    PageHeader,
} from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Meeting = {
    id: number;
    held_at: string;
    topic: string | null;
    code: string | null;
    code_expires_at: string | null;
    recording: { url: string | null; file: boolean; size: number | null; published: boolean } | null;
};

/** Attach the lecture recording to a class: a link, or a file uploaded in resumable pieces (LMS 30). */
function RecordingPanel({ meeting }: { meeting: Meeting }) {
    const [url, setUrl] = useState(meeting.recording?.url ?? '');
    const [pct, setPct] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const r = meeting.recording;
    const post = (data: Record<string, string | boolean>) => router.post(`/learn/meetings/${meeting.id}/recording`, data, { preserveScroll: true });
    const upload = async (file: File) => {
        setError(null);

        try {
            const { resumableUpload } = await import('@/lib/resumable');
            const id = await resumableUpload(file, `recording:${meeting.id}`, setPct);
            post({ upload_id: id, published: r?.published ?? false });
        } catch (e) {
            setError(e instanceof Error ? e.message : 'The upload failed. Try again.');
        } finally {
            setPct(null);
        }
    };

    return (
        <section className="surface grid gap-3 p-5 text-sm" aria-label="Lecture recording">
            <p className="font-medium">Recording of this class</p>
            {r && <p className="text-muted-foreground">{r.file ? `Uploaded file${r.size ? ` (${Math.round(r.size / 1048576)} MB)` : ''}` : <a className="text-brand underline" href={r.url!} target="_blank" rel="noreferrer noopener">{r.url}</a>} · {r.published ? 'visible to the class' : 'not published'}</p>}
            <div className="flex flex-wrap items-end gap-2">
                <label className="grid flex-1 gap-1">Link (https) to the recording<input className={inputClass} value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://…" /></label>
                <Button variant="outline" disabled={!url.startsWith('https://')} onClick={() => post({ url, published: r?.published ?? false })}>Save link</Button>
                <label className="cursor-pointer rounded-md border px-3 py-2">
                    {pct !== null ? `Uploading ${pct}%` : 'Upload video or audio'}
                    <input type="file" accept="video/mp4,video/webm,audio/mp4,audio/mpeg,.mp4,.webm,.m4a,.mp3" className="sr-only" disabled={pct !== null} onChange={(e) => e.target.files?.[0] && upload(e.target.files[0])} />
                </label>
            </div>
            {error && <p className="text-destructive">{error}</p>}
            {r && (
                <div className="flex gap-2">
                    <Button size="sm" onClick={() => post({ published: !r.published })}>{r.published ? 'Hide from the class' : 'Publish to the class'}</Button>
                    <Button size="sm" variant="ghost" onClick={() => confirm('Remove this recording?') && post({ remove: true })}>Remove</Button>
                </div>
            )}
        </section>
    );
}
type Student = {
    id: number;
    name: string;
    identifier: string | null;
    records: Record<number, string>;
    rate: number | null;
};
const next: Record<string, string> = {
    present: 'late',
    late: 'absent',
    absent: 'excused',
    excused: 'present',
};
const tone: Record<string, string> = {
    present: 'bg-status-good/20 text-status-good',
    late: 'bg-status-warning/20 text-status-warning',
    absent: 'bg-status-critical/20 text-status-critical',
    excused: 'bg-muted text-muted-foreground',
};

export default function Attendance({
    offering,
    meetings,
    students,
}: {
    offering: { id: number; label: string };
    meetings: Meeting[];
    students: Student[];
}) {
    const form = useForm({
        held_at: new Date().toISOString().slice(0, 16),
        topic: '',
        code_minutes: 15,
    });
    const [active, setActive] = useState<number | null>(
        meetings[0]?.id ?? null,
    );
    const [draft, setDraft] = useState<Record<number, string>>({});
    const meeting = meetings.find((m) => m.id === active);

    return (
        <>
            <Head title="Attendance" />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <Link
                    href={`/learn/courses/${offering.id}`}
                    className="flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-brand"
                >
                    <ArrowLeft className="size-4" /> Course
                </Link>
                <PageHeader
                    title={offering.label}
                    lead="Start a class to show a code students enter on their phones, or tick the register yourself."
                />
                <Flash />
                <form
                    className="surface grid gap-3 p-5 md:grid-cols-[220px_1fr_160px_auto]"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/learn/courses/${offering.id}/meetings`, {
                            preserveScroll: true,
                        });
                    }}
                >
                    <input
                        type="datetime-local"
                        className={inputClass}
                        value={form.data.held_at}
                        onChange={(e) =>
                            form.setData('held_at', e.target.value)
                        }
                    />
                    <input
                        className={inputClass}
                        placeholder="Topic"
                        value={form.data.topic}
                        onChange={(e) => form.setData('topic', e.target.value)}
                    />
                    <label className="flex items-center gap-2 text-xs">
                        Code for
                        <input
                            type="number"
                            min={0}
                            className={`${inputClass} w-16`}
                            value={form.data.code_minutes}
                            onChange={(e) =>
                                form.setData(
                                    'code_minutes',
                                    Number(e.target.value),
                                )
                            }
                        />
                        min
                    </label>
                    <Button>Start class</Button>
                </form>
                {meeting?.code && (
                    <div className="surface flex flex-col items-center gap-2 p-10 text-center">
                        <p className="eyebrow">
                            Attendance code · until{' '}
                            {formatDateTime(meeting.code_expires_at)}
                        </p>
                        <p className="font-mono text-6xl font-semibold tracking-[0.3em] text-brand">
                            {meeting.code}
                        </p>
                    </div>
                )}
                <div className="flex flex-wrap gap-2">
                    {meetings.map((m) => (
                        <button
                            key={m.id}
                            onClick={() => {
                                setActive(m.id);
                                setDraft({});
                            }}
                            className={`rounded-xl border px-3 py-1.5 text-sm ${m.id === active ? 'border-brand bg-brand-soft' : 'border-border'}`}
                        >
                            {formatDateTime(m.held_at)}
                            {m.topic ? ` · ${m.topic}` : ''}
                        </button>
                    ))}
                </div>
                {meeting && <RecordingPanel key={meeting.id} meeting={meeting} />}
                {meeting && (
                    <div className="surface overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-border text-left">
                                    <th className="p-3">Student</th>
                                    <th className="p-3">This class</th>
                                    <th className="p-3">Overall</th>
                                </tr>
                            </thead>
                            <tbody>
                                {students.map((s) => {
                                    const st =
                                        draft[s.id] ??
                                        s.records[meeting.id] ??
                                        'absent';

                                    return (
                                        <tr
                                            key={s.id}
                                            className="border-b border-border/60"
                                        >
                                            <td className="p-3">
                                                {s.name}
                                                <span className="block text-xs text-muted-foreground">
                                                    {s.identifier}
                                                </span>
                                            </td>
                                            <td className="p-3">
                                                <button
                                                    onClick={() =>
                                                        setDraft({
                                                            ...draft,
                                                            [s.id]: next[st],
                                                        })
                                                    }
                                                    className={`rounded-full px-3 py-1 text-xs font-medium capitalize ${tone[st]}`}
                                                >
                                                    {st}
                                                </button>
                                            </td>
                                            <td
                                                className={`p-3 tabular-nums ${s.rate !== null && s.rate < 75 ? 'text-status-critical' : ''}`}
                                            >
                                                {s.rate ?? '—'}%
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                        <div className="flex gap-2 p-4">
                            <Button
                                disabled={!Object.keys(draft).length}
                                onClick={() =>
                                    router.post(
                                        `/learn/meetings/${meeting.id}/records`,
                                        { records: draft },
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => setDraft({}),
                                        },
                                    )
                                }
                            >
                                Save register
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() =>
                                    setDraft(
                                        Object.fromEntries(
                                            students.map((s) => [
                                                s.id,
                                                'present',
                                            ]),
                                        ),
                                    )
                                }
                            >
                                Everyone present
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}
