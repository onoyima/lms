import { Head, router, useForm } from '@inertiajs/react';
import { Flash, formatDateTime, inputClass, PageHeader, Status, textareaClass } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Props = {
    ticket: { id: number; subject: string; category: string; priority: string; status: string; created_at: string; assigned_to: number | null; from: { name: string; identifier: string | null; email: string } | null };
    messages: { id: number; body: string; internal: boolean; created_at: string; name: string }[];
    desk: boolean;
    staff: { id: number; name: string }[];
};

export default function Ticket({ ticket, messages, desk, staff }: Props) {
    const form = useForm({ body: '', internal: false });
    const set = (data: Record<string, unknown>) => router.post(`/help/tickets/${ticket.id}`, data as never, { preserveScroll: true });

    return (
        <>
            <Head title={ticket.subject} />
            <div className="flex max-w-3xl flex-col gap-6 p-6 md:p-10">
                <PageHeader eyebrow={`Request #${ticket.id} · ${ticket.category}`} title={ticket.subject} lead={<span className="flex gap-2"><Status value={ticket.status} /><Status value={ticket.priority} /></span>} />
                <Flash />
                {desk && (
                    <div className="surface grid gap-2 p-4 text-sm md:grid-cols-3">
                        <span>{ticket.from?.name} · {ticket.from?.identifier ?? ticket.from?.email}</span>
                        <select className={inputClass} value={ticket.assigned_to ?? ''} onChange={(e) => set({ assigned_to: e.target.value ? Number(e.target.value) : null })} aria-label="Assign">
                            <option value="">Not assigned</option>
                            {staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </select>
                        <select className={inputClass} value={ticket.status} onChange={(e) => set({ status: e.target.value })} aria-label="Status">
                            {['open', 'pending', 'resolved', 'closed'].map((s) => <option key={s} value={s}>{s}</option>)}
                        </select>
                    </div>
                )}
                <div className="grid gap-3">
                    {messages.map((m) => (
                        <div key={m.id} className={`surface p-4 text-sm ${m.internal ? 'border-status-warning/50 bg-status-warning/5' : ''}`}>
                            <p className="text-xs text-muted-foreground">{m.name} · {formatDateTime(m.created_at)}{m.internal ? ' · staff note' : ''}</p>
                            <p className="mt-1 whitespace-pre-wrap">{m.body}</p>
                        </div>
                    ))}
                </div>
                <form className="grid gap-2" onSubmit={(e) => {
 e.preventDefault(); form.post(`/help/tickets/${ticket.id}`, { preserveScroll: true, onSuccess: () => form.reset() }); 
}}>
                    <textarea className={textareaClass} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} placeholder="Write a reply" required />
                    <div className="flex items-center gap-3">
                        <Button disabled={form.processing}>Send</Button>
                        {desk && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.internal} onChange={(e) => form.setData('internal', e.target.checked)} /> Staff-only note</label>}
                    </div>
                </form>
            </div>
        </>
    );
}

Ticket.layout = { breadcrumbs: [{ title: 'Help', href: '/help' }, { title: 'Request', href: '#' }] };
