import { Link, usePage } from '@inertiajs/react';
import { ShoppingBag } from 'lucide-react';
import type { ReactNode } from 'react';

type Shared = { tenant?: { name?: string } | null; auth?: { user?: { name: string } | null }; flash?: { success?: string | null; error?: string | null } };

/** The public frame for the shop: institution name, cart count, sign-in or "My test series". */
export function ShopShell({ children, cartCount }: { children: ReactNode; cartCount?: number }) {
    const { tenant, auth, flash } = usePage<Shared>().props;

    return (
        <div className="min-h-screen bg-background">
            <header className="border-b border-border bg-card/70">
                <div className="mx-auto flex w-full max-w-6xl flex-wrap items-center gap-3 px-4 py-3 sm:px-6">
                    <Link href="/shop" className="mr-auto min-w-0 truncate font-semibold">{tenant?.name ?? 'Test series'}</Link>
                    {auth?.user ? <Link href="/my/test-series" className="text-sm underline-offset-4 hover:underline">My test series</Link> : <Link href="/login" className="text-sm underline-offset-4 hover:underline">Sign in</Link>}
                    {cartCount !== undefined && (
                        <Link href="/shop/checkout" className="flex items-center gap-1.5 rounded-full border border-border px-3 py-1.5 text-sm" aria-label={`Cart, ${cartCount} items`}>
                            <ShoppingBag className="size-4" /> {cartCount}
                        </Link>
                    )}
                </div>
            </header>
            <main className="mx-auto w-full max-w-6xl px-4 py-6 sm:px-6 sm:py-10">
                {flash?.success && <p role="status" className="mb-4 rounded-xl border border-status-good/30 bg-status-good/10 px-4 py-3 text-sm">{flash.success}</p>}
                {flash?.error && <p role="alert" className="mb-4 rounded-xl border border-status-critical/30 bg-status-critical/10 px-4 py-3 text-sm">{flash.error}</p>}
                {children}
            </main>
        </div>
    );
}
