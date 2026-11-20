import { Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import {
    SidebarGroup,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
import type { NavItem } from '@/types';

const KEY = 'menu:open-groups';

function readOpen(): string[] {
    try {
        return JSON.parse(localStorage.getItem(KEY) ?? '[]') as string[];
    } catch {
        return [];
    }
}

/** Items shown one under the other (the top of the menu: dashboard, notifications…). */
export function NavMain({ items = [] }: { items: NavItem[]; label?: string }) {
    const { isCurrentUrl } = useCurrentUrl();

    if (items.length === 0) {
        return null;
    }

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarMenu className="gap-0.5">
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton asChild isActive={isCurrentUrl(item.href)} tooltip={{ children: item.title }} className="h-9 rounded-[10px] font-medium">
                            <Link href={item.href} prefetch>
                                {item.icon && <item.icon />}
                                <span>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}

/**
 * A folding group of related pages (Exams, Exam day, Teaching…). It opens by itself when one of
 * its pages is showing; otherwise it remembers whether the person left it open.
 */
export function NavGroup({ label, icon: Icon, items, badge }: { label: string; icon?: LucideIcon; items: NavItem[]; badge?: string | null }) {
    const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
    const { state, setOpen: setSidebarOpen } = useSidebar();
    // the group holding the page in view (also its sub-pages, e.g. one exam under "All exams")
    const containsCurrent = items.some((i) => isCurrentOrParentUrl(i.href));
    const [open, setOpen] = useState(() => containsCurrent || readOpen().includes(label));

    if (items.length === 0) {
        return null;
    }

    const toggle = () => {
        if (state === 'collapsed') {
            setSidebarOpen(true);
            setOpen(true);

            return;
        }

        const next = !open;
        setOpen(next);

        try {
            const list = readOpen().filter((x) => x !== label);
            localStorage.setItem(KEY, JSON.stringify(next ? [...list, label] : list));
        } catch {
            // private window: the group simply starts closed next time
        }
    };
    const shown = open || containsCurrent;

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton onClick={toggle} aria-expanded={shown} tooltip={{ children: label }} isActive={containsCurrent && state === 'collapsed'}
                        className={cn('h-9 rounded-[10px] font-medium', shown && 'text-sidebar-accent-foreground')}>
                        {Icon && <Icon />}
                        <span>{label}</span>
                        {badge && <span className="ml-auto rounded-full bg-[color-mix(in_oklab,var(--live)_30%,transparent)] px-2 text-[11px] leading-5 font-semibold text-[#c4f7e4] group-data-[collapsible=icon]:hidden">{badge}</span>}
                        <ChevronDown className={cn('size-4 opacity-70 transition-transform duration-200', !badge && 'ml-auto', shown && 'rotate-180')} />
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <div className={cn('grid transition-[grid-template-rows] duration-200 ease-out group-data-[collapsible=icon]:hidden', shown ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]')}>
                <ul className="ml-[18px] min-h-0 overflow-hidden border-l border-sidebar-border pl-2.5">
                    {items.map((item) => {
                        const on = isCurrentUrl(item.href) || (isCurrentOrParentUrl(item.href) && !items.some((x) => x !== item && String(x.href).length > String(item.href).length && isCurrentOrParentUrl(x.href)));

                        return (
                            <li key={item.title} className="py-px first:pt-1 last:pb-1.5">
                                <Link href={item.href} prefetch aria-current={on ? 'page' : undefined}
                                    className={cn('flex items-center gap-2 rounded-[9px] px-2.5 py-1.5 text-[13.5px] transition-[background,color,transform] duration-150 hover:translate-x-0.5 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground',
                                        on && 'bg-[color-mix(in_oklab,var(--brand)_24%,transparent)] text-white shadow-[inset_2px_0_0_var(--glow)]')}>
                                    <span className="truncate">{item.title}</span>
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            </div>
        </SidebarGroup>
    );
}
