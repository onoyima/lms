import { Link, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { notificationsUnread = 0, auth } = usePage().props as { notificationsUnread?: number; auth?: { isPlatformAdmin?: boolean } };

    return (
        <header className="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex flex-1 items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
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
        </header>
    );
}
