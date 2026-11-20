import { usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';

/** The institution's crest (in its own colour) inside an institution; the platform's mark in the platform console. */
export default function AppLogo() {
    const { tenant, platform } = usePage().props;
    const logo = tenant ? tenant.logo_url : platform?.logo_url;

    return (
        <>
            <div className="flex aspect-square size-9 items-center justify-center overflow-hidden rounded-xl bg-[linear-gradient(135deg,var(--inst),color-mix(in_oklab,var(--inst)_55%,var(--glow)))] text-[var(--inst-foreground,#fff)] shadow-[0_6px_18px_-6px_var(--inst)]">
                {logo ? <img src={logo} alt="" className="size-full object-cover" /> : <AppLogoIcon className="size-5 fill-current" />}
            </div>
            <div className="ml-1 grid flex-1 text-left">
                <span className="truncate font-display text-[15px] leading-tight font-semibold text-white">{tenant?.short_name ?? platform?.short_name}</span>
                <span className="truncate text-[11.5px] text-sidebar-foreground/80">{platform?.tagline || 'Exams & learning'}</span>
            </div>
        </>
    );
}
