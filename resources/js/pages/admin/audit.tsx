import { Head } from '@inertiajs/react';
import { AuditBrowser } from '@/components/platform/audit-table';
import type { AuditProps } from '@/components/platform/audit-table';
import { PageHeader } from '@/components/platform/ui';

/** The institution's own audit trail: who did what, when, from where. */
export default function Audit(p: AuditProps) {
    return (
        <>
            <Head title="Audit trail" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title="Audit" accent="trail" lead="Every sign-in, permission change, export, payment and administrative action in your institution. Entries are chained so that any later change can be detected." />
                <AuditBrowser {...p} base="/admin/audit" />
            </div>
        </>
    );
}

Audit.layout = { breadcrumbs: [{ title: 'Audit trail', href: '/admin/audit' }] };
