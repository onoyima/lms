import { Head, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Flash } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Category = { key: string; label: string; about: string; mandatory: boolean; app: boolean; mail: boolean };

/** Which kinds of notice reach this person, in the app and by email. Security alerts cannot be turned off. */
export default function NotificationSettings({ categories }: { categories: Category[] }) {
    const form = useForm({ prefs: Object.fromEntries(categories.map((c) => [c.key, { app: c.app, mail: c.mail }])) as Record<string, { app: boolean; mail: boolean }> });
    const set = (k: string, ch: 'app' | 'mail', v: boolean) => form.setData('prefs', { ...form.data.prefs, [k]: { ...form.data.prefs[k], [ch]: v } });

    return (
        <>
            <Head title="Notification settings" />
            <h1 className="sr-only">Notification settings</h1>
            <div className="space-y-6">
                <Heading variant="small" title="Notifications" description="Choose what reaches you in the app and by email. Security alerts always reach you." />
                <Flash />
                <form onSubmit={(e) => {
                    e.preventDefault();
                    form.put('/settings/notifications', { preserveScroll: true });
                }} className="space-y-4">
                    <div className="overflow-hidden rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs uppercase text-muted-foreground">
                                <tr><th className="px-4 py-2">Kind</th><th className="w-20 px-4 py-2 text-center">In app</th><th className="w-20 px-4 py-2 text-center">Email</th></tr>
                            </thead>
                            <tbody className="divide-y">
                                {categories.map((c) => (
                                    <tr key={c.key}>
                                        <td className="px-4 py-3"><span className="font-medium">{c.label}</span><span className="block text-xs text-muted-foreground">{c.about}{c.mandatory ? ' — always on' : ''}</span></td>
                                        <td className="px-4 py-3 text-center"><input type="checkbox" aria-label={`${c.label} in app`} disabled={c.mandatory} checked={form.data.prefs[c.key].app} onChange={(e) => set(c.key, 'app', e.target.checked)} /></td>
                                        <td className="px-4 py-3 text-center"><input type="checkbox" aria-label={`${c.label} by email`} disabled={c.mandatory} checked={form.data.prefs[c.key].mail} onChange={(e) => set(c.key, 'mail', e.target.checked)} /></td>
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
