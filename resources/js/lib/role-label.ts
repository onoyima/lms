import { usePage } from '@inertiajs/react';

/** The institution's own name for a role (roles are created and renamed per institution). */
export function useRoleLabel() {
    const labels = ((usePage().props as { roleLabels?: Record<string, string> }).roleLabels ?? {}) as Record<string, string>;

    return (role: string) => labels[role] ?? role.replace(/_/g, ' ');
}
