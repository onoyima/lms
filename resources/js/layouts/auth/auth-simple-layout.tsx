import { Link, usePage } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

/**
 * Sign-in pages in the same design as the app: the institution's chosen theme (Branding > Theme)
 * gives the deep panel and the colours; the institution's logo, name and words are shown when an
 * institution is in context, otherwise the platform's.
 */
export default function AuthSimpleLayout({ children, title, description }: AuthLayoutProps) {
    const page = usePage();
    const { tenant, platform } = page.props as { tenant?: { name: string; short_name?: string; logo_url?: string | null; login_title?: string | null; login_message?: string | null; contact?: { email?: string; phone?: string } | null } | null; platform?: { name?: string; short_name?: string; logo_url?: string | null } };
    const isLogin = page.url.split('?')[0] === '/login';
    const name = tenant?.name ?? platform?.name ?? '';
    const logo = tenant?.logo_url ?? platform?.logo_url;

    return (
        <div className="grid min-h-svh bg-background lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
            <aside className="auth-deep relative flex flex-col justify-between gap-8 overflow-hidden px-6 py-6 text-white sm:px-10 lg:py-10">
                <Link href={home()} className="flex min-w-0 items-center gap-3">
                    <span className="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-gradient-to-br from-brand to-brand-ink text-brand-foreground shadow-[0_10px_24px_-10px_var(--brand)]">
                        {logo ? <img src={logo} alt="" className="size-full bg-white object-contain p-1" /> : <AppLogoIcon className="size-6 fill-current" />}
                    </span>
                    <span className="min-w-0">
                        <span className="block truncate font-display text-lg font-semibold">{name}</span>
                        <span className="block text-sm text-white/60">Exams &amp; learning</span>
                    </span>
                </Link>
                <div className="hidden max-w-md lg:block">
                    <p className="font-display text-4xl leading-tight font-semibold tracking-tight">Every answer kept, whatever happens to the connection.</p>
                    <p className="mt-4 text-white/70">Exams, courses and results in one place. Sign in with the account your institution gave you.</p>
                </div>
                <p className="hidden items-center gap-2 text-sm text-white/60 lg:flex"><ShieldCheck className="size-4" /> Your institution's records stay with your institution.</p>
            </aside>

            <main className="flex items-center justify-center px-4 py-10 sm:px-6">
                <div className="surface w-full max-w-md p-6 sm:p-8">
                    <div className="mb-7 space-y-2">
                        <h1 className="text-2xl font-semibold tracking-tight">{isLogin && tenant?.login_title ? tenant.login_title : title}</h1>
                        <p className="text-sm text-muted-foreground">{isLogin && tenant?.login_message ? tenant.login_message : description}</p>
                    </div>
                    {children}
                    {tenant?.contact && (tenant.contact.email || tenant.contact.phone) && (
                        <p className="mt-6 text-center text-xs text-muted-foreground">Help: {[tenant.contact.email, tenant.contact.phone].filter(Boolean).join(' · ')}</p>
                    )}
                </div>
            </main>
        </div>
    );
}
