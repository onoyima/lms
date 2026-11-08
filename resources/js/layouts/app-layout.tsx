import { router, usePage } from '@inertiajs/react';
import { LifeBuoy, Megaphone, Wrench, X } from 'lucide-react';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Window = { id: number; starts_at: string; ends_at: string; message: string; blocks_exams: boolean };

/** Planned maintenance is announced on every page for two days beforehand. */
function MaintenanceBanner() {
    const windows = ((usePage().props as { maintenance?: Window[] }).maintenance ?? []).slice(0, 1);

    return windows.map((w) => {
        const live = new Date(w.starts_at) <= new Date();
        const when = `${new Date(w.starts_at).toLocaleString([], { weekday: 'short', hour: '2-digit', minute: '2-digit' })}–${new Date(w.ends_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;

        return (
            <div key={w.id} role="status" className={`flex items-center gap-2 px-6 py-2 text-sm ${live ? 'bg-status-warning/20' : 'bg-muted'}`}>
                <Wrench className="size-4 shrink-0" />
                <span>
                    <strong>{live ? 'Maintenance in progress' : 'Planned maintenance'}</strong> {when}. {w.message}
                    {w.blocks_exams && ' No exams can start during this time.'}
                </span>
            </div>
        );
    });
}

type Announcement = { id: number; title: string; body: string; level: 'info' | 'warning' | 'critical'; dismissible: boolean };

/** Notices from the platform team (all institutions or chosen ones). */
function AnnouncementBanner() {
    const list = (usePage().props as { announcements?: Announcement[] }).announcements ?? [];
    const tone = { info: 'bg-brand-soft text-foreground', warning: 'bg-status-warning/20', critical: 'bg-status-critical/15' };

    return list.map((a) => (
        <div key={a.id} role="status" className={`flex items-start gap-2 px-6 py-2 text-sm ${tone[a.level] ?? tone.info}`}>
            <Megaphone className="mt-0.5 size-4 shrink-0" />
            <span className="flex-1"><strong>{a.title}</strong> {a.body}</span>
            {a.dismissible && (
                <button aria-label="Dismiss" className="rounded p-0.5 hover:bg-black/10" onClick={() => router.post(`/announcements/${a.id}/dismiss`, {}, { preserveScroll: true, preserveState: true })}>
                    <X className="size-4" />
                </button>
            )}
        </div>
    ));
}

/** Shown on every page while the platform team is signed in as this user for support. */
function SupportBanner() {
    const s = (usePage().props as { impersonation?: { by: string; expires_at: string } | null }).impersonation;

    if (!s) {
        return null;
    }

    return (
        <div role="alert" className="flex flex-wrap items-center gap-2 bg-status-critical px-6 py-2 text-sm text-white">
            <LifeBuoy className="size-4 shrink-0" />
            <span className="flex-1"><strong>Support session.</strong> {s.by} (platform team) is signed in as this account until {new Date(s.expires_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}. Everything done is recorded as support.</span>
            <button className="rounded-md bg-white/20 px-3 py-1 font-medium hover:bg-white/30" onClick={() => router.post('/support/leave')}>End support session</button>
        </div>
    );
}

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>
            <SupportBanner />
            <AnnouncementBanner />
            <MaintenanceBanner />
            {children}
            <Toaster />
        </AppLayoutTemplate>
    );
}
