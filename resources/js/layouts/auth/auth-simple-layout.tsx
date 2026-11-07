import { Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

/** Sign-in pages carry the institution's own logo, name and words when an institution is in context. */
export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const page = usePage();
    const tenant = page.props.tenant;
    const isLogin = page.url.split('?')[0] === '/login';

    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            {tenant && <div aria-hidden className="pointer-events-none fixed inset-x-0 top-0 h-1 bg-brand" />}
            <div className="w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <Link
                            href={home()}
                            className="flex flex-col items-center gap-2 font-medium"
                        >
                            {tenant?.logo_url ? (
                                <img src={tenant.logo_url} alt={tenant.name} className="mb-1 max-h-16 max-w-48 object-contain" />
                            ) : (
                                <div className="mb-1 flex h-9 w-9 items-center justify-center rounded-md">
                                    <AppLogoIcon className="size-9 fill-current text-[var(--foreground)] dark:text-white" />
                                </div>
                            )}
                            {tenant && <span className="text-sm text-muted-foreground">{tenant.name}</span>}
                        </Link>

                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-medium">{isLogin && tenant?.login_title ? tenant.login_title : title}</h1>
                            <p className="text-center text-sm text-muted-foreground">
                                {isLogin && tenant?.login_message ? tenant.login_message : description}
                            </p>
                        </div>
                    </div>
                    {children}
                    {tenant?.contact && (
                        <p className="text-center text-xs text-muted-foreground">
                            Help: {[tenant.contact.email, tenant.contact.phone].filter(Boolean).join(' · ')}
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}
