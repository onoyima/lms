import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Download } from 'lucide-react';
import { useMemo, useState } from 'react';
import { formatDateTime, inputClass, PageHeader, Stat, Table, Td } from '@/components/platform/ui';

type Item = { id: number; title: string; type: string; section: string | null; visible: boolean; opened: number; completed: number; views: number; downloads: number };
type Row = { id: number; name: string; identifier: string | null; opened: number; completed: number; handed_in: number; percent: number; last_seen: string | null; items: number[]; done: number[] };

const TYPE: Record<string, string> = { file: 'File', page: 'Page', video: 'Video', link: 'Link', assignment: 'Assignment', quiz: 'Quiz / CA', forum: 'Discussion', live: 'Live class', audio: 'Audio' };

/** Who has studied what in one course: per student and per material. */
export default function Progress({ offering, items, rows, enrolled }: { offering: { id: number; code: string; title: string; label: string }; items: Item[]; rows: Row[]; enrolled: number }) {
    const [view, setView] = useState<'students' | 'materials'>('students');
    const [q, setQ] = useState('');
    const [open, setOpen] = useState<number | null>(null);
    const shown = useMemo(() => rows.filter((r) => `${r.name} ${r.identifier ?? ''}`.toLowerCase().includes(q.toLowerCase())), [rows, q]);
    const visible = items.filter((i) => i.visible).length;
    const never = rows.filter((r) => r.opened === 0).length;
    const avg = rows.length ? Math.round(rows.reduce((a, r) => a + r.percent, 0) / rows.length) : 0;

    return (
        <>
            <Head title={`Who studied what · ${offering.code}`} />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <Link href={`/learn/courses/${offering.id}`} className="flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-brand">
                    <ArrowLeft className="size-4" /> {offering.title}
                </Link>
                <PageHeader
                    title="Who studied what"
                    lead={`${offering.label}: which students opened and finished each material, and when they were last in the course.`}
                    actions={
                        <a href={`/learn/courses/${offering.id}/progress/export`} className="flex items-center gap-1 rounded-xl border border-border px-3 py-2 text-sm">
                            <Download className="size-4" /> Export CSV
                        </a>
                    }
                />
                <div className="grid gap-4 sm:grid-cols-4">
                    <Stat label="Students" value={enrolled} />
                    <Stat label="Materials and activities" value={visible} />
                    <Stat label="Average opened" value={`${avg}%`} />
                    <Stat label="Not opened anything" value={never} tone={never ? 'warning' : 'good'} />
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <div className="inline-flex rounded-xl border p-1 text-sm" role="tablist">
                        {(['students', 'materials'] as const).map((v) => (
                            <button key={v} role="tab" aria-selected={view === v} onClick={() => setView(v)}
                                className={`rounded-lg px-3 py-1.5 ${view === v ? 'bg-brand text-brand-foreground' : 'text-muted-foreground hover:text-foreground'}`}>
                                {v === 'students' ? 'By student' : 'By material'}
                            </button>
                        ))}
                    </div>
                    {view === 'students' && (
                        <input className={`${inputClass} max-w-xs`} placeholder="Find a student" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Find a student" />
                    )}
                </div>

                {view === 'students' ? (
                    <div className="surface overflow-x-auto">
                        <Table head={['Student', 'Opened', 'Finished', 'Handed in', 'Last in the course', '']}>
                            {shown.map((r) => (
                                <tr key={r.id} className="border-b border-border/60 align-top">
                                    <Td>
                                        <span className="font-medium">{r.name}</span>
                                        <span className="block text-xs text-muted-foreground">{r.identifier}</span>
                                        {open === r.id && (
                                            <ul className="mt-2 grid gap-0.5 text-xs">
                                                {items.filter((i) => i.visible).map((i) => (
                                                    <li key={i.id} className={r.done.includes(i.id) ? 'text-status-success' : r.items.includes(i.id) ? '' : 'text-muted-foreground'}>
                                                        {r.done.includes(i.id) ? '✓ finished' : r.items.includes(i.id) ? '• opened' : '– not opened'} · {i.title}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </Td>
                                    <Td>
                                        <span className="tabular-nums">{r.opened} / {visible}</span>
                                        <span className="mt-1 block h-1.5 w-24 overflow-hidden rounded-full bg-muted" aria-hidden>
                                            <span className="block h-full rounded-full bg-brand" style={{ width: `${r.percent}%` }} />
                                        </span>
                                    </Td>
                                    <Td className="tabular-nums">{r.completed}</Td>
                                    <Td className="tabular-nums">{r.handed_in}</Td>
                                    <Td>{r.last_seen ? formatDateTime(r.last_seen) : <span className="text-status-warning">Never</span>}</Td>
                                    <Td>
                                        <button className="text-xs text-brand hover:underline" onClick={() => setOpen(open === r.id ? null : r.id)} aria-expanded={open === r.id}>
                                            {open === r.id ? 'Hide' : 'Details'}
                                        </button>
                                    </Td>
                                </tr>
                            ))}
                        </Table>
                        {shown.length === 0 && <p className="p-6 text-sm text-muted-foreground">No students{q ? ' match' : ' registered yet'}.</p>}
                    </div>
                ) : (
                    <div className="surface overflow-x-auto">
                        <Table head={['Material or activity', 'Kind', 'Opened by', 'Finished by', 'Views', 'Downloads', '']}>
                            {items.map((i) => (
                                <tr key={i.id} className="border-b border-border/60 align-top">
                                    <Td>
                                        <Link href={`/learn/items/${i.id}`} className="font-medium hover:text-brand">{i.title}</Link>
                                        <span className="block text-xs text-muted-foreground">{i.section}{i.visible ? '' : ' · hidden from students'}</span>
                                        {open === -i.id && (
                                            <p className="mt-2 text-xs text-muted-foreground">
                                                Not opened yet: {rows.filter((r) => !r.items.includes(i.id)).map((r) => r.name).join(', ') || 'everyone has opened it'}
                                            </p>
                                        )}
                                    </Td>
                                    <Td>{TYPE[i.type] ?? i.type}</Td>
                                    <Td className="tabular-nums">{i.opened} / {enrolled}</Td>
                                    <Td className="tabular-nums">{i.completed}</Td>
                                    <Td className="tabular-nums">{i.views}</Td>
                                    <Td className="tabular-nums">{i.downloads}</Td>
                                    <Td>
                                        <button className="text-xs text-brand hover:underline" onClick={() => setOpen(open === -i.id ? null : -i.id)} aria-expanded={open === -i.id}>
                                            {open === -i.id ? 'Hide' : 'Who has not'}
                                        </button>
                                    </Td>
                                </tr>
                            ))}
                        </Table>
                    </div>
                )}
            </div>
        </>
    );
}
