import { Link, usePage } from '@inertiajs/react';
import { Bell, LogOut, Moon, Sun } from 'lucide-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { UserMenuContent } from '@/components/user-menu-content';
import { useAppearance } from '@/hooks/use-appearance';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

function getInitials(name: string): string {
    return name
        .split(' ')
        .map((n) => n[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { notificationsUnread = 0, auth } = usePage().props as { notificationsUnread?: number; auth?: { user?: { name: string; avatar?: string | null; email: string } | null; isPlatformAdmin?: boolean } };
    const { resolvedAppearance, updateAppearance } = useAppearance();

    return (
        <header className="sticky top-0 z-50 flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/50 bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex flex-1 items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            <div className="flex items-center gap-2">
                {!auth?.isPlatformAdmin && (
                    <Link href="/notifications" className="relative rounded-full p-2 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                        aria-label={notificationsUnread ? `Notifications, ${notificationsUnread} unread` : 'Notifications'}>
                        <Bell className="size-5" />
                        {notificationsUnread > 0 && (
                            <span className="absolute -top-0.5 -right-0.5 min-w-5 rounded-full bg-brand px-1 text-center text-[11px] leading-5 font-medium text-brand-foreground">
                                {notificationsUnread > 99 ? '99+' : notificationsUnread}
                            </span>
                        )}
                    </Link>
                )}
                <button
                    onClick={() => updateAppearance(resolvedAppearance === 'dark' ? 'light' : 'dark')}
                    className="rounded-full p-2 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    aria-label={resolvedAppearance === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}
                >
                    {resolvedAppearance === 'dark' ? <Sun className="size-5" /> : <Moon className="size-5" />}
                </button>
                {auth.user && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button className="relative rounded-full size-10 p-0.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus:outline-none focus:ring-2 focus:ring-ring">
                                <Avatar className="size-9 overflow-hidden rounded-full">
                                    <AvatarImage src={auth.user.avatar} alt={auth.user.name} />
                                    <AvatarFallback className="rounded-lg bg-muted text-muted-foreground">
                                        {getInitials(auth.user.name)}
                                    </AvatarFallback>
                                </Avatar>
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent className="w-56" align="end">
                            <UserMenuContent user={auth.user} />
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>
        </header>
    );
}
