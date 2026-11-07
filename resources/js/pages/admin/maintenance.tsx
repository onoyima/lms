import { Head, router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { Field, Flash, formatDateTime, inputClass, PageHeader, Table, Td, textareaClass } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type W = { id: number; tenant_id: number | null; starts_at: string; ends_at: string; message: string; blocks_exams: boolean | number };

export default function Maintenance({ windows }: { windows: W[] }) {
    const form = useForm({ starts_at: '', ends_at: '', message: 'The platform will be briefly unavailable for updates.', blocks_exams: true, notify: true });

    return (
        <>
            <Head title="Maintenance" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title="Planned maintenance" lead="Announced on every page for two days beforehand. No exam can start during a window, and no sitting can be booked into one." />
                <Flash />
                <form className="surface grid gap-3 p-5 md:grid-cols-4" onSubmit={(e) => {
 e.preventDefault(); form.post('/admin/maintenance', { preserveScroll: true }); 
}}>
                    <Field label="Starts" error={form.errors.starts_at}><input type="datetime-local" className={inputClass} value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} required /></Field>
                    <Field label="Ends" error={form.errors.ends_at}><input type="datetime-local" className={inputClass} value={form.data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} required /></Field>
                    <div className="md:col-span-2"><Field label="Message" error={form.errors.message}><textarea className={`${textareaClass} min-h-10`} value={form.data.message} onChange={(e) => form.setData('message', e.target.value)} /></Field></div>
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.blocks_exams} onChange={(e) => form.setData('blocks_exams', e.target.checked)} /> No exams during this time</label>
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.notify} onChange={(e) => form.setData('notify', e.target.checked)} /> Tell all staff now</label>
                    <Button className="w-fit" disabled={form.processing}>Schedule</Button>
                </form>
                <Table head={['When', 'Message', 'Exams', '']}>
                    {windows.map((w) => (
                        <tr key={w.id}>
                            <Td>{formatDateTime(w.starts_at)} – {formatDateTime(w.ends_at)}{w.tenant_id === null && <span className="block text-xs text-muted-foreground">whole platform</span>}</Td>
                            <Td>{w.message}</Td>
                            <Td>{w.blocks_exams ? 'blocked' : 'allowed'}</Td>
                            <Td>{w.tenant_id !== null && new Date(w.ends_at) > new Date() && <Button size="sm" variant="ghost" aria-label="Cancel" onClick={() => confirm('Cancel this maintenance?') && router.delete(`/admin/maintenance/${w.id}`)}><Trash2 className="size-4" /></Button>}</Td>
                        </tr>
                    ))}
                </Table>
            </div>
        </>
    );
}

Maintenance.layout = { breadcrumbs: [{ title: 'Maintenance', href: '/admin/maintenance' }] };
