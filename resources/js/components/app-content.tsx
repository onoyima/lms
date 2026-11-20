import * as React from 'react';
import { SidebarInset } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import type { AppVariant } from '@/types';

type Props = React.ComponentProps<'main'> & {
    variant?: AppVariant;
};

/**
 * The page area beside the menu. It scrolls on its own so the glass top bar stays in place
 * (marked as an Inertia scroll region so each new page starts at the top); the colour wash and
 * the picture behind the glass come from `page-wash`.
 */
export function AppContent({ variant = 'sidebar', children, className, ...props }: Props) {
    if (variant === 'sidebar') {
        return (
            <SidebarInset {...props} {...{ 'scroll-region': '' }} className={cn('page-wash h-svh overflow-y-auto lg:peer-data-[variant=inset]:h-[calc(100svh-1rem)]', className)}>
                {children}
            </SidebarInset>
        );
    }

    return (
        <main className={cn('mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-4 rounded-xl', className)} {...props}>
            {children}
        </main>
    );
}
