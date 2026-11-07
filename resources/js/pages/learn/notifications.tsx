import { Head, Link, router } from '@inertiajs/react';
import { Bell, Check, CheckCheck, Settings2, Trash2 } from 'lucide-react';
import { Flash, formatDateTime, PageHeader } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type N = { id: string; data: { title: string; body: string; url?: string | null; urgent?: boolean; category: string }; read: boolean; created_at: string };
type Category = { key: string; label: string; unread: number };
type Props = {
    notifications: { data: N[]; links: { url: string | null; label: string; active: boolean }[] };
    categories: Category[]; filters: { category: string | null; unread: boolean }; unread: number;
};

/** The notification centre: history by kind, unread first-class, mark read, mark all read, delete. */
export default function Notifications({ notifications, categories, filters, unread }: Props) {
    const go = (q: Partial<Props['filters']>) => router.get('/notifications', { ...filters, ...q, unread: (q.unread ?? filters.unread) ? 1 : undefined, category: (q.category === undefined ? filters.category : q.category) || undefined }, { preserveScroll: true, preserveState: true });
    const label = (k: string) => categories.find((c) => c.key === k)?.label ?? k;

    return (
        <>
            <Head title="Notifications" />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-6 md:p-10">
                <PageHeader title="For you" lead={unread ? `${unread} unread` : 'All caught up.'} actions={
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" disabled={!unread} onClick={() => router.post('/notifications/read-all', filters.category ? { category: filters.category } : {}, { preserveScroll: true })}><CheckCheck className="size-4" /> Mark all read</Button>
                        <Button variant="ghost" size="sm" onClick={() => confirm('Delete every notification you have already read?') && router.delete('/notifications', { preserveScroll: true })}><Trash2 className="size-4" /> Delete read</Button>
                        <Button variant="ghost" size="sm" asChild><Link href="/settings/notifications"><Settings2 className="size-4" /> Preferences</Link></Button>
                    </div>
                } />
                <Flash />
                <div className="flex flex-wrap gap-2" role="tablist" aria-label="Notification kinds">
                    <button role="tab" aria-selected={!filters.category} onClick={() => go({ category: null })} className={`rounded-full border px-3 py-1 text-sm ${!filters.category ? 'border-brand bg-brand-soft text-brand' : 'text-muted-foreground hover:bg-muted'}`}>All</button>
                    {categories.map((c) => (
                        <button key={c.key} role="tab" aria-selected={filters.category === c.key} onClick={() => go({ category: c.key })}
                            className={`rounded-full border px-3 py-1 text-sm ${filters.category === c.key ? 'border-brand bg-brand-soft text-brand' : 'text-muted-foreground hover:bg-muted'}`}>
                            {c.label}{c.unread > 0 && <span className="ml-1.5 rounded-full bg-brand px-1.5 text-xs text-brand-foreground">{c.unread}</span>}
                        </button>
                    ))}
                    <label className="ml-auto flex items-center gap-2 text-sm text-muted-foreground"><input type="checkbox" checked={filters.unread} onChange={(e) => go({ unread: e.target.checked })} /> Unread only</label>
                </div>
                <div className="surface divide-y divide-border">
                    {notifications.data.length === 0 && <p className="flex items-center gap-2 p-6 text-sm text-muted-foreground"><Bell className="size-4" /> Nothing here.</p>}
                    {notifications.data.map((n) => (
                        <div key={n.id} className={`group flex gap-3 p-4 ${n.data.urgent && !n.read ? 'bg-status-critical/5' : ''} ${n.read ? '' : 'bg-brand-soft/30'}`}>
                            <span className={`mt-2 size-2 shrink-0 rounded-full ${n.read ? 'bg-transparent' : 'bg-brand'}`} aria-label={n.read ? undefined : 'Unread'} />
                            <button className="min-w-0 flex-1 text-left" onClick={() => n.data.url ? router.post(`/notifications/${n.id}/read`, { open: true }) : !n.read && router.post(`/notifications/${n.id}/read`, {}, { preserveScroll: true })}>
                                <span className="block font-medium">{n.data.title}</span>
                                <span className="block text-sm text-muted-foreground">{n.data.body}</span>
                                <span className="mt-0.5 block text-xs text-muted-foreground">{label(n.data.category)} · {formatDateTime(n.created_at)}</span>
                            </button>
                            <div className="flex shrink-0 items-start gap-1 opacity-70 group-hover:opacity-100">
                                {!n.read && <Button size="icon" variant="ghost" aria-label="Mark as read" onClick={() => router.post(`/notifications/${n.id}/read`, {}, { preserveScroll: true })}><Check className="size-4" /></Button>}
                                <Button size="icon" variant="ghost" aria-label="Delete" onClick={() => router.delete(`/notifications/${n.id}`, { preserveScroll: true })}><Trash2 className="size-4" /></Button>
                            </div>
                        </div>
                    ))}
                </div>
                {notifications.links.length > 3 && (
                    <nav className="flex flex-wrap gap-1" aria-label="Pages">
                        {notifications.links.map((l, i) => l.url
                            ? <Link key={i} href={l.url} preserveScroll className={`rounded-md border px-3 py-1 text-sm ${l.active ? 'border-brand text-brand' : ''}`} dangerouslySetInnerHTML={{ __html: l.label }} />
                            : <span key={i} className="px-3 py-1 text-sm text-muted-foreground" dangerouslySetInnerHTML={{ __html: l.label }} />)}
                    </nav>
                )}
            </div>
        </>
    );
}

Notifications.layout = { breadcrumbs: [{ title: 'Notifications', href: '/notifications' }] };
