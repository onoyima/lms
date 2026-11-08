import { createInertiaApp, router } from '@inertiajs/react';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

/**
 * The name in the browser tab: the institution's short name inside an institution, otherwise the platform's
 * own brand (set by the platform administrator). Never a name baked in at build time.
 */
type BrandProps = { tenant?: { short_name?: string; name?: string } | null; platform?: { short_name?: string; name?: string } | null };
const brandOf = (props: BrandProps | undefined) => props?.tenant?.short_name || props?.tenant?.name || props?.platform?.short_name || props?.platform?.name || '';
let brand = '';

if (typeof document !== 'undefined') {
    brand = document.title; // the server writes the brand into the first page's <title>

    router.on('navigate', (event) => {
        brand = brandOf(event.detail.page.props as BrandProps);
    });
}

createInertiaApp({
    title: (title) => (title && brand ? `${title} - ${brand}` : title || brand),
    layout: (name) => {
        switch (true) {
            case name === 'welcome' || name === 'suspended' || name === 'maintenance' || name === 'candidate/exam-room' || name === 'candidate/phone' || name === 'verify' || name === 'meetings/room' || name === 'meetings/guest':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
