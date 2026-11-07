import { Head, Link, router, useForm } from '@inertiajs/react';
import { ShieldCheck, Upload, UserPlus } from 'lucide-react';
import { useState } from 'react';
import { Flash, formatDateTime, inputClass, PageHeader, Status, Table, Td } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';
import { useRoleLabel } from '@/lib/role-label';

type Placement = { faculty_id: number | null; department_id: number | null; title: string | null; designation: string | null } | null;
type Person = { id: number; name: string; email: string; identifier: string | null; type: string; status: string; roles: string[]; two_factor: boolean; placement: Placement };
type Unit = { id: number; name: string; faculty_id?: number };
type Props = {
    users: { data: Person[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    roles: string[];
    grants: { id: number; name: string; role: string; reason: string; expires_at: string }[];
    logins: { type: string; ip: string; created_at: string; name: string | null; data: string | null }[];
    filters: { q?: string; type?: string; role?: string };
    faculties: Unit[];
    departments: Unit[];
};

export default function People({ users, roles, grants, logins, filters, faculties, departments }: Props) {
    const label = useRoleLabel();
    const [tab, setTab] = useState<'people' | 'add' | 'import' | 'temporary' | 'sign-ins'>('people');
    const [editing, setEditing] = useState<Person | null>(null);
    const add = useForm({ name: '', email: '', identifier: '', user_type: 'staff', roles: [] as string[] });
    const imp = useForm<{ file: File | null }>({ file: null });
    const filter = (patch: Record<string, string>) => router.get('/admin/people', { ...filters, ...patch }, { preserveState: true, replace: true });

    return (
        <>
            <Head title="People" />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader title="People and roles" lead={`${users.total} accounts. Roles decide what each person can see and do; temporary roles end on their own.`} />
                <Flash />
                <div className="flex flex-wrap gap-2">
                    {(['people', 'add', 'import', 'temporary', 'sign-ins'] as const).map((t) => (
                        <button key={t} onClick={() => setTab(t)} className={`rounded-xl border px-4 py-2 text-sm capitalize ${tab === t ? 'border-brand bg-brand-soft' : 'border-border'}`}>{t === 'temporary' ? `Temporary roles (${grants.length})` : t.replace('-', ' ')}</button>
                    ))}
                </div>

                {tab === 'people' && (
                    <>
                        <div className="flex flex-wrap gap-2">
                            <input className={`${inputClass} max-w-sm`} placeholder="Search name, email or matric number" defaultValue={filters.q} onKeyDown={(e) => e.key === 'Enter' && filter({ q: (e.target as HTMLInputElement).value })} />
                            <select className={`${inputClass} max-w-40`} value={filters.type ?? ''} onChange={(e) => filter({ type: e.target.value })}><option value="">Everyone</option><option value="staff">Staff</option><option value="student">Students</option></select>
                            <select className={`${inputClass} max-w-48`} value={filters.role ?? ''} onChange={(e) => filter({ role: e.target.value })}><option value="">Any role</option>{roles.map((r) => <option key={r} value={r}>{label(r)}</option>)}</select>
                        </div>
                        <Table head={['Name', 'ID', 'Roles', 'Status', '']}>
                            {users.data.map((u) => (
                                <tr key={u.id}>
                                    <Td><span className="font-medium">{u.name}</span><span className="block text-xs text-muted-foreground">{u.email}</span></Td>
                                    <Td className="font-mono text-xs">{u.identifier ?? '—'}</Td>
                                    <Td><span className="flex flex-wrap gap-1">{u.roles.map((r) => <span key={r} className="rounded-full bg-muted px-2 py-0.5 text-[11px]">{label(r)}</span>)}{u.two_factor && <ShieldCheck className="size-4 text-status-good" aria-label="Two-factor on" />}</span></Td>
                                    <Td><Status value={u.status} /></Td>
                                    <Td><Button size="sm" variant="outline" onClick={() => setEditing(u)}>Manage</Button></Td>
                                </tr>
                            ))}
                        </Table>
                        {users.links.length > 3 && <div className="flex flex-wrap gap-1">{users.links.map((l, i) => l.url && <Link key={i} href={l.url} preserveState className={`rounded-lg px-3 py-1 text-sm ${l.active ? 'bg-brand text-brand-foreground' : 'border border-border'}`} dangerouslySetInnerHTML={{ __html: l.label }} />)}</div>}
                    </>
                )}

                {tab === 'add' && (
                    <form className="surface grid max-w-2xl gap-3 p-6" onSubmit={(e) => {
 e.preventDefault(); add.post('/admin/people', { onSuccess: () => add.reset() }); 
}}>
                        <p className="eyebrow flex items-center gap-2"><UserPlus className="size-3.5" /> New account</p>
                        <input className={inputClass} placeholder="Full name" value={add.data.name} onChange={(e) => add.setData('name', e.target.value)} required />
                        <input className={inputClass} placeholder="University email" type="email" value={add.data.email} onChange={(e) => add.setData('email', e.target.value)} required />
                        <div className="grid grid-cols-2 gap-2">
                            <input className={inputClass} placeholder="Staff or matric number" value={add.data.identifier} onChange={(e) => add.setData('identifier', e.target.value)} />
                            <select className={inputClass} value={add.data.user_type} onChange={(e) => add.setData('user_type', e.target.value)}><option value="staff">Staff</option><option value="student">Student</option></select>
                        </div>
                        <RolePicker roles={roles} value={add.data.roles} onChange={(v) => add.setData('roles', v)} />
                        {Object.values(add.errors).length > 0 && <p className="text-sm text-status-critical">{Object.values(add.errors).join(' ')}</p>}
                        <p className="text-xs text-muted-foreground">They get an email link to choose their own password. No passwords are sent or shown.</p>
                        <Button className="w-fit" disabled={add.processing}>Create account</Button>
                    </form>
                )}

                {tab === 'import' && (
                    <form className="surface grid max-w-2xl gap-3 p-6" onSubmit={(e) => {
 e.preventDefault(); imp.post('/admin/people/import', { forceFormData: true }); 
}}>
                        <p className="eyebrow flex items-center gap-2"><Upload className="size-3.5" /> Bulk import (CSV)</p>
                        <p className="text-sm text-muted-foreground">Columns: <code>name,email,identifier,type,roles</code>. Type is <code>staff</code> or <code>student</code>; separate several roles with <code>;</code>. Existing emails are updated, not duplicated. For daily student records, connect the student information system instead. <a className="text-brand underline" href="/templates/people">Download the template</a>.</p>
                        <input type="file" accept=".csv,text/csv" onChange={(e) => imp.setData('file', e.target.files?.[0] ?? null)} />
                        <Button className="w-fit" disabled={!imp.data.file || imp.processing}>Import</Button>
                    </form>
                )}

                {tab === 'temporary' && (
                    <Table head={['Person', 'Role', 'Reason', 'Ends', '']}>
                        {grants.map((g) => (
                            <tr key={g.id}><Td>{g.name}</Td><Td>{g.role}</Td><Td>{g.reason}</Td><Td>{formatDateTime(g.expires_at)}</Td>
                                <Td><Button size="sm" variant="outline" onClick={() => router.delete(`/admin/people/grants/${g.id}`, { preserveScroll: true })}>End now</Button></Td></tr>
                        ))}
                    </Table>
                )}

                {tab === 'sign-ins' && (
                    <Table head={['When', 'Who', 'Result', 'IP']}>
                        {logins.map((l, i) => <tr key={i}><Td>{formatDateTime(l.created_at)}</Td><Td>{l.name ?? (l.data ? JSON.parse(l.data).email : '—')}</Td><Td><Status value={l.type === 'login' ? 'approved' : l.type === 'login_locked' ? 'blocked' : 'withheld'} /> {l.type.replace('_', ' ')}</Td><Td className="font-mono text-xs">{l.ip}</Td></tr>)}
                    </Table>
                )}

                {editing && <Manage person={editing} roles={roles} faculties={faculties} departments={departments} onClose={() => setEditing(null)} />}
            </div>
        </>
    );
}

function RolePicker({ roles, value, onChange }: { roles: string[]; value: string[]; onChange: (v: string[]) => void }) {
    const label = useRoleLabel();

    return (
        <div className="flex flex-wrap gap-2">
            {roles.map((r) => (
                <button type="button" key={r} onClick={() => onChange(value.includes(r) ? value.filter((x) => x !== r) : [...value, r])}
                    className={`rounded-full border px-3 py-1 text-xs ${value.includes(r) ? 'border-brand bg-brand-soft' : 'border-border'}`}>{label(r)}</button>
            ))}
        </div>
    );
}

function Manage({ person, roles, faculties, departments, onClose }: { person: Person; roles: string[]; faculties: Unit[]; departments: Unit[]; onClose: () => void }) {
    const label = useRoleLabel();
    const [picked, setPicked] = useState(person.roles);
    const [place, setPlace] = useState({ faculty_id: person.placement?.faculty_id ?? '', department_id: person.placement?.department_id ?? '', title: person.placement?.title ?? '', designation: person.placement?.designation ?? '' });
    const del = useForm({ role: 'hod', expires_at: '', reason: '' });

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="surface max-h-[90vh] w-full max-w-xl overflow-y-auto bg-background p-8">
                <p className="eyebrow">Manage</p>
                <h3 className="mt-1 text-lg font-medium">{person.name}</h3>
                <p className="text-sm text-muted-foreground">{person.email}</p>
                <div className="mt-6 grid gap-3">
                    <p className="text-sm font-medium">Roles</p>
                    <RolePicker roles={roles} value={picked} onChange={setPicked} />
                    <Button size="sm" className="w-fit" onClick={() => router.put(`/admin/people/${person.id}`, { roles: picked }, { preserveScroll: true, onSuccess: onClose })}>Save roles</Button>
                </div>
                {person.type === 'staff' && (
                    <div className="mt-8 grid gap-2">
                        <p className="text-sm font-medium">Placement (decides what an HOD or Dean oversees)</p>
                        <div className="grid grid-cols-2 gap-2">
                            <select className={inputClass} value={place.faculty_id} onChange={(e) => setPlace({ ...place, faculty_id: e.target.value, department_id: '' })}><option value="">Faculty…</option>{faculties.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}</select>
                            <select className={inputClass} value={place.department_id} onChange={(e) => setPlace({ ...place, department_id: e.target.value })}><option value="">Department…</option>{departments.filter((d) => !place.faculty_id || String(d.faculty_id) === String(place.faculty_id)).map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}</select>
                            <input className={inputClass} placeholder="Title (Dr., Prof.)" value={place.title} onChange={(e) => setPlace({ ...place, title: e.target.value })} />
                            <input className={inputClass} placeholder="Designation" value={place.designation} onChange={(e) => setPlace({ ...place, designation: e.target.value })} />
                        </div>
                        <Button size="sm" variant="outline" className="w-fit" onClick={() => router.put(`/admin/people/${person.id}/placement`, { faculty_id: place.faculty_id || null, department_id: place.department_id || null, title: place.title || null, designation: place.designation || null }, { preserveScroll: true, onSuccess: onClose })}>Save placement</Button>
                    </div>
                )}
                <form className="mt-8 grid gap-2" onSubmit={(e) => {
 e.preventDefault(); del.post(`/admin/people/${person.id}/delegate`, { preserveScroll: true, onSuccess: onClose }); 
}}>
                    <p className="text-sm font-medium">Temporary role (acting HOD, cover invigilator…)</p>
                    <div className="grid grid-cols-2 gap-2">
                        <select className={inputClass} value={del.data.role} onChange={(e) => del.setData('role', e.target.value)}>{roles.map((r) => <option key={r} value={r}>{label(r)}</option>)}</select>
                        <input type="datetime-local" className={inputClass} value={del.data.expires_at} onChange={(e) => del.setData('expires_at', e.target.value)} required />
                    </div>
                    <input className={inputClass} placeholder="Reason (kept in the audit log)" value={del.data.reason} onChange={(e) => del.setData('reason', e.target.value)} required />
                    {Object.values(del.errors).length > 0 && <p className="text-sm text-status-critical">{Object.values(del.errors).join(' ')}</p>}
                    <Button size="sm" variant="outline" className="w-fit">Grant until that date</Button>
                </form>
                <div className="mt-8 flex flex-wrap gap-2">
                    {person.status === 'active'
                        ? <Button variant="outline" onClick={() => confirm(`Deactivate ${person.name}? They are signed out everywhere.`) && router.put(`/admin/people/${person.id}`, { status: 'inactive' }, { onSuccess: onClose })}>Deactivate</Button>
                        : <Button variant="outline" onClick={() => router.put(`/admin/people/${person.id}`, { status: 'active' }, { onSuccess: onClose })}>Reactivate</Button>}
                    <Button variant="ghost" onClick={onClose}>Close</Button>
                </div>
            </div>
        </div>
    );
}
