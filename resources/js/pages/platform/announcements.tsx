import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Field, Flash, formatDateTime, inputClass, PageHeader, Status, Table, Td, textareaClass } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type A = { id: number; title: string; body: string; level: string; audience: string; tenant_ids: number[] | null; starts_at: string | null; ends_at: string | null; dismissible: boolean };
type Props = { announcements: A[]; institutions: { id: number; name: string }[] };

const live = (a: A) => (!a.starts_at || new Date(a.starts_at) <= new Date()) && (!a.ends_at || new Date(a.ends_at) > new Date());

/** Banners from the platform team to every institution or chosen ones. */
export default function Announcements({ announcements, institutions }: Props) {
    const [editing, setEditing] = useState<A | 'new' | null>(null);

    return (
        <>
            <Head title="Announcements" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title="System-wide" accent="announcements" lead="Shown as a banner at the top of every page for the people chosen, until the end date or until they dismiss it."
                    actions={<Button onClick={() => setEditing(editing ? null : 'new')}>{editing ? 'Close' : 'New announcement'}</Button>} />
                <Flash />
                {editing && <AnnouncementForm key={editing === 'new' ? 'new' : editing.id} a={editing === 'new' ? undefined : editing} institutions={institutions} onDone={() => setEditing(null)} />}
                <Table head={['Announcement', 'For', 'Shown', 'Status', '']}>
                    {announcements.length === 0 && <tr><Td className="text-muted-foreground">No announcements yet.</Td></tr>}
                    {announcements.map((a) => (
                        <tr key={a.id}>
                            <Td><span className="font-medium">{a.title}</span><span className="block max-w-md truncate text-xs text-muted-foreground">{a.body}</span></Td>
                            <Td className="text-xs">{a.audience} · {a.tenant_ids ? institutions.filter((i) => a.tenant_ids!.includes(i.id)).map((i) => i.name).join(', ') : 'all institutions'}</Td>
                            <Td className="text-xs">{a.starts_at ? formatDateTime(a.starts_at) : 'now'} – {a.ends_at ? formatDateTime(a.ends_at) : 'until removed'}</Td>
                            <Td><Status value={live(a) ? 'active' : 'inactive'} /> <span className="text-xs text-muted-foreground">{a.level}</span></Td>
                            <Td>
                                <Button size="sm" variant="ghost" onClick={() => setEditing(a)}>Edit</Button>
                                <Button size="sm" variant="ghost" className="text-status-critical" onClick={() => confirm('Remove this announcement?') && router.delete(`/platform/announcements/${a.id}`, { preserveScroll: true })}>Remove</Button>
                            </Td>
                        </tr>
                    ))}
                </Table>
            </div>
        </>
    );
}

function AnnouncementForm({ a, institutions, onDone }: { a?: A; institutions: Props['institutions']; onDone: () => void }) {
    const form = useForm({
        title: a?.title ?? '', body: a?.body ?? '', level: a?.level ?? 'info', audience: a?.audience ?? 'everyone', tenant_ids: a?.tenant_ids ?? [],
        starts_at: a?.starts_at?.slice(0, 16) ?? '', ends_at: a?.ends_at?.slice(0, 16) ?? '', dismissible: a?.dismissible ?? true, notify: false,
    });

    return (
        <form className="surface grid gap-3 p-5 md:grid-cols-4" onSubmit={(e) => {
            e.preventDefault();
            form.transform((d) => ({ ...d, starts_at: d.starts_at || null, ends_at: d.ends_at || null }));

            if (a) {
                form.put(`/platform/announcements/${a.id}`, { preserveScroll: true, onSuccess: onDone });
            } else {
                form.post('/platform/announcements', { preserveScroll: true, onSuccess: onDone });
            }
        }}>
            <div className="md:col-span-4"><Field label="Title" error={form.errors.title}><input className={inputClass} value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} required /></Field></div>
            <div className="md:col-span-4"><Field label="Message" error={form.errors.body}><textarea className={textareaClass} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} required maxLength={2000} /></Field></div>
            <Field label="Level"><select className={inputClass} value={form.data.level} onChange={(e) => form.setData('level', e.target.value)}><option value="info">Information</option><option value="warning">Warning</option><option value="critical">Critical</option></select></Field>
            <Field label="Who sees it"><select className={inputClass} value={form.data.audience} onChange={(e) => form.setData('audience', e.target.value)}><option value="everyone">Everyone</option><option value="admins">Institution administrators</option><option value="staff">Staff</option><option value="students">Students</option></select></Field>
            <Field label="From" error={form.errors.starts_at}><input type="datetime-local" className={inputClass} value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} /></Field>
            <Field label="Until" error={form.errors.ends_at}><input type="datetime-local" className={inputClass} value={form.data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} /></Field>
            <div className="md:col-span-4">
                <p className="mb-1.5 text-sm font-medium">Institutions <span className="font-normal text-muted-foreground">(none ticked = all)</span></p>
                <div className="flex max-h-40 flex-wrap gap-2 overflow-auto">
                    {institutions.map((i) => (
                        <label key={i.id} className="flex items-center gap-1 text-sm">
                            <input type="checkbox" checked={form.data.tenant_ids.includes(i.id)} onChange={() => form.setData('tenant_ids', form.data.tenant_ids.includes(i.id) ? form.data.tenant_ids.filter((x) => x !== i.id) : [...form.data.tenant_ids, i.id])} />{i.name}
                        </label>
                    ))}
                </div>
            </div>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.dismissible} onChange={(e) => form.setData('dismissible', e.target.checked)} /> People can dismiss it</label>
            <label className="flex items-center gap-2 text-sm md:col-span-2"><input type="checkbox" checked={form.data.notify} onChange={(e) => form.setData('notify', e.target.checked)} /> Also send it as a notification</label>
            <div className="md:col-span-4"><Button disabled={form.processing}>{a ? 'Save' : 'Publish'}</Button></div>
        </form>
    );
}

Announcements.layout = { breadcrumbs: [{ title: 'Announcements', href: '/platform/announcements' }] };
