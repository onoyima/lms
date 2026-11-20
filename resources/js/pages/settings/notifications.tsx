import { Head, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Flash } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Category = { key: string; label: string; about: string; mandatory: boolean; app: boolean; mail: boolean; sms: boolean };
type Prefs = Record<string, { app: boolean; mail: boolean; sms?: boolean }>;

/** Which kinds of notice reach this person, in the app and by email. Security alerts cannot be turned off. */
export default function NotificationSettings({ categories, sms = null }: { categories: Category[]; sms?: { phone: string | null } | null }) {
    const form = useForm({ prefs: Object.fromEntries(categories.map((c) => [c.key, sms ? { app: c.app, mail: c.mail, sms: c.sms } : { app: c.app, mail: c.mail }])) as Prefs });
    const set = (k: string, ch: 'app' | 'mail' | 'sms', v: boolean) => form.setData('prefs', { ...form.data.prefs, [k]: { ...form.data.prefs[k], [ch]: v } });

    return (
        <>
            <Head title="Notification settings" />
            <h1 className="sr-only">Notification settings</h1>
            <div className="space-y-6">
                <Heading variant="small" title="Notifications" description="Choose what reaches you in the app and by email. Security alerts always reach you." />
                <Flash />
                {sms && !sms.phone && <p className="rounded-xl bg-status-warning/10 p-3 text-sm">Add your mobile number under Profile to get text messages.</p>}
                <form onSubmit={(e) => {
                    e.preventDefault();
                    form.put('/settings/notifications', { preserveScroll: true });
                }} className="space-y-4">
                    <div className="overflow-hidden rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs uppercase text-muted-foreground">
                                <tr><th className="px-4 py-2">Kind</th><th className="w-20 px-4 py-2 text-center">In app</th><th className="w-20 px-4 py-2 text-center">Email</th>{sms && <th className="w-20 px-4 py-2 text-center">Text</th>}</tr>
                            </thead>
                            <tbody className="divide-y">
                                {categories.map((c) => (
                                    <tr key={c.key}>
                                        <td className="px-4 py-3"><span className="font-medium">{c.label}</span><span className="block text-xs text-muted-foreground">{c.about}{c.mandatory ? ' — always on' : ''}</span></td>
                                        <td className="px-4 py-3 text-center"><input type="checkbox" aria-label={`${c.label} in app`} disabled={c.mandatory} checked={form.data.prefs[c.key].app} onChange={(e) => set(c.key, 'app', e.target.checked)} /></td>
                                        <td className="px-4 py-3 text-center"><input type="checkbox" aria-label={`${c.label} by email`} disabled={c.mandatory} checked={form.data.prefs[c.key].mail} onChange={(e) => set(c.key, 'mail', e.target.checked)} /></td>
                                        {sms && <td className="px-4 py-3 text-center"><input type="checkbox" aria-label={`${c.label} by text message`} disabled={!sms.phone} checked={!!form.data.prefs[c.key].sms} onChange={(e) => set(c.key, 'sms', e.target.checked)} /></td>}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <Button disabled={form.processing}>Save preferences</Button>
                </form>
            </div>
        </>
    );
}

NotificationSettings.layout = { breadcrumbs: [{ title: 'Notification settings', href: '/settings/notifications' }] };
