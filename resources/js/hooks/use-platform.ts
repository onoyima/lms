import { usePage } from '@inertiajs/react';

/**
 * Which modules this institution has, and what the signed-in user may do.
 * Platform administrators hold no institution permissions: they run the platform console only.
 */
export function usePlatform() {
    const { modules, auth, tenant } = usePage().props;
    const permissions: string[] = auth?.permissions ?? [];
    const roles: string[] = (usePage().props as { roles?: string[] }).roles ?? [];

    return {
        tenant,
        hasModule: (key: string) => (modules ?? []).includes(key),
        can: (permission: string) => permissions.includes(permission),
        canAny: (...list: string[]) => list.some((p) => permissions.includes(p)),
        hasRole: (...list: string[]) => list.some((r) => roles.includes(r)),
        isPlatformAdmin: Boolean(auth?.isPlatformAdmin),
        userType: (auth?.user as { user_type?: string } | undefined)?.user_type ?? null,
    };
}
