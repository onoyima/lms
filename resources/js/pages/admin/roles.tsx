import { Head, router } from '@inertiajs/react';
import { Lock, Plus, RotateCcw, Trash2, Users } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Field, Flash, inputClass, PageHeader } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Role = { id: number; name: string; label: string; description: string | null; is_system: boolean; permissions: string[]; members: number; editable: boolean; resettable: boolean };
type Perm = { name: string; label: string; description: string | null; privileged: boolean; grantable: boolean };
type Draft = { id?: number; label: string; description: string; permissions: string[] };

const GROUPS: [string, string][] = [
    ['Institution', 'institution.|users.|roles.|integrations.|billing.|audit.'],
    ['Academics & teaching', 'academics.|lms.'],
    ['Questions & exams', 'questions.|exams.'],
    ['Results & reports', 'results.|reports.'],
];

function Editor({ draft, permissions, onDone }: { draft: Draft; permissions: Perm[]; onDone: () => void }) {
    const [d, setD] = useState<Draft>(draft);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const toggle = (p: string) => setD({ ...d, permissions: d.permissions.includes(p) ? d.permissions.filter((x) => x !== p) : [...d.permissions, p] });
    const save = () => {
        const payload = { label: d.label, description: d.description || null, permissions: d.permissions };
        const opts = { preserveScroll: true, onError: (e: Record<string, string>) => setErrors(e), onSuccess: onDone };

        if (d.id) {
            router.put(`/admin/roles/${d.id}`, payload, opts);
        } else {
            router.post('/admin/roles', payload, opts);
        }
    };

    return (
        <div className="surface grid gap-5 p-6">
            <div className="grid gap-3 md:grid-cols-2">
                <Field label="Role name" error={errors.label}><input className={inputClass} value={d.label} onChange={(e) => setD({ ...d, label: e.target.value })} placeholder="e.g. Faculty exams coordinator" autoFocus /></Field>
                <Field label="What this role is for (optional)" error={errors.description}><input className={inputClass} value={d.description} onChange={(e) => setD({ ...d, description: e.target.value })} /></Field>
            </div>
            {GROUPS.map(([title, prefixes]) => {
                const list = permissions.filter((p) => prefixes.split('|').some((x) => p.name.startsWith(x)));

                return (
                    <fieldset key={title} className="grid gap-2">
                        <legend className="eyebrow mb-1">{title}</legend>
                        <div className="grid gap-2 md:grid-cols-2">
                            {list.map((p) => (
                                <label key={p.name} className={`flex items-start gap-3 rounded-xl border p-3 text-sm ${d.permissions.includes(p.name) ? 'border-brand bg-brand-soft' : 'border-border'} ${p.grantable ? '' : 'opacity-50'}`}>
                                    <input type="checkbox" className="mt-1" checked={d.permissions.includes(p.name)} disabled={!p.grantable} onChange={() => toggle(p.name)} />
                                    <span>
                                        <span className="font-medium">{p.label}</span>
                                        {p.privileged && <span className="ml-2 rounded-full bg-muted px-2 py-0.5 text-[10px] uppercase tracking-wide">high trust</span>}
                                        <span className="block text-xs text-muted-foreground">{p.grantable ? p.description : 'You do not hold this permission, so you cannot give it.'}</span>
                                    </span>
                                </label>
                            ))}
                        </div>
                    </fieldset>
                );
            })}
            {errors.permissions && <p className="text-sm text-status-critical">{errors.permissions}</p>}
            <div className="flex gap-2">
                <Button onClick={save} disabled={d.label.trim().length < 2}>{d.id ? 'Save role' : 'Create role'}</Button>
                <Button variant="outline" onClick={onDone}>Cancel</Button>
            </div>
        </div>
    );
}

export default function Roles({ roles, permissions }: { roles: Role[]; permissions: Perm[] }) {
    const [editing, setEditing] = useState<Draft | null>(null);
    const labelOf = useMemo(() => Object.fromEntries(permissions.map((p) => [p.name, p.label])), [permissions]);

    return (
        <>
            <Head title="Roles & permissions" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader
                    title="Roles & permissions"
                    lead="Your institution decides its own roles. Create any role, choose exactly what it may do, then give it to anyone under People — by hand, by import, or automatically from single sign-on groups."
                    actions={!editing && <Button onClick={() => setEditing({ label: '', description: '', permissions: [] })}><Plus className="size-4" /> New role</Button>}
                />
                <Flash />
                {editing && <Editor key={editing.id ?? 'new'} draft={editing} permissions={permissions} onDone={() => setEditing(null)} />}
                <div className="grid gap-4 lg:grid-cols-2">
                    {roles.map((r) => (
                        <article key={r.id} className="surface grid content-start gap-3 p-5">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <p className="font-medium">{r.label}</p>
                                    <p className="text-xs text-muted-foreground">{r.description || (r.is_system ? 'Starting role' : 'Custom role')}</p>
                                </div>
                                <span className="flex shrink-0 items-center gap-1 text-xs text-muted-foreground"><Users className="size-3.5" /> {r.members}</span>
                            </div>
                            <div className="flex flex-wrap gap-1">
                                {r.permissions.length === 0 && <span className="text-xs text-muted-foreground">No permissions yet</span>}
                                {r.permissions.map((p) => <span key={p} className="rounded-full bg-muted px-2 py-0.5 text-[11px]">{labelOf[p] ?? p}</span>)}
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                {r.editable ? (
                                    <>
                                        <Button size="sm" variant="outline" onClick={() => setEditing({ id: r.id, label: r.label, description: r.description ?? '', permissions: r.permissions })}>Edit</Button>
                                        {r.resettable && <Button size="sm" variant="ghost" onClick={() => confirm(`Put "${r.label}" back to its default permissions?`) && router.post(`/admin/roles/${r.id}/reset`, {}, { preserveScroll: true })}><RotateCcw className="size-4" /> Reset</Button>}
                                        {!r.is_system || r.members === 0 ? (
                                            <Button size="sm" variant="ghost" aria-label="Delete role" disabled={r.members > 0} title={r.members > 0 ? 'Remove it from its members first' : 'Delete'} onClick={() => confirm(`Delete "${r.label}"?`) && router.delete(`/admin/roles/${r.id}`, { preserveScroll: true })}><Trash2 className="size-4" /></Button>
                                        ) : null}
                                    </>
                                ) : (
                                    <span className="flex items-center gap-1 text-xs text-muted-foreground"><Lock className="size-3.5" /> {r.name === 'institution_admin' ? 'Always holds every permission' : 'You hold this role, or it has permissions you do not hold'}</span>
                                )}
                            </div>
                        </article>
                    ))}
                </div>
            </div>
        </>
    );
}

Roles.layout = { breadcrumbs: [{ title: 'Roles & permissions', href: '/admin/roles' }] };
