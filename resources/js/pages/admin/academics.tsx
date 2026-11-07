import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Flash, formatDateTime, inputClass, PageHeader, Status, Table, Td, textareaClass } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Unit = { id: number; name: string; code: string; faculty_id?: number; department_id?: number; duration_years?: number };
type Course = { id: number; code: string; title: string; credit_units: number; level: number | null; department_id: number | null; description: string | null; learning_outcomes: string | null; active: boolean; prerequisites: number[] };
type Semester = { id: number; name: string; is_current: boolean; status: string; starts_on: string | null; ends_on: string | null; closed_at: string | null; offerings: number };
type Session = { id: number; name: string; is_current: boolean; status: string; semesters: Semester[] };
type Offering = { id: number; course: { id: number; code: string; title: string; credit_units: number }; capacity: number | null; status: string; review_status: string; students: number; staff: { id: number; name: string; role: string }[]; streams: Record<string, number> };
type Props = {
    tab: string; faculties: Unit[]; departments: Unit[]; programmes: Unit[]; courses: Course[];
    plan: { programme_id: number; course_id: number; level: number; category: string }[];
    sessions: Session[]; semester: number | null; offerings: Offering[]; staff: { id: number; name: string }[];
};

const TABS = [['structure', 'Faculties & departments'], ['courses', 'Courses'], ['plans', 'Programme courses'], ['calendar', 'Sessions & semesters'], ['offerings', 'Course offerings']] as const;
const post = (url: string, data: Record<string, unknown>) => router.post(url, data as never, { preserveScroll: true });

export default function Academics(props: Props) {
    const [tab, setTab] = useState(props.tab);

    return (
        <>
            <Head title="Academic structure" />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader title="Academic structure" lead="Faculties, departments, programmes, courses and the academic calendar. Records with history are never deleted — deactivate instead." />
                <Flash />
                <div className="flex flex-wrap gap-2">
                    {TABS.map(([k, l]) => <button key={k} onClick={() => setTab(k)} className={`rounded-xl border px-4 py-2 text-sm ${tab === k ? 'border-brand bg-brand-soft' : 'border-border'}`}>{l}</button>)}
                </div>
                {tab === 'structure' && <Structure {...props} />}
                {tab === 'courses' && <Courses {...props} />}
                {tab === 'plans' && <Plans {...props} />}
                {tab === 'calendar' && <Calendar {...props} />}
                {tab === 'offerings' && <Offerings {...props} />}
            </div>
        </>
    );
}

function UnitForm({ kind, parents, parentKey, initial, onDone }: { kind: string; parents?: Unit[]; parentKey?: string; initial?: Unit; onDone?: () => void }) {
    const [d, setD] = useState<Record<string, string | number>>({ name: initial?.name ?? '', code: initial?.code ?? '', ...(parentKey ? { [parentKey]: (initial as never)?.[parentKey] ?? parents?.[0]?.id ?? '' } : {}), ...(kind === 'programme' ? { duration_years: initial?.duration_years ?? 4 } : {}) });

    return (
        <form className="grid gap-2 md:grid-cols-[1fr_120px_1fr_auto]" onSubmit={(e) => {
 e.preventDefault(); router.post(`/admin/academics/units/${kind}`, { ...d, id: initial?.id } as never, { preserveScroll: true, onSuccess: () => {
 setD({ ...d, name: '', code: '' }); onDone?.(); 
} }); 
}}>
            <input className={inputClass} placeholder={`${kind[0].toUpperCase() + kind.slice(1)} name`} value={d.name} onChange={(e) => setD({ ...d, name: e.target.value })} required />
            <input className={`${inputClass} uppercase`} placeholder="Code" value={d.code} onChange={(e) => setD({ ...d, code: e.target.value })} required />
            {parentKey ? (
                <select className={inputClass} value={d[parentKey]} onChange={(e) => setD({ ...d, [parentKey]: Number(e.target.value) })}>{parents?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select>
            ) : <span />}
            <Button size="sm">{initial ? 'Save' : 'Add'}</Button>
        </form>
    );
}

function Structure({ faculties, departments, programmes }: Props) {
    return (
        <div className="grid gap-6">
            <section className="surface grid gap-4 p-6">
                <p className="eyebrow">Faculties</p>
                <UnitForm kind="faculty" />
                <ul className="grid gap-1 text-sm">{faculties.map((f) => <li key={f.id} className="flex justify-between rounded-lg px-2 py-1 hover:bg-muted"><span><span className="font-mono text-brand">{f.code}</span> {f.name}</span><button className="text-xs text-muted-foreground" onClick={() => confirm('Delete?') && router.delete(`/admin/academics/units/faculty/${f.id}`, { preserveScroll: true })}>Delete</button></li>)}</ul>
            </section>
            {faculties.length > 0 && (
                <section className="surface grid gap-4 p-6">
                    <p className="eyebrow">Departments</p>
                    <UnitForm kind="department" parents={faculties} parentKey="faculty_id" />
                    <Table head={['Code', 'Department', 'Faculty', '']}>{departments.map((d) => <tr key={d.id}><Td className="font-mono">{d.code}</Td><Td>{d.name}</Td><Td>{faculties.find((f) => f.id === d.faculty_id)?.name}</Td><Td><button className="text-xs text-muted-foreground" onClick={() => confirm('Delete?') && router.delete(`/admin/academics/units/department/${d.id}`, { preserveScroll: true })}>Delete</button></Td></tr>)}</Table>
                </section>
            )}
            {departments.length > 0 && (
                <section className="surface grid gap-4 p-6">
                    <p className="eyebrow">Programmes</p>
                    <UnitForm kind="programme" parents={departments} parentKey="department_id" />
                    <Table head={['Code', 'Programme', 'Department', 'Years']}>{programmes.map((p) => <tr key={p.id}><Td className="font-mono">{p.code}</Td><Td>{p.name}</Td><Td>{departments.find((d) => d.id === p.department_id)?.name}</Td><Td>{p.duration_years}</Td></tr>)}</Table>
                </section>
            )}
        </div>
    );
}

function Courses({ courses, departments }: Props) {
    const [editing, setEditing] = useState<Partial<Course> | null>(null);
    const [q, setQ] = useState('');
    const shown = courses.filter((c) => `${c.code} ${c.title}`.toLowerCase().includes(q.toLowerCase()));

    return (
        <div className="grid gap-4">
            <div className="flex flex-wrap gap-2">
                <input className={`${inputClass} max-w-sm`} placeholder="Search courses" value={q} onChange={(e) => setQ(e.target.value)} />
                <Button onClick={() => setEditing({ code: '', title: '', credit_units: 3, level: 100, active: true, prerequisites: [] })}>New course</Button>
                <label className="cursor-pointer rounded-md border px-3 py-2 text-sm" title="CSV with columns: code, title, credit_units, level, department_code">
                    Import from CSV
                    <input type="file" accept=".csv,text/csv" className="sr-only" onChange={(e) => {
                        const f = e.target.files?.[0];

                        if (f) {
                            router.post('/admin/academics/courses/import', { file: f }, { forceFormData: true, preserveScroll: true });
                        }

                        e.target.value = '';
                    }} />
                </label>
                <a className="self-center text-sm text-brand underline" href="/templates/courses">CSV template</a>
            </div>
            {editing && (
                <form className="surface grid gap-3 p-6" onSubmit={(e) => {
 e.preventDefault(); router.post('/admin/academics/courses', editing as never, { preserveScroll: true, onSuccess: () => setEditing(null) }); 
}}>
                    <div className="grid gap-2 md:grid-cols-[140px_1fr_100px_100px_1fr]">
                        <input className={`${inputClass} uppercase`} placeholder="CHM 101" value={editing.code} onChange={(e) => setEditing({ ...editing, code: e.target.value })} required />
                        <input className={inputClass} placeholder="Title" value={editing.title} onChange={(e) => setEditing({ ...editing, title: e.target.value })} required />
                        <input type="number" className={inputClass} value={editing.credit_units} onChange={(e) => setEditing({ ...editing, credit_units: Number(e.target.value) })} aria-label="Units" />
                        <input type="number" className={inputClass} value={editing.level ?? ''} onChange={(e) => setEditing({ ...editing, level: Number(e.target.value) || null })} aria-label="Level" />
                        <select className={inputClass} value={editing.department_id ?? ''} onChange={(e) => setEditing({ ...editing, department_id: Number(e.target.value) || null })}><option value="">Department…</option>{departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}</select>
                    </div>
                    <textarea className={textareaClass} placeholder="Description" value={editing.description ?? ''} onChange={(e) => setEditing({ ...editing, description: e.target.value })} />
                    <textarea className={textareaClass} placeholder="Learning outcomes (one per line)" value={editing.learning_outcomes ?? ''} onChange={(e) => setEditing({ ...editing, learning_outcomes: e.target.value })} />
                    <label className="grid gap-1 text-xs">Prerequisites (hold Ctrl to pick several)
                        <select multiple className={`${inputClass} h-28`} value={(editing.prerequisites ?? []).map(String)} onChange={(e) => setEditing({ ...editing, prerequisites: Array.from(e.target.selectedOptions).map((o) => Number(o.value)) })}>
                            {courses.filter((c) => c.id !== editing.id).map((c) => <option key={c.id} value={c.id}>{c.code} · {c.title}</option>)}
                        </select>
                    </label>
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={editing.active ?? true} onChange={(e) => setEditing({ ...editing, active: e.target.checked })} /> Active (offered)</label>
                    <div className="flex gap-2"><Button>Save course</Button><Button type="button" variant="ghost" onClick={() => setEditing(null)}>Cancel</Button></div>
                </form>
            )}
            <Table head={['Code', 'Title', 'Units', 'Level', 'Department', 'Needs', '']}>
                {shown.map((c) => (
                    <tr key={c.id} className={c.active ? '' : 'opacity-50'}>
                        <Td className="font-mono text-brand">{c.code}</Td><Td>{c.title}</Td><Td>{c.credit_units}</Td><Td>{c.level}</Td>
                        <Td>{departments.find((d) => d.id === c.department_id)?.name}</Td>
                        <Td className="text-xs">{c.prerequisites.map((p) => courses.find((x) => x.id === p)?.code).join(', ')}</Td>
                        <Td><Button size="sm" variant="outline" onClick={() => setEditing({ ...c })}>Edit</Button></Td>
                    </tr>
                ))}
            </Table>
        </div>
    );
}

function Plans({ programmes, courses, plan }: Props) {
    const [programme, setProgramme] = useState<number | null>(programmes[0]?.id ?? null);
    const initial = useMemo(() => plan.filter((p) => p.programme_id === programme), [plan, programme]);
    const [rows, setRows] = useState(initial);
    const [prev, setPrev] = useState(programme);

    if (prev !== programme) {
        setPrev(programme);
        setRows(initial);
    }

    if (!programmes.length) {
        return <p className="text-sm text-muted-foreground">Add a programme first.</p>;
    }

    return (
        <div className="surface grid gap-4 p-6">
            <select className={`${inputClass} max-w-md`} value={programme ?? ''} onChange={(e) => setProgramme(Number(e.target.value))}>{programmes.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select>
            {[100, 200, 300, 400, 500, 600].map((level) => {
                const at = rows.filter((r) => r.level === level);

                return (
                    <div key={level} className="grid gap-2">
                        <p className="eyebrow">{level} level · {at.reduce((n, r) => n + (courses.find((c) => c.id === r.course_id)?.credit_units ?? 0), 0)} units</p>
                        {at.map((r) => (
                            <div key={r.course_id} className="flex items-center gap-2 text-sm">
                                <span className="w-24 font-mono text-brand">{courses.find((c) => c.id === r.course_id)?.code}</span>
                                <span className="flex-1">{courses.find((c) => c.id === r.course_id)?.title}</span>
                                <select className={`${inputClass} w-32`} value={r.category} onChange={(e) => setRows(rows.map((x) => (x.course_id === r.course_id ? { ...x, category: e.target.value } : x)))}><option value="core">Compulsory</option><option value="elective">Elective</option></select>
                                <button className="text-xs text-muted-foreground" onClick={() => setRows(rows.filter((x) => x.course_id !== r.course_id))}>Remove</button>
                            </div>
                        ))}
                        <select className={`${inputClass} max-w-md`} value="" onChange={(e) => e.target.value && setRows([...rows, { programme_id: programme!, course_id: Number(e.target.value), level, category: 'core' }])}>
                            <option value="">Add a course at {level} level…</option>
                            {courses.filter((c) => c.active && !rows.some((r) => r.course_id === c.id)).map((c) => <option key={c.id} value={c.id}>{c.code} · {c.title}</option>)}
                        </select>
                    </div>
                );
            })}
            <Button className="w-fit" onClick={() => post('/admin/academics/plan', { programme_id: programme, rows: rows.map(({ course_id, level, category }) => ({ course_id, level, category })) })}>Save programme courses</Button>
        </div>
    );
}

function Calendar({ sessions }: Props) {
    const [name, setName] = useState('');
    const all = sessions.flatMap((s) => s.semesters.map((m) => ({ ...m, session: s.name })));

    return (
        <div className="grid gap-6">
            <form className="surface flex flex-wrap items-end gap-2 p-6" onSubmit={(e) => {
 e.preventDefault(); post('/admin/academics/sessions', { name, semesters: ['First semester', 'Second semester'] }); setName(''); 
}}>
                <label className="grid gap-1 text-xs">New academic session<input className={inputClass} placeholder="2027/2028" value={name} onChange={(e) => setName(e.target.value)} required pattern="\d{4}/\d{4}" /></label>
                <Button>Add with two semesters</Button>
            </form>
            {sessions.map((s) => (
                <section key={s.id} className="surface grid gap-3 p-6">
                    <p className="text-lg font-medium">{s.name} {s.is_current && <Status value="active" />}</p>
                    {s.semesters.map((m) => (
                        <div key={m.id} className="grid gap-2 rounded-xl border border-border p-4 md:grid-cols-[1fr_auto]">
                            <div>
                                <p className="font-medium">{m.name} <Status value={m.is_current ? 'current' : m.status} /></p>
                                <p className="text-xs text-muted-foreground">{m.offerings} courses{m.closed_at && ` · closed ${formatDateTime(m.closed_at)}`}</p>
                                <div className="mt-2 flex gap-2 text-xs">
                                    <input type="date" className={`${inputClass} w-40`} defaultValue={m.starts_on ?? ''} onBlur={(e) => post(`/admin/academics/semesters/${m.id}`, { action: 'dates', starts_on: e.target.value || null, ends_on: m.ends_on })} aria-label="Starts" />
                                    <input type="date" className={`${inputClass} w-40`} defaultValue={m.ends_on ?? ''} onBlur={(e) => post(`/admin/academics/semesters/${m.id}`, { action: 'dates', starts_on: m.starts_on, ends_on: e.target.value || null })} aria-label="Ends" />
                                </div>
                            </div>
                            <div className="flex flex-wrap items-start gap-2">
                                {m.status !== 'closed' && !m.is_current && <Button size="sm" onClick={() => confirm(`Make ${s.name} ${m.name} the current semester?`) && post(`/admin/academics/semesters/${m.id}`, { action: 'activate' })}>Make current</Button>}
                                {m.status !== 'closed' && (
                                    <select className={`${inputClass} w-52`} value="" onChange={(e) => {
                                        if (e.target.value && confirm('Carry over courses and lecturers from that semester? Choose OK, then you will be asked about content.')) {
                                            post(`/admin/academics/semesters/${m.id}`, { action: 'rollover', from: Number(e.target.value), copy_content: confirm('Also copy course pages, materials and quizzes?') });
                                        }
                                    }}>
                                        <option value="">Roll over from…</option>
                                        {all.filter((x) => x.id !== m.id).map((x) => <option key={x.id} value={x.id}>{x.session} {x.name}</option>)}
                                    </select>
                                )}
                                {m.status !== 'closed' && <Button size="sm" variant="outline" onClick={() => confirm('Close this semester? Its courses are archived and registration stops.') && post(`/admin/academics/semesters/${m.id}`, { action: 'close' })}>Close</Button>}
                            </div>
                        </div>
                    ))}
                </section>
            ))}
        </div>
    );
}

/** Act on many courses at once (LMS 173). */
function BulkBar({ ids, staff, onDone }: { ids: number[]; staff: Props['staff']; onDone: () => void }) {
    const [action, setAction] = useState('status');
    const [status, setStatus] = useState('open');
    const [capacity, setCapacity] = useState('');
    const [user, setUser] = useState('');
    const [role, setRole] = useState('lecturer');
    const run = () => router.post('/admin/academics/offerings/bulk', { ids, action, status, capacity: capacity ? Number(capacity) : null, user_id: user ? Number(user) : null, role } as never, { preserveScroll: true, onSuccess: onDone });

    return (
        <div className="surface flex flex-wrap items-center gap-2 p-3 text-sm" role="region" aria-label="Bulk actions">
            <span className="font-medium">{ids.length} selected</span>
            <select aria-label="Action" className={`${inputClass} w-48`} value={action} onChange={(e) => setAction(e.target.value)}>
                <option value="status">Set status</option><option value="capacity">Set capacity</option><option value="add_lecturer">Add a lecturer</option>
                <option value="remove_lecturer">Remove a lecturer</option><option value="copy_content">Copy content from last time</option>
            </select>
            {action === 'status' && <select aria-label="Status" className={`${inputClass} w-36`} value={status} onChange={(e) => setStatus(e.target.value)}>{['draft', 'open', 'closed', 'archived'].map((x) => <option key={x}>{x}</option>)}</select>}
            {action === 'capacity' && <input aria-label="Capacity" type="number" min={1} className={`${inputClass} w-28`} value={capacity} onChange={(e) => setCapacity(e.target.value)} placeholder="no limit" />}
            {(action === 'add_lecturer' || action === 'remove_lecturer') && (
                <select aria-label="Lecturer" className={`${inputClass} w-56`} value={user} onChange={(e) => setUser(e.target.value)}><option value="">Choose…</option>{staff.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select>
            )}
            {action === 'add_lecturer' && <select aria-label="Role" className={`${inputClass} w-36`} value={role} onChange={(e) => setRole(e.target.value)}><option>coordinator</option><option>lecturer</option><option>assistant</option></select>}
            <Button size="sm" disabled={(action.endsWith('lecturer') && !user)} onClick={() => confirm(`Apply to ${ids.length} course(s)?`) && run()}>Apply</Button>
        </div>
    );
}

function Offerings({ sessions, semester, offerings, courses, staff }: Props) {
    const [picked, setPicked] = useState<number[]>([]);
    const [course, setCourse] = useState<number | ''>('');
    const [editing, setEditing] = useState<Offering | null>(null);
    const semesters = sessions.flatMap((s) => s.semesters.map((m) => ({ id: m.id, label: `${s.name} ${m.name}` })));

    return (
        <div className="grid gap-4">
            <div className="flex flex-wrap gap-2">
                <select className={`${inputClass} max-w-xs`} value={semester ?? ''} onChange={(e) => router.get('/admin/academics', { tab: 'offerings', semester: e.target.value }, { preserveState: false })}>{semesters.map((s) => <option key={s.id} value={s.id}>{s.label}</option>)}</select>
                <select className={`${inputClass} max-w-sm`} value={course} onChange={(e) => setCourse(Number(e.target.value) || '')}><option value="">Offer a course this semester…</option>{courses.filter((c) => c.active && !offerings.some((o) => o.course.id === c.id)).map((c) => <option key={c.id} value={c.id}>{c.code} · {c.title}</option>)}</select>
                <Button disabled={!course || !semester} onClick={() => {
 post('/admin/academics/offerings', { course_id: course, semester_id: semester, status: 'open' }); setCourse(''); 
}}>Add</Button>
            </div>
            {picked.length > 0 && <BulkBar ids={picked} staff={staff} onDone={() => setPicked([])} />}
            <Table head={[<input key="all" type="checkbox" aria-label="Select all" checked={offerings.length > 0 && picked.length === offerings.length} onChange={(e) => setPicked(e.target.checked ? offerings.map((o) => o.id) : [])} />, 'Course', 'Lecturers', 'Students', 'Capacity', 'Streams', 'Status', '']}>
                {offerings.map((o) => (
                    <tr key={o.id}>
                        <Td><input type="checkbox" aria-label={`Select ${o.course.code}`} checked={picked.includes(o.id)} onChange={(e) => setPicked(e.target.checked ? [...picked, o.id] : picked.filter((x) => x !== o.id))} /></Td>
                        <Td><span className="font-mono text-brand">{o.course.code}</span> <span className="block text-xs">{o.course.title}</span></Td>
                        <Td className="text-xs">{o.staff.map((s) => `${s.name} (${s.role})`).join(', ') || <span className="text-status-warning">none</span>}</Td>
                        <Td>{o.students}</Td><Td>{o.capacity ?? '—'}</Td>
                        <Td className="text-xs">{Object.entries(o.streams).map(([k, n]) => `${k}:${n}`).join(' ') || '—'}</Td>
                        <Td><Status value={o.status} /></Td>
                        <Td className="flex gap-1">
                            <Button size="sm" variant="outline" onClick={() => setEditing(o)}>Manage</Button>
                            <Button size="sm" variant="ghost" onClick={() => {
 const size = prompt('Students per stream?', '150');

 if (size) {
post(`/admin/academics/offerings/${o.id}/streams`, { size: Number(size) });
} 
}}>Streams</Button>
                        </Td>
                    </tr>
                ))}
            </Table>
            {editing && <OfferingDialog offering={editing} semester={semester!} staff={staff} onClose={() => setEditing(null)} />}
        </div>
    );
}

function OfferingDialog({ offering, semester, staff, onClose }: { offering: Offering; semester: number; staff: { id: number; name: string }[]; onClose: () => void }) {
    const [d, setD] = useState({ capacity: offering.capacity ?? '', status: offering.status, staff: offering.staff.map((s) => ({ user_id: s.id, role: s.role })) });

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="surface w-full max-w-xl bg-background p-8">
                <p className="eyebrow">{offering.course.code}</p>
                <h3 className="text-lg font-medium">{offering.course.title}</h3>
                <div className="mt-4 grid grid-cols-2 gap-2">
                    <label className="grid gap-1 text-xs">Capacity<input type="number" className={inputClass} value={d.capacity} onChange={(e) => setD({ ...d, capacity: e.target.value })} /></label>
                    <label className="grid gap-1 text-xs">Status<select className={inputClass} value={d.status} onChange={(e) => setD({ ...d, status: e.target.value })}>{['draft', 'open', 'closed', 'archived'].map((s) => <option key={s}>{s}</option>)}</select></label>
                </div>
                <p className="mt-4 text-sm font-medium">Teaching staff</p>
                {d.staff.map((s, i) => (
                    <div key={i} className="mt-2 grid grid-cols-[1fr_140px_auto] gap-2">
                        <select className={inputClass} value={s.user_id} onChange={(e) => setD({ ...d, staff: d.staff.map((x, j) => (j === i ? { ...x, user_id: Number(e.target.value) } : x)) })}>{staff.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select>
                        <select className={inputClass} value={s.role} onChange={(e) => setD({ ...d, staff: d.staff.map((x, j) => (j === i ? { ...x, role: e.target.value } : x)) })}><option value="coordinator">Coordinator</option><option value="lecturer">Lecturer</option><option value="assistant">Teaching assistant</option></select>
                        <button className="text-xs text-muted-foreground" onClick={() => setD({ ...d, staff: d.staff.filter((_, j) => j !== i) })}>Remove</button>
                    </div>
                ))}
                <Button size="sm" variant="outline" className="mt-2" onClick={() => staff[0] && setD({ ...d, staff: [...d.staff, { user_id: staff[0].id, role: 'lecturer' }] })}>Add staff</Button>
                <div className="mt-6 flex gap-2">
                    <Button onClick={() => router.post('/admin/academics/offerings', { id: offering.id, course_id: offering.course.id, semester_id: semester, capacity: d.capacity || null, status: d.status, staff: d.staff } as never, { preserveScroll: true, onSuccess: onClose })}>Save</Button>
                    <Button variant="ghost" onClick={onClose}>Close</Button>
                </div>
            </div>
        </div>
    );
}
