import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Field, inputClass, PageHeader, Status, Table, Td } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Venue = { id: number; name: string; campus: string | null; capacity: number; network_cidr: string | null; seats_count: number };
type Device = { id: number; label: string; status: string; last_seen_at: string | null; venue: { id: number; name: string } | null; public_key: string | null };

export default function Venues({ venues, devices }: { venues: Venue[]; devices: Device[] }) {
    const { flash } = usePage().props;
    const form = useForm({ name: '', campus: '', capacity: 100, network_cidr: '', seat_prefix: 'A' });

    return (
        <>
            <Head title="Halls & devices" />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader title="Halls, seats" accent="and approved computers." lead="Desktop app computers enrol themselves; approve them here before they can run hall exams." />
                {flash?.success && <p className="text-sm text-status-good">{flash.success}</p>}
                <div className="grid gap-6 lg:grid-cols-[1fr_360px]">
                    <Table head={['Venue', 'Campus', 'Seats', 'Network']}>
                        {venues.map((v) => (
                            <tr key={v.id}>
                                <Td className="font-medium">{v.name}</Td>
                                <Td>{v.campus ?? '—'}</Td>
                                <Td className="tabular-nums">{v.seats_count}</Td>
                                <Td className="font-mono text-xs">{v.network_cidr ?? '—'}</Td>
                            </tr>
                        ))}
                    </Table>
                    <form className="surface grid gap-3 p-5" onSubmit={(e) => (e.preventDefault(), form.post('/venues', { onSuccess: () => form.reset() }))}>
                        <p className="eyebrow">New venue</p>
                        <Field label="Name"><input className={inputClass} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /></Field>
                        <Field label="Campus"><input className={inputClass} value={form.data.campus} onChange={(e) => form.setData('campus', e.target.value)} /></Field>
                        <div className="grid grid-cols-2 gap-2">
                            <Field label="Seats"><input type="number" min={1} className={inputClass} value={form.data.capacity} onChange={(e) => form.setData('capacity', Number(e.target.value))} /></Field>
                            <Field label="Seat prefix"><input className={inputClass} value={form.data.seat_prefix} onChange={(e) => form.setData('seat_prefix', e.target.value)} /></Field>
                        </div>
                        <Field label="Hall network" hint="e.g. 192.168.10.0/24"><input className={`${inputClass} font-mono`} value={form.data.network_cidr} onChange={(e) => form.setData('network_cidr', e.target.value)} /></Field>
                        <Button disabled={form.processing}>Create with seats</Button>
                    </form>
                </div>

                <section>
                    <p className="eyebrow mb-3">Exam computers</p>
                    <Table head={['Computer', 'Venue', 'Status', 'Last seen', '']}>
                        {devices.map((d) => (
                            <tr key={d.id}>
                                <Td className="font-medium">{d.label}</Td>
                                <Td>{d.venue?.name ?? '—'}</Td>
                                <Td><Status value={d.status} /></Td>
                                <Td className="text-xs">{d.last_seen_at ?? 'never'}</Td>
                                <Td className="flex gap-2">
                                    {d.status !== 'approved' && <Button size="sm" onClick={() => router.put(`/devices/${d.id}`, { status: 'approved' }, { preserveScroll: true })}>Approve</Button>}
                                    {d.status !== 'blocked' && <Button size="sm" variant="outline" onClick={() => router.put(`/devices/${d.id}`, { status: 'blocked' }, { preserveScroll: true })}>Block</Button>}
                                </Td>
                            </tr>
                        ))}
                    </Table>
                </section>
            </div>
        </>
    );
}

Venues.layout = { breadcrumbs: [{ title: 'Halls & devices', href: '/venues' }] };
