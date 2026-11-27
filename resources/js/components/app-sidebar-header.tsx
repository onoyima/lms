import { Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Bell, CircleHelp, House, Moon, Search, Sun } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { UserMenuContent } from '@/components/user-menu-content';
import { useAppearance } from '@/hooks/use-appearance';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

type Hit = { title: string; meta: string; href: string };

const initials = (name: string) => name.split(' ').filter(Boolean).map((n) => n[0]).slice(0, 2).join('').toUpperCase();

/** Search across people, exams, courses and questions; Ctrl+K (or ⌘K) jumps here from anywhere. */
function QuickSearch() {
    const [q, setQ] = useState('');
    const [groups, setGroups] = useState<{ label: string; items: Hit[] }[]>([]);
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const input = useRef<HTMLInputElement>(null);
    const flat = groups.flatMap((g) => g.items);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                input.current?.focus();
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    useEffect(() => {
        if (q.trim().length < 2) {
            return;
        }

        const ctrl = new AbortController();
        const t = window.setTimeout(() => {
            fetch(`/quick-search?q=${encodeURIComponent(q.trim())}`, { headers: { Accept: 'application/json' }, signal: ctrl.signal })
                .then((r) => (r.ok ? r.json() : { groups: [] }))
                .then((d: { groups: { label: string; items: Hit[] }[] }) => {
                    setGroups(d.groups);
                    setActive(0);
                })
                .catch(() => {});
        }, 180);

        return () => (ctrl.abort(), window.clearTimeout(t));
    }, [q]);

    const go = (h: Hit | undefined) => {
        if (h) {
            setOpen(false);
            setQ('');
            router.visit(h.href);
        }
    };
    let n = -1;

    return (
        <div className="relative w-full max-w-xl">
            <label className="flex h-10 items-center gap-2.5 rounded-xl px-3 text-sm text-muted-foreground transition-colors focus-within:bg-card/80 hover:bg-card/60">
                <Search className="size-4 shrink-0" />
                <input
                    ref={input}
                    value={q}
                    onChange={(e) => {
                        setQ(e.target.value);
                        setOpen(true);

                        if (e.target.value.trim().length < 2) {
                            setGroups([]);
                        }
                    }}
                    onFocus={() => setOpen(true)}
                    onBlur={() => window.setTimeout(() => setOpen(false), 150)}
                    onKeyDown={(e) => {
                        if (e.key === 'ArrowDown') {
                            e.preventDefault();
                            setActive((a) => Math.min(flat.length - 1, a + 1));
                        } else if (e.key === 'ArrowUp') {
                            e.preventDefault();
                            setActive((a) => Math.max(0, a - 1));
                        } else if (e.key === 'Enter') {
                            go(flat[active]);
                        } else if (e.key === 'Escape') {
                            setOpen(false);
                            input.current?.blur();
                        }
                    }}
                    placeholder="Search students, exams, courses or questions"
                    aria-label="Search"
                    role="combobox"
                    aria-expanded={open && flat.length > 0}
                    className="h-full w-full bg-transparent text-foreground outline-none placeholder:text-muted-foreground"
                />
                <kbd className="hidden whitespace-nowrap rounded-md border border-border bg-card px-1.5 text-[11px] font-medium text-muted-foreground sm:block">Ctrl K</kbd>
            </label>
            {open && q.trim().length >= 2 && (
                <div role="listbox" className="glass absolute top-12 right-0 left-0 z-50 max-h-[70vh] overflow-auto rounded-2xl p-2 [animation:rise-in_.16s_ease-out]">
                    {flat.length === 0 && <p className="px-3 py-2.5 text-sm text-muted-foreground">Nothing matches “{q.trim()}”.</p>}
                    {groups.map((g) => (
                        <div key={g.label} className="py-1">
                            <p className="px-3 pt-1 pb-1 text-xs font-medium text-muted-foreground">{g.label}</p>
                            {g.items.map((h) => {
                                n++;
                                const i = n;

                                return (
                                    <button key={h.href + h.title} type="button" role="option" aria-selected={i === active} onMouseEnter={() => setActive(i)} onMouseDown={(e) => (e.preventDefault(), go(h))}
                                        className={`flex w-full items-center justify-between gap-3 rounded-xl px-3 py-2 text-left text-sm ${i === active ? 'bg-brand-soft text-foreground' : ''}`}>
                                        <span className="truncate">{h.title}</span>
                                        <span className="shrink-0 text-xs text-muted-foreground">{h.meta}</span>
                                    </button>
                                );
                            })}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

/**
 * Back and Home on every page. Back returns to the previous page of the app when there is one,
 * otherwise to the page above this one (its breadcrumb or the address one level up), so it never
 * leaves the platform or does nothing.
 */
function BackHome({ breadcrumbs, home }: { breadcrumbs: BreadcrumbItemType[]; home: string }) {
    const { url } = usePage();
    const path = url.split(/[?#]/)[0];
    const atHome = path === home || path === '/';
    const cls = 'grid size-9 shrink-0 place-items-center rounded-xl text-foreground/80 transition-colors hover:bg-card hover:text-foreground';

    const back = () => {
        const inside = typeof document !== 'undefined' && document.referrer.startsWith(window.location.origin) && window.history.length > 1;

        if (inside) {
            window.history.back();

            return;
        }

        const parent = breadcrumbs.length > 1 ? String(breadcrumbs[breadcrumbs.length - 2].href) : path.replace(/\/[^/]+\/?$/, '') || home;
        router.visit(parent && parent !== path ? parent : home);
    };

    if (atHome) {
        return null;
    }

    return (
        <div className="flex shrink-0 items-center">
            <button type="button" onClick={back} className={cls} aria-label="Back" title="Back">
                <ArrowLeft className="size-[18px]" />
            </button>
            <Link href={home} className={cls} aria-label="Home" title="Home">
                <House className="size-[18px]" />
            </Link>
        </div>
    );
}

export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    const { notificationsUnread = 0, auth } = usePage().props as { notificationsUnread?: number; auth?: { user?: { name: string; avatar?: string | null; email: string } | null; isPlatformAdmin?: boolean } };
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const icon = 'grid size-10 place-items-center rounded-xl text-foreground/80 transition-colors hover:bg-card hover:text-foreground';

    return (
        <header className="sticky top-0 z-30 px-4 pt-3 md:px-6">
            <div className="glass flex h-14 items-center gap-2 rounded-2xl px-2.5">
                <SidebarTrigger className="size-9 rounded-xl" />
                <BackHome breadcrumbs={breadcrumbs} home={auth?.isPlatformAdmin ? '/platform' : '/dashboard'} />
                {breadcrumbs.length > 1 && <div className="hidden min-w-0 shrink lg:block"><Breadcrumbs breadcrumbs={breadcrumbs} /></div>}
                <div className="flex flex-1 justify-center">{!auth?.isPlatformAdmin && <QuickSearch />}</div>
                {!auth?.isPlatformAdmin && (
                    <Link href="/notifications" className="relative grid size-10 place-items-center rounded-xl text-foreground/80 transition-colors hover:bg-card hover:text-foreground"
                        aria-label={notificationsUnread ? `Notifications, ${notificationsUnread} unread` : 'Notifications'}>
                        <Bell className="size-[18px]" />
                        {notificationsUnread > 0 && (
                            <span className="absolute top-1 right-1 min-w-[18px] rounded-full bg-[var(--status-critical)] px-1 text-center text-[10.5px] leading-[18px] font-semibold text-white ring-2 ring-card">
                                {notificationsUnread > 99 ? '99+' : notificationsUnread}
                            </span>
                        )}
                    </Link>
                )}
                <Link href={auth?.isPlatformAdmin ? '/platform/docs' : '/docs'} aria-label="Help" className="grid size-10 place-items-center rounded-xl text-foreground/80 transition-colors hover:bg-card hover:text-foreground">
                    <CircleHelp className="size-[18px]" />
                </Link>
                <button type="button" className={icon} onClick={() => updateAppearance(resolvedAppearance === 'dark' ? 'light' : 'dark')}
                    aria-label={resolvedAppearance === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}>
                    {resolvedAppearance === 'dark' ? <Sun className="size-[18px]" /> : <Moon className="size-[18px]" />}
                </button>
                {auth?.user && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button type="button" className="ml-0.5 grid size-10 place-items-center rounded-xl transition-colors hover:bg-card focus:outline-none focus-visible:ring-2 focus-visible:ring-ring" aria-label="Your account">
                                <Avatar className="size-8 overflow-hidden rounded-full">
                                    <AvatarImage src={auth.user.avatar ?? undefined} alt={auth.user.name} />
                                    <AvatarFallback className="rounded-full bg-brand text-[12px] font-semibold text-brand-foreground">{initials(auth.user.name)}</AvatarFallback>
                                </Avatar>
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent className="w-56" align="end">
                            <UserMenuContent user={auth.user as never} />
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>
        </header>
    );
}
