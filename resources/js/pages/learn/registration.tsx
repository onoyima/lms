import { Head, router, useForm } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    Flash,
    formatDateTime,
    inputClass,
    PageHeader,
    Status,
    Table,
    Td,
} from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Course = {
    id: number;
    code: string;
    title: string;
    units: number;
    level: number | null;
    category: string;
    status: string | null;
    seats_left: number | null;
    blocked: string | null;
};
type Props = {
    semester: { id: number; label: string } | null;
    semesters: { id: number; label: string }[];
    window: {
        state: string;
        opens_at?: string;
        closes_at?: string;
        late_closes_at?: string | null;
        min_units?: number;
        max_units?: number;
        requires_approval?: boolean;
    };
    profile: {
        programme: string | null;
        level: number | null;
        status: string;
    } | null;
    catalogue: Course[];
    isStudent: boolean;
    canManage: boolean;
    pending: {
        id: number;
        student: string;
        identifier: string;
        course: string;
        category: string;
    }[];
};

export default function Registration({
    semester,
    semesters,
    window: w,
    profile,
    catalogue,
    isStudent,
    canManage,
    pending,
}: Props) {
    const [chosen, setChosen] = useState<number[]>(
        catalogue
            .filter((c) => c.status === 'active' || c.status === 'pending')
            .map((c) => c.id),
    );
    const units = useMemo(
        () =>
            catalogue
                .filter((c) => chosen.includes(c.id))
                .reduce((a, c) => a + c.units, 0),
        [chosen, catalogue],
    );
    const open = w.state === 'open' || w.state === 'late';
    const toggle = (id: number) =>
        setChosen(
            chosen.includes(id)
                ? chosen.filter((x) => x !== id)
                : [...chosen, id],
        );
    const win = useForm({
        semester_id: semester?.id ?? 0,
        opens_at: '',
        closes_at: '',
        late_closes_at: '',
        min_units: w.min_units ?? 15,
        max_units: w.max_units ?? 24,
        requires_approval: w.requires_approval ?? false,
    });
    const ov = useForm({ identifier: '', courses: [] as number[], reason: '' });

    return (
        <>
            <Head title="Course registration" />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader
                    eyebrow={semester?.label ?? 'Registration'}
                    title="Course registration"
                    lead={
                        profile
                            ? `${profile.programme ?? 'Programme not set'} · ${profile.level ?? '—'} level · ${profile.status}`
                            : undefined
                    }
                    actions={
                        <>
                            <select
                                className={inputClass}
                                value={semester?.id}
                                onChange={(e) =>
                                    router.get('/learn/registration', {
                                        semester: e.target.value,
                                    })
                                }
                            >
                                {semesters.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.label}
                                    </option>
                                ))}
                            </select>
                            {isStudent && (
                                <a
                                    href={`/my/documents/registration-slip?semester=${semester?.id}`}
                                    className="flex items-center gap-1 rounded-xl border border-border px-3 py-2 text-sm whitespace-nowrap"
                                >
                                    <Printer className="size-4" /> Slip (PDF)
                                </a>
                            )}
                        </>
                    }
                />
                <Flash />
                <div className="surface flex flex-wrap items-center gap-4 p-5 text-sm">
                    <Status
                        value={
                            w.state === 'open'
                                ? 'open'
                                : w.state === 'late'
                                  ? 'late registration'
                                  : w.state === 'none'
                                    ? 'not set up'
                                    : 'closed'
                        }
                    />
                    {w.opens_at && (
                        <span>
                            {formatDateTime(w.opens_at)} –{' '}
                            {formatDateTime(w.closes_at)}
                            {w.late_closes_at &&
                                ` (late until ${formatDateTime(w.late_closes_at)})`}
                        </span>
                    )}
                    {w.min_units !== undefined && (
                        <span className="text-muted-foreground">
                            {w.min_units}–{w.max_units} units
                        </span>
                    )}
                    {w.requires_approval && (
                        <span className="text-muted-foreground">
                            Needs adviser approval
                        </span>
                    )}
                </div>

                {isStudent && (
                    <>
                        <Table
                            head={[
                                '',
                                'Course',
                                'Units',
                                'Type',
                                'Seats',
                                'Status',
                            ]}
                        >
                            {catalogue.map((c) => (
                                <tr
                                    key={c.id}
                                    className={
                                        c.blocked && !chosen.includes(c.id)
                                            ? 'opacity-60'
                                            : ''
                                    }
                                >
                                    <Td>
                                        <input
                                            type="checkbox"
                                            checked={chosen.includes(c.id)}
                                            disabled={
                                                !open ||
                                                (!!c.blocked &&
                                                    !chosen.includes(c.id))
                                            }
                                            onChange={() => toggle(c.id)}
                                            aria-label={`Register ${c.code}`}
                                        />
                                    </Td>
                                    <Td>
                                        <span className="font-mono text-brand">
                                            {c.code}
                                        </span>{' '}
                                        <span className="block">{c.title}</span>
                                        {c.blocked && (
                                            <span className="block text-xs text-status-warning">
                                                {c.blocked}
                                            </span>
                                        )}
                                    </Td>
                                    <Td>{c.units}</Td>
                                    <Td>
                                        <Status value={c.category} />
                                    </Td>
                                    <Td>{c.seats_left ?? '—'}</Td>
                                    <Td>
                                        <Status value={c.status} />
                                    </Td>
                                </tr>
                            ))}
                        </Table>
                        <div className="surface sticky bottom-4 flex flex-wrap items-center justify-between gap-3 p-4">
                            <span
                                className={`text-sm ${w.max_units && (units > w.max_units || units < (w.min_units ?? 0)) ? 'text-status-critical' : ''}`}
                            >
                                {chosen.length} courses ·{' '}
                                <strong>{units}</strong> units
                            </span>
                            <Button
                                disabled={!open}
                                onClick={() =>
                                    router.post(
                                        `/learn/registration?semester=${semester?.id}`,
                                        { courses: chosen },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Save registration
                            </Button>
                        </div>
                    </>
                )}

                {pending.length > 0 && (
                    <section className="grid gap-3">
                        <p className="eyebrow">Awaiting approval</p>
                        <Table head={['Student', 'Course', 'Type', '']}>
                            {pending.map((p) => (
                                <tr key={p.id}>
                                    <Td>
                                        {p.student}{' '}
                                        <span className="block text-xs text-muted-foreground">
                                            {p.identifier}
                                        </span>
                                    </Td>
                                    <Td>{p.course}</Td>
                                    <Td>
                                        <Status value={p.category} />
                                    </Td>
                                    <Td className="flex gap-2">
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    `/learn/enrolments/${p.id}/decide`,
                                                    { approve: true },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Approve
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => {
                                                const note = prompt(
                                                    'Reason for rejecting?',
                                                );

                                                if (note) {
                                                    router.post(
                                                        `/learn/enrolments/${p.id}/decide`,
                                                        {
                                                            approve: false,
                                                            note,
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }
                                            }}
                                        >
                                            Reject
                                        </Button>
                                    </Td>
                                </tr>
                            ))}
                        </Table>
                    </section>
                )}

                {canManage && (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <form
                            className="surface grid content-start gap-3 p-5"
                            onSubmit={(e) => {
                                e.preventDefault();
                                win.post('/learn/registration/window', {
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <p className="eyebrow">
                                Registration window · {semester?.label}
                            </p>
                            <div className="grid grid-cols-3 gap-2 text-xs">
                                <label className="grid gap-1">
                                    Opens
                                    <input
                                        type="datetime-local"
                                        className={inputClass}
                                        value={win.data.opens_at}
                                        onChange={(e) =>
                                            win.setData(
                                                'opens_at',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                </label>
                                <label className="grid gap-1">
                                    Closes
                                    <input
                                        type="datetime-local"
                                        className={inputClass}
                                        value={win.data.closes_at}
                                        onChange={(e) =>
                                            win.setData(
                                                'closes_at',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                </label>
                                <label className="grid gap-1">
                                    Late until
                                    <input
                                        type="datetime-local"
                                        className={inputClass}
                                        value={win.data.late_closes_at}
                                        onChange={(e) =>
                                            win.setData(
                                                'late_closes_at',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </label>
                                <label className="grid gap-1">
                                    Min units
                                    <input
                                        type="number"
                                        className={inputClass}
                                        value={win.data.min_units}
                                        onChange={(e) =>
                                            win.setData(
                                                'min_units',
                                                Number(e.target.value),
                                            )
                                        }
                                    />
                                </label>
                                <label className="grid gap-1">
                                    Max units
                                    <input
                                        type="number"
                                        className={inputClass}
                                        value={win.data.max_units}
                                        onChange={(e) =>
                                            win.setData(
                                                'max_units',
                                                Number(e.target.value),
                                            )
                                        }
                                    />
                                </label>
                                <label className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked={win.data.requires_approval}
                                        onChange={(e) =>
                                            win.setData(
                                                'requires_approval',
                                                e.target.checked,
                                            )
                                        }
                                    />{' '}
                                    Adviser approval
                                </label>
                            </div>
                            {Object.values(win.errors).length > 0 && (
                                <p className="text-sm text-status-critical">
                                    {Object.values(win.errors).join(' ')}
                                </p>
                            )}
                            <Button size="sm" className="w-fit">
                                Save window
                            </Button>
                        </form>
                        <form
                            className="surface grid content-start gap-3 p-5"
                            onSubmit={(e) => {
                                e.preventDefault();
                                ov.post(
                                    `/learn/registration/override?semester=${semester?.id}`,
                                    { preserveScroll: true },
                                );
                            }}
                        >
                            <p className="eyebrow">
                                Authorised override (late, corrections, limits)
                            </p>
                            <input
                                className={inputClass}
                                placeholder="Matric number"
                                value={ov.data.identifier}
                                onChange={(e) =>
                                    ov.setData('identifier', e.target.value)
                                }
                                required
                            />
                            <select
                                multiple
                                className={`${inputClass} h-32`}
                                value={ov.data.courses.map(String)}
                                onChange={(e) =>
                                    ov.setData(
                                        'courses',
                                        Array.from(
                                            e.target.selectedOptions,
                                        ).map((o) => Number(o.value)),
                                    )
                                }
                            >
                                {catalogue.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.code} · {c.title}
                                    </option>
                                ))}
                            </select>
                            <input
                                className={inputClass}
                                placeholder="Reason (recorded in the audit log)"
                                value={ov.data.reason}
                                onChange={(e) =>
                                    ov.setData('reason', e.target.value)
                                }
                                required
                            />
                            <Button size="sm" className="w-fit">
                                Apply override
                            </Button>
                        </form>
                    </div>
                )}
            </div>
        </>
    );
}
