import { Head, useForm } from '@inertiajs/react';
import { Field, Flash, inputClass, PageHeader, textareaClass } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Settings = {
    trial_days: number; grace_days: number; invoice_days_before: number; reminder_days: number[]; default_upload_mb: number;
    billing_name: string; billing_address: string; billing_email: string; maintenance_mode: boolean; maintenance_message: string;
    rate_user_per_minute: number; rate_ip_per_minute: number; rate_key_per_minute: number; rate_tenant_per_minute: number;
};

/** Platform-wide billing rules. A package can set its own trial and grace period; these are the defaults. */
export default function PlatformSettingsPage({ settings }: { settings: Settings }) {
    const form = useForm({ ...settings, reminder_days: settings.reminder_days.join(', ') });
    const maint = useForm({ maintenance_mode: settings.maintenance_mode, maintenance_message: settings.maintenance_message });

    return (
        <>
            <Head title="Billing rules" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title="Billing" accent="rules" lead="Defaults for every institution. Changes apply from the next daily billing run; nothing already invoiced changes." />
                <Flash />
                <form className="surface grid gap-4 p-6 md:grid-cols-3" onSubmit={(e) => {
                    e.preventDefault();
                    form.transform((d) => ({ ...d, reminder_days: d.reminder_days.split(/[\s,]+/).filter(Boolean).map(Number) }));
                    form.put('/platform/settings', { preserveScroll: true });
                }}>
                    <Field label="Free trial (days)" hint="when a package does not set its own" error={form.errors.trial_days}>
                        <input type="number" min={0} max={365} className={inputClass} value={form.data.trial_days} onChange={(e) => form.setData('trial_days', Number(e.target.value))} />
                    </Field>
                    <Field label="Grace period (days)" hint="after an invoice is due, before access is suspended" error={form.errors.grace_days}>
                        <input type="number" min={0} max={180} className={inputClass} value={form.data.grace_days} onChange={(e) => form.setData('grace_days', Number(e.target.value))} />
                    </Field>
                    <Field label="Renewal invoice (days before)" hint="how early the renewal invoice is issued" error={form.errors.invoice_days_before}>
                        <input type="number" min={1} max={90} className={inputClass} value={form.data.invoice_days_before} onChange={(e) => form.setData('invoice_days_before', Number(e.target.value))} />
                    </Field>
                    <Field label="Reminders (days before due)" hint="comma-separated, e.g. 14, 7, 3, 1" error={form.errors.reminder_days}>
                        <input className={inputClass} value={form.data.reminder_days} onChange={(e) => form.setData('reminder_days', e.target.value)} />
                    </Field>
                    <Field label="Largest upload (MB)" hint="when a package does not set its own" error={form.errors.default_upload_mb}>
                        <input type="number" min={1} max={5120} className={inputClass} value={form.data.default_upload_mb} onChange={(e) => form.setData('default_upload_mb', Number(e.target.value))} />
                    </Field>
                    <div />
                    <Field label="Name on invoices and receipts" error={form.errors.billing_name}>
                        <input className={inputClass} value={form.data.billing_name} onChange={(e) => form.setData('billing_name', e.target.value)} required />
                    </Field>
                    <Field label="Billing email" error={form.errors.billing_email}>
                        <input type="email" className={inputClass} value={form.data.billing_email} onChange={(e) => form.setData('billing_email', e.target.value)} />
                    </Field>
                    <div />
                    <div className="md:col-span-2">
                        <Field label="Address on invoices and receipts" error={form.errors.billing_address}>
                            <textarea className={textareaClass} value={form.data.billing_address} onChange={(e) => form.setData('billing_address', e.target.value)} />
                        </Field>
                    </div>
                    <h2 className="pt-2 text-lg font-medium md:col-span-3">Request limits (per minute, 0 = none)</h2>
                    {([['rate_user_per_minute', 'Per signed-in person'], ['rate_ip_per_minute', 'Per address (not signed in)'], ['rate_key_per_minute', 'Per API key'], ['rate_tenant_per_minute', 'Per institution']] as const).map(([k, label]) => (
                        <Field key={k} label={label} error={form.errors[k]}>
                            <input type="number" min={0} className={inputClass} value={form.data[k]} onChange={(e) => form.setData(k, Number(e.target.value))} />
                        </Field>
                    ))}
                    <p className="text-xs text-muted-foreground md:col-span-3">The exam room has its own per-candidate limits, so a whole hall behind one campus address is never throttled together.</p>
                    <div className="md:col-span-3"><Button disabled={form.processing}>Save rules</Button></div>
                </form>
                <form className={`surface flex flex-col gap-3 p-6 ${settings.maintenance_mode ? 'border-status-warning' : ''}`} onSubmit={(e) => {
                    e.preventDefault();

                    if (!maint.data.maintenance_mode || confirm('Switch on maintenance mode? Every institution sees the maintenance page (exams in progress cannot continue) until you switch it off.')) {
                        maint.put('/platform/maintenance', { preserveScroll: true });
                    }
                }}>
                    <h2 className="text-lg font-medium">Maintenance mode</h2>
                    <p className="text-sm text-muted-foreground">While on, institution users see a maintenance page; platform administrators keep working. Plan exam-free times with each institution's own maintenance windows where possible.</p>
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={maint.data.maintenance_mode} onChange={(e) => maint.setData('maintenance_mode', e.target.checked)} /> Maintenance mode on</label>
                    <Field label="Message shown" error={maint.errors.maintenance_message}><input className={inputClass} value={maint.data.maintenance_message} onChange={(e) => maint.setData('maintenance_message', e.target.value)} maxLength={500} /></Field>
                    <div><Button variant={maint.data.maintenance_mode ? 'destructive' : 'default'} disabled={maint.processing}>Save maintenance mode</Button></div>
                </form>
            </div>
        </>
    );
}

PlatformSettingsPage.layout = { breadcrumbs: [{ title: 'Billing rules', href: '/platform/settings' }] };
