import { Head, useForm } from '@inertiajs/react';
import { Field, Flash, inputClass, PageHeader } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';
import { compressImage } from '@/lib/compress-image';
import type { PlatformBrand } from '@/types/platform';

type Settings = { brand_name: string; brand_short_name: string; brand_tagline: string; brand_color: string; support_email: string };

/** The platform's own identity: shown on the public site, sign-in at the platform address, this console, platform emails and invoices. */
export default function PlatformBranding({ brand, settings }: { brand: PlatformBrand; settings: Settings }) {
    const form = useForm<Settings & { logo: File | null; favicon: File | null; remove_logo: boolean; remove_favicon: boolean }>({
        ...settings, brand_name: settings.brand_name || brand.name, logo: null, favicon: null, remove_logo: false, remove_favicon: false,
    });

    return (
        <>
            <Head title="Platform branding" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title="Platform branding" lead="Your service's own name and look. Each institution sets its own branding for its portal; this is used wherever no institution is in view." />
                <Flash />
                <form className="grid max-w-3xl gap-4 md:grid-cols-2" onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/platform/branding', { forceFormData: true, preserveScroll: true });
                }}>
                    <Field label="Platform name" error={form.errors.brand_name}><input className={inputClass} value={form.data.brand_name} onChange={(e) => form.setData('brand_name', e.target.value)} required /></Field>
                    <Field label="Short name" hint="Browser tab and menu; blank uses the full name" error={form.errors.brand_short_name}><input className={inputClass} value={form.data.brand_short_name} onChange={(e) => form.setData('brand_short_name', e.target.value)} /></Field>
                    <Field label="Line under the name" error={form.errors.brand_tagline}><input className={inputClass} value={form.data.brand_tagline} onChange={(e) => form.setData('brand_tagline', e.target.value)} /></Field>
                    <Field label="Support email" error={form.errors.support_email}><input type="email" className={inputClass} value={form.data.support_email} onChange={(e) => form.setData('support_email', e.target.value)} /></Field>
                    <Field label="Main colour" error={form.errors.brand_color}>
                        <span className="flex items-center gap-2">
                            <input type="color" aria-label="Pick colour" className="h-10 w-12 rounded-md border border-input" value={form.data.brand_color} onChange={(e) => form.setData('brand_color', e.target.value)} />
                            <input className={`${inputClass} font-mono`} value={form.data.brand_color} onChange={(e) => form.setData('brand_color', e.target.value)} />
                        </span>
                    </Field>
                    <div />
                    <Field label="Logo (PNG, JPG or WebP)" error={form.errors.logo}>
                        <input type="file" accept="image/png,image/jpeg,image/webp" className="text-sm" onChange={async (e) => {
                            const file = e.target.files?.[0];
                            form.setData('logo', file ? await compressImage(file, { maxWidth: 1024, maxHeight: 1024 }) : null);
                        }} />
                        {brand.logo_url && <span className="flex items-center gap-2 text-xs text-muted-foreground"><img src={brand.logo_url} alt="" className="size-6 rounded object-cover" /> current <label className="flex items-center gap-1"><input type="checkbox" checked={form.data.remove_logo} onChange={(e) => form.setData('remove_logo', e.target.checked)} /> remove</label></span>}
                    </Field>
                    <Field label="Browser tab icon (PNG or ICO)" error={form.errors.favicon}>
                        <input type="file" accept="image/png,image/x-icon,.ico" className="text-sm" onChange={async (e) => {
                            const file = e.target.files?.[0];
                            form.setData('favicon', file ? await compressImage(file, { maxWidth: 256, maxHeight: 256 }) : null);
                        }} />
                        {brand.favicon_url && <span className="flex items-center gap-2 text-xs text-muted-foreground"><img src={brand.favicon_url} alt="" className="size-4" /> current <label className="flex items-center gap-1"><input type="checkbox" checked={form.data.remove_favicon} onChange={(e) => form.setData('remove_favicon', e.target.checked)} /> remove</label></span>}
                    </Field>
                    <div className="md:col-span-2"><Button disabled={form.processing}>Save branding</Button></div>
                </form>
            </div>
        </>
    );
}

PlatformBranding.layout = { breadcrumbs: [{ title: 'Platform branding', href: '/platform/branding' }] };
