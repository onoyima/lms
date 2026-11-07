import { Head, router, useForm } from '@inertiajs/react';
import { Field, Flash, formatDateTime, inputClass, PageHeader, Status, Table, Td } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Admin = { id: number; name: string; email: string; status: string; two_factor_confirmed_at: string | null; created_at: string };

/** The platform operator's own team. They manage institutions and billing; they have no access to institution data. */
export default function Admins({ admins }: { admins: Admin[] }) {
    const form = useForm({ name: '', email: '' });

    return (
        <>
            <Head title="Platform administrators" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title="Platform administrators" lead="Each new administrator receives a link to set their own password and must turn on two-factor sign-in before using the console." />
                <Flash />
                <form className="surface grid gap-3 p-5 md:grid-cols-3" onSubmit={(e) => {
 e.preventDefault(); form.post('/platform/admins', { preserveScroll: true, onSuccess: () => form.reset() }); 
}}>
                    <Field label="Name" error={form.errors.name}><input className={inputClass} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /></Field>
                    <Field label="Email" error={form.errors.email}><input type="email" className={inputClass} value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} required /></Field>
                    <div className="flex items-end"><Button disabled={form.processing}>Invite</Button></div>
                </form>
                <Table head={['Name', 'Email', 'Two-factor', 'Since', 'Status', '']}>
                    {admins.map((a) => (
                        <tr key={a.id}>
                            <Td className="font-medium">{a.name}</Td>
                            <Td>{a.email}</Td>
                            <Td>{a.two_factor_confirmed_at ? 'on' : <span className="text-status-warning">not yet</span>}</Td>
                            <Td>{formatDateTime(a.created_at)}</Td>
                            <Td><Status value={a.status} /></Td>
                            <Td><Button size="sm" variant="ghost" onClick={() => router.post(`/platform/admins/${a.id}/toggle`, {}, { preserveScroll: true })}>{a.status === 'active' ? 'Disable' : 'Enable'}</Button></Td>
                        </tr>
                    ))}
                </Table>
            </div>
        </>
    );
}

Admins.layout = { breadcrumbs: [{ title: 'Platform administrators', href: '/platform/admins' }] };
