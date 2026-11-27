import { router, usePage } from '@inertiajs/react';
import { LifeBuoy, Megaphone, Wrench, X } from 'lucide-react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import { Toaster } from '@/components/ui/sonner';
import { modulesFor } from '@/hooks/use-platform';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import { play } from '@/lib/ui-sound';
import type { BreadcrumbItem } from '@/types';

type Window = { id: number; starts_at: string; ends_at: string; message: string; blocks_exams: boolean };

/** Planned maintenance is announced on every page for two days beforehand. */
function MaintenanceBanner() {
    const windows = ((usePage().props as { maintenance?: Window[] }).maintenance ?? []).slice(0, 1);

    return windows.map((w) => {
        const live = new Date(w.starts_at) <= new Date();
        const when = `${new Date(w.starts_at).toLocaleString([], { weekday: 'short', hour: '2-digit', minute: '2-digit' })}–${new Date(w.ends_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;

        return (
            <div key={w.id} role="status" className={`mx-4 mt-3 flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm md:mx-6 ${live ? 'bg-status-warning/20' : 'bg-muted'}`}>
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
    const tone = { info: 'text-foreground', warning: 'bg-status-warning/15', critical: 'bg-status-critical/12' };

    return list.map((a) => (
        <div key={a.id} role="status" className={`glass mx-4 mt-3 flex items-start gap-2.5 rounded-xl px-4 py-2.5 text-sm md:mx-6 ${tone[a.level] ?? tone.info}`}>
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

/** Shown on every page of the public demo. */
function DemoBanner() {
    const demo = (usePage().props as { demo?: boolean }).demo;

    if (!demo) {
        return null;
    }

    return (
        <div role="note" className="mx-4 mt-3 flex flex-wrap items-center gap-2 rounded-xl bg-brand-soft px-4 py-2.5 text-sm md:mx-6">
            <span className="flex-1"><strong>Public demo.</strong> Sample data only; nothing is sent to anyone and everything resets every night.</span>
            <a href="/demo" className="rounded-md border border-current/20 px-3 py-1 font-medium hover:bg-black/5">Try another role</a>
            <a href="/#apply" className="rounded-md bg-brand px-3 py-1 font-medium text-brand-foreground">Apply for your institution</a>
        </div>
    );
}

/** Shown on every page while the platform team is signed in as this user for support. */
function SupportBanner() {
    const s = (usePage().props as { impersonation?: { by: string; expires_at: string } | null }).impersonation;

    if (!s) {
        return null;
    }

    return (
        <div role="alert" className="mx-4 mt-3 flex flex-wrap items-center gap-2 rounded-xl bg-status-critical px-4 py-2.5 text-sm text-white md:mx-6">
            <LifeBuoy className="size-4 shrink-0" />
            <span className="flex-1"><strong>Support session.</strong> {s.by} (platform team) is signed in as this account until {new Date(s.expires_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}. Everything done is recorded as support.</span>
            <button className="rounded-md bg-white/20 px-3 py-1 font-medium hover:bg-white/30" onClick={() => router.post('/support/leave')}>End support session</button>
        </div>
    );
}

/**
 * A link into a part of the platform that is switched off for this institution (a module) is
 * shown dimmed and says so when tapped, instead of opening a page that would be refused. Pages
 * themselves are refused on the server too.
 */
function ModuleLinkGuard() {
    const modules = ((usePage().props as { modules?: string[] }).modules ?? []).join(',');

    useEffect(() => {
        const on = new Set(modules.split(',').filter(Boolean));
        const off = (a: HTMLAnchorElement) => {
            const href = a.getAttribute('href');

            if (!href || href.startsWith('#') || /^(mailto|tel):/.test(href) || (a.host && a.host !== window.location.host)) {
                return false;
            }

            return modulesFor(href).some((m) => !on.has(m));
        };
        const mark = () => document.querySelectorAll<HTMLAnchorElement>('main a[href], [data-slot=sidebar-inset] a[href]').forEach((a) => {
            if (off(a)) {
                a.dataset.moduleOff = '1';
                a.setAttribute('aria-disabled', 'true');
                a.title = 'Not available: switched off for your institution';
            }
        });
        const click = (e: MouseEvent) => {
            const a = (e.target as HTMLElement | null)?.closest?.('a[href]') as HTMLAnchorElement | null;

            if (a && off(a)) {
                e.preventDefault();
                e.stopPropagation();
                play('nope');
                toast.info('This feature is switched off for your institution. Ask your administrator if you need it.');
            }
        };
        mark();
        const mo = new MutationObserver(mark);
        mo.observe(document.body, { childList: true, subtree: true });
        document.addEventListener('click', click, true);

        return () => {
            mo.disconnect();
            document.removeEventListener('click', click, true);
        };
    }, [modules]);

    return null;
}

/**
 * Midnight theme: a quiet click on anything that can be pressed, and an ember that follows the
 * pointer inside tiles. Switched off with the speaker button; never on typing or in forms' fields.
 */
function MidnightFeel() {
    const theme = (usePage().props as { tenant?: { theme?: string } | null }).tenant?.theme;

    useEffect(() => {
        if (theme !== 'midnight') {
            return;
        }

        const down = (e: PointerEvent) => {
            const el = (e.target as HTMLElement | null)?.closest?.('a[href], button, [role=tab], [role=menuitem], [role=option], [role=switch], [role=checkbox], summary, label') as HTMLElement | null;

            if (!el || el.closest('[data-no-sound]') || el.getAttribute('aria-disabled') === 'true' || (el as HTMLButtonElement).disabled) {
                return;
            }

            const role = el.getAttribute('role');
            play(role === 'switch' || role === 'checkbox' || role === 'tab' || el.getAttribute('aria-expanded') !== null ? 'toggle' : 'tap');
        };
        const move = (e: PointerEvent) => {
            const tile = (e.target as HTMLElement | null)?.closest?.('.mn-tile') as HTMLElement | null;

            if (tile) {
                const r = tile.getBoundingClientRect();
                tile.style.setProperty('--mx', `${e.clientX - r.left}px`);
                tile.style.setProperty('--my', `${e.clientY - r.top}px`);
            }
        };
        document.addEventListener('pointerdown', down, true);
        document.addEventListener('pointermove', move, { passive: true });

        return () => {
            document.removeEventListener('pointerdown', down, true);
            document.removeEventListener('pointermove', move);
        };
    }, [theme]);

    return null;
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
            <DemoBanner />
            <AnnouncementBanner />
            <MaintenanceBanner />
            {children}
            <ModuleLinkGuard />
            <MidnightFeel />
            <Toaster />
        </AppLayoutTemplate>
    );
}
