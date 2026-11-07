import { Head, router } from '@inertiajs/react';
import { AuditBrowser } from '@/components/platform/audit-table';
import type { AuditProps } from '@/components/platform/audit-table';
import { inputClass, PageHeader } from '@/components/platform/ui';

/** The platform's own audit trail, or one institution's for support and investigations (viewing is itself recorded). */
export default function PlatformAudit(p: AuditProps & { institutions: { id: number; name: string }[] }) {
    return (
        <>
            <Head title="Audit trail" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title="Audit" accent="trail" lead="Platform actions, or one institution's activity. Opening an institution's trail is recorded in it." />
                <AuditBrowser {...p} base="/platform/audit" extra={
                    <select aria-label="Whose trail" className={inputClass} value={p.filters.tenant ?? ''} onChange={(e) => router.get('/platform/audit', e.target.value ? { tenant: e.target.value } : {})}>
                        <option value="">Platform (this console)</option>
                        {p.institutions.map((i) => <option key={i.id} value={i.id}>{i.name}</option>)}
                    </select>
                } />
            </div>
        </>
    );
}

PlatformAudit.layout = { breadcrumbs: [{ title: 'Audit trail', href: '/platform/audit' }] };
