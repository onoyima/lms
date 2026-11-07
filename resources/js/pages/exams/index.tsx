import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { Field, inputClass, PageHeader, Status, Table, Td } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/types';

type Row = { id: number; title: string; type: string; category: string; status: string; course: string | null; duration: number; sessions: number; attempts: number; results: number };

const types = [
    { value: 'cbt', label: 'CBT (objective, auto-marked)' },
    { value: 'essay', label: 'Essay / theory' },
    { value: 'mixed', label: 'Mixed (objective + essay)' },
    { value: 'practical', label: 'Practical (checklist, scored by examiners)' },
    { value: 'viva', label: 'Viva (oral, panel score)' },
    { value: 'research', label: 'Research (project or thesis, examiner score)' },
];

export default function Exams({ exams, filters }: { exams: Paginated<Row>; filters: { type?: string; status?: string; q?: string } }) {
    const { flash } = usePage().props;
    const [open, setOpen] = useState(false);
    const form = useForm({ title: '', type: 'cbt', category: 'exam', duration_minutes: 60, total_mark: 100 as number | '' });

    return (
        <>
            <Head title="Exams" />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader
                    title="Every format,"
                    accent="one engine."
                    lead="CBT, essay, mixed, practical checklists and vivas — all with the same scheduling, approval, marking and results workflow."
                    actions={
                        <Button onClick={() => setOpen(!open)}>
                            <Plus className="size-4" /> New exam
                        </Button>
                    }
                />
                {flash?.success && <p className="text-sm text-status-good">{flash.success}</p>}

                {open && (
                    <form
                        className="surface grid gap-4 p-6 md:grid-cols-5"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/exams');
                        }}
                    >
                        <div className="md:col-span-2">
                            <Field label="Title" error={form.errors.title}>
                                <input className={inputClass} value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} placeholder="CHM 101 First Semester Exam" required />
                            </Field>
                        </div>
                        <Field label="Format" error={form.errors.type}>
                            <select className={inputClass} value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
                                {types.map((t) => (
                                    <option key={t.value} value={t.value}>
                                        {t.label}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Kind">
                            <select className={inputClass} value={form.data.category} onChange={(e) => form.setData('category', e.target.value)}>
                                {['exam', 'test', 'quiz', 'practice', 'mock'].map((c) => (
                                    <option key={c}>{c}</option>
                                ))}
                            </select>
                        </Field>
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Minutes">
                                <input type="number" min={1} className={inputClass} value={form.data.duration_minutes} onChange={(e) => form.setData('duration_minutes', Number(e.target.value))} />
                            </Field>
                            <Field label="Out of">
                                <input type="number" min={0} className={inputClass} value={form.data.total_mark} onChange={(e) => form.setData('total_mark', e.target.value === '' ? '' : Number(e.target.value))} />
                            </Field>
                        </div>
                        <div className="md:col-span-5">
                            <Button disabled={form.processing}>Create exam</Button>
                        </div>
                    </form>
                )}

                <div className="flex flex-wrap gap-2">
                    <input
                        className={`${inputClass} max-w-xs`}
                        placeholder="Search title"
                        defaultValue={filters.q}
                        onKeyDown={(e) => e.key === 'Enter' && router.get('/exams', { ...filters, q: (e.target as HTMLInputElement).value || undefined }, { preserveState: true })}
                    />
                    <select className={`${inputClass} max-w-[200px]`} value={filters.type ?? ''} onChange={(e) => router.get('/exams', { ...filters, type: e.target.value || undefined }, { preserveState: true })}>
                        <option value="">All formats</option>
                        {types.map((t) => (
                            <option key={t.value} value={t.value}>
                                {t.value}
                            </option>
                        ))}
                    </select>
                    <select className={`${inputClass} max-w-[200px]`} value={filters.status ?? ''} onChange={(e) => router.get('/exams', { ...filters, status: e.target.value || undefined }, { preserveState: true })}>
                        <option value="">All statuses</option>
                        {['draft', 'submitted', 'returned', 'scheduled', 'live', 'closed', 'results_published', 'archived'].map((s) => (
                            <option key={s} value={s}>
                                {s.replace('_', ' ')}
                            </option>
                        ))}
                    </select>
                </div>

                <Table head={['Exam', 'Format', 'Course', 'Status', 'Sittings', 'Attempts', 'Results']}>
                    {exams.data.map((e) => (
                        <tr key={e.id} className="hover:bg-muted/40">
                            <Td>
                                <Link href={`/exams/${e.id}`} className="font-medium hover:text-brand">
                                    {e.title}
                                </Link>
                                <div className="text-xs text-muted-foreground">
                                    {e.category} · {e.duration} min
                                </div>
                            </Td>
                            <Td className="font-mono text-xs">{e.type}</Td>
                            <Td className="font-mono text-xs">{e.course ?? '—'}</Td>
                            <Td>
                                <Status value={e.status} />
                            </Td>
                            <Td className="tabular-nums">{e.sessions}</Td>
                            <Td className="tabular-nums">{e.attempts}</Td>
                            <Td>
                                {e.results > 0 ? (
                                    <Link href={`/results/${e.id}`} className="text-brand">
                                        {e.results}
                                    </Link>
                                ) : (
                                    '—'
                                )}
                            </Td>
                        </tr>
                    ))}
                    {exams.data.length === 0 && (
                        <tr>
                            <Td className="py-10 text-center text-muted-foreground" >No exams yet.</Td>
                        </tr>
                    )}
                </Table>
            </div>
        </>
    );
}

Exams.layout = { breadcrumbs: [{ title: 'Exams', href: '/exams' }] };
