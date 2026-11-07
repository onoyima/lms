import { usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';

/** The institution's mark inside an institution; the platform's own mark in the platform console. */
export default function AppLogo() {
    const { tenant, platform } = usePage().props;
    const logo = tenant ? tenant.logo_url : platform?.logo_url;

    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center overflow-hidden rounded-md bg-brand text-brand-foreground">
                {logo ? <img src={logo} alt="" className="size-full object-cover" /> : <AppLogoIcon className="size-5 fill-current" />}
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-tight font-semibold">{tenant?.short_name ?? platform?.short_name}</span>
                <span className="truncate text-[11px] text-muted-foreground">{platform?.tagline}</span>
            </div>
        </>
    );
}
