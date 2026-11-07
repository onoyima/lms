import { Head, Link, useForm } from '@inertiajs/react';
import {
    BookOpen,
    CalendarClock,
    ClipboardList,
    KeyRound,
    Users,
} from 'lucide-react';
import {
    Flash,
    formatDateTime,
    inputClass,
    PageHeader,
    Status,
} from '@/components/platform/ui';

type Teaching = {
    id: number;
    code: string;
    title: string;
    semester: string;
    students: number;
    role: string;
    to_mark: number;
};
type Enrolled = {
    id: number;
    code: string;
    title: string;
    semester: string;
    status: string;
    category: string;
    lecturers: string[];
    progress: { done: number; total: number; percent: number };
};
type Upcoming = {
    id: number;
    title: string;
    type: string;
    course: string;
    due_at: string;
    submitted: boolean;
};

export default function Courses({
    teaching,
    enrolled,
    upcoming,
}: {
    teaching: Teaching[];
    enrolled: Enrolled[];
    upcoming: Upcoming[];
}) {
    const checkIn = useForm({ code: '' });
    const active = enrolled.filter((e) => e.status === 'active');
    const past = enrolled.filter((e) => e.status !== 'active');

    return (
        <>
            <Head title="Courses" />
            <div className="flex flex-col gap-10 p-6 md:p-10">
                <PageHeader
                    title={
                        teaching.length && !active.length
                            ? 'Your teaching,'
                            : 'Your semester,'
                    }
                    accent="one page."
                    lead="Materials, assignments, quizzes, discussions and grades for every course you take or teach."
                    actions={
                        <>
                            <Link
                                href="/learn/registration"
                                className="rounded-xl border border-border px-4 py-2 text-sm"
                            >
                                Course registration
                            </Link>
                            <Link
                                href="/learn/announcements"
                                className="rounded-xl border border-border px-4 py-2 text-sm"
                            >
                                Announcements
                            </Link>
                        </>
                    }
                />
                <Flash />

                {teaching.length > 0 && (
                    <section className="grid gap-4">
                        <p className="eyebrow">Teaching</p>
                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {teaching.map((c) => (
                                <Link
                                    key={c.id}
                                    href={`/learn/courses/${c.id}`}
                                    className="surface group flex flex-col gap-3 p-6 transition hover:border-brand/50"
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <span className="font-mono text-sm text-brand">
                                            {c.code}
                                        </span>
                                        <span className="text-xs text-muted-foreground capitalize">
                                            {c.role}
                                        </span>
                                    </div>
                                    <h3 className="font-medium">{c.title}</h3>
                                    <p className="text-xs text-muted-foreground">
                                        {c.semester}
                                    </p>
                                    <div className="mt-auto flex gap-4 text-sm text-muted-foreground">
                                        <span className="flex items-center gap-1">
                                            <Users className="size-4" />{' '}
                                            {c.students}
                                        </span>
                                        {c.to_mark > 0 && (
                                            <span className="flex items-center gap-1 text-status-warning">
                                                <ClipboardList className="size-4" />{' '}
                                                {c.to_mark} to mark
                                            </span>
                                        )}
                                    </div>
                                </Link>
                            ))}
                        </div>
                    </section>
                )}

                {(active.length > 0 || teaching.length === 0) && (
                    <div className="grid gap-8 lg:grid-cols-[1fr_340px]">
                        <section className="grid content-start gap-4">
                            <p className="eyebrow">This semester</p>
                            {active.length === 0 && (
                                <div className="surface p-8 text-sm text-muted-foreground">
                                    You are not registered for any courses yet.{' '}
                                    <Link
                                        href="/learn/registration"
                                        className="text-brand underline-offset-4 hover:underline"
                                    >
                                        Register now
                                    </Link>
                                    .
                                </div>
                            )}
                            <div className="grid gap-4 md:grid-cols-2">
                                {active.map((c) => (
                                    <Link
                                        key={c.id}
                                        href={`/learn/courses/${c.id}`}
                                        className="surface flex flex-col gap-3 p-6 transition hover:border-brand/50"
                                    >
                                        <div className="flex items-center justify-between">
                                            <span className="font-mono text-sm text-brand">
                                                {c.code}
                                            </span>
                                            {c.category !== 'core' && (
                                                <Status value={c.category} />
                                            )}
                                        </div>
                                        <h3 className="font-medium">
                                            {c.title}
                                        </h3>
                                        {c.lecturers.length > 0 && (
                                            <p className="text-xs text-muted-foreground">
                                                {c.lecturers.join(', ')}
                                            </p>
                                        )}
                                        <div className="mt-auto">
                                            <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className="h-full rounded-full bg-brand"
                                                    style={{
                                                        width: `${c.progress.percent}%`,
                                                    }}
                                                />
                                            </div>
                                            <p className="mt-2 text-xs text-muted-foreground">
                                                {c.progress.done} of{' '}
                                                {c.progress.total} activities ·{' '}
                                                {c.progress.percent}%
                                            </p>
                                        </div>
                                    </Link>
                                ))}
                            </div>
                            {past.length > 0 && (
                                <details className="mt-4">
                                    <summary className="cursor-pointer text-sm text-muted-foreground">
                                        Previous courses ({past.length})
                                    </summary>
                                    <ul className="mt-3 grid gap-2 text-sm">
                                        {past.map((c) => (
                                            <li key={c.id}>
                                                <Link
                                                    href={`/learn/courses/${c.id}`}
                                                    className="hover:text-brand"
                                                >
                                                    {c.code} · {c.title}
                                                </Link>{' '}
                                                <span className="text-muted-foreground">
                                                    ({c.semester})
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </details>
                            )}
                        </section>
                        <aside className="grid content-start gap-4">
                            <div className="surface p-5">
                                <p className="eyebrow mb-3 flex items-center gap-2">
                                    <CalendarClock className="size-3.5" />{' '}
                                    Coming up
                                </p>
                                {upcoming.length === 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        Nothing due.
                                    </p>
                                )}
                                <ul className="grid gap-3">
                                    {upcoming.map((u) => (
                                        <li key={u.id}>
                                            <Link
                                                href={`/learn/items/${u.id}`}
                                                className="block rounded-xl p-2 hover:bg-muted"
                                            >
                                                <span className="flex items-center justify-between gap-2 text-sm font-medium">
                                                    {u.title}
                                                    {u.submitted ? (
                                                        <Status value="submitted" />
                                                    ) : new Date(u.due_at) <
                                                      new Date() ? (
                                                        <Status
                                                            value="overdue"
                                                            className="text-status-critical"
                                                        />
                                                    ) : null}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {u.course} · {u.type} · due{' '}
                                                    {formatDateTime(u.due_at)}
                                                </span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                            <form
                                className="surface grid gap-3 p-5"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    checkIn.post('/learn/attendance/check-in', {
                                        preserveScroll: true,
                                        onSuccess: () => checkIn.reset(),
                                    });
                                }}
                            >
                                <p className="eyebrow flex items-center gap-2">
                                    <KeyRound className="size-3.5" /> In class?
                                    Mark attendance
                                </p>
                                <input
                                    className={`${inputClass} font-mono tracking-widest uppercase`}
                                    placeholder="Code on the board"
                                    value={checkIn.data.code}
                                    onChange={(e) =>
                                        checkIn.setData('code', e.target.value)
                                    }
                                    maxLength={8}
                                />
                                <button
                                    className="h-10 rounded-xl bg-brand text-sm font-medium text-brand-foreground disabled:opacity-50"
                                    disabled={
                                        !checkIn.data.code || checkIn.processing
                                    }
                                >
                                    I'm here
                                </button>
                            </form>
                            <Link
                                href="/learn/grades"
                                className="surface flex items-center gap-2 p-5 text-sm hover:border-brand/50"
                            >
                                <BookOpen className="size-4" /> My course
                                results
                            </Link>
                        </aside>
                    </div>
                )}
            </div>
        </>
    );
}
