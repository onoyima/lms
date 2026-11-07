import { Head, useForm, usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { readableTextOn } from '@/lib/contrast';

type Branding = {
    name: string; short_name: string; brand: string; logo_url: string | null; domains: string[]; accent: string | null; favicon_url: string | null;
    login_title: string | null; login_message: string | null; welcome_message: string | null; contact: { email?: string; phone?: string; address?: string } | null;
};
type Form = {
    name: string; short_name: string; brand_color: string; logo: File | null; secondary_color: string; favicon: File | null; remove_favicon: boolean;
    login_title: string; login_message: string; welcome_message: string; official_email: string; phone: string; address: string;
};

export default function BrandingPage({ branding }: { branding: Branding }) {
    const { flash } = usePage().props;
    const form = useForm<Form>({
        name: branding.name,
        short_name: branding.short_name,
        brand_color: branding.brand,
        logo: null,
        secondary_color: branding.accent ?? '',
        favicon: null,
        remove_favicon: false,
        login_title: branding.login_title ?? '',
        login_message: branding.login_message ?? '',
        welcome_message: branding.welcome_message ?? '',
        official_email: branding.contact?.email ?? '',
        phone: branding.contact?.phone ?? '',
        address: branding.contact?.address ?? '',
    });
    const text = (k: keyof Form, label: string, hint?: string, area = false) => (
        <div className="grid gap-2">
            <Label htmlFor={k}>{label}</Label>
            {area
                ? <textarea id={k} className="min-h-20 rounded-md border border-input bg-transparent px-3 py-2 text-sm" value={form.data[k] as string} onChange={(e) => form.setData(k, e.target.value as never)} maxLength={500} />
                : <Input id={k} value={form.data[k] as string} onChange={(e) => form.setData(k, e.target.value as never)} />}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            <InputError message={form.errors[k]} />
        </div>
    );

    return (
        <>
            <Head title="Branding" />
            <div className="grid gap-10 p-6 md:p-10 lg:grid-cols-[1fr_380px]">
                <form
                    className="max-w-xl space-y-6"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/admin/branding', { forceFormData: true, preserveScroll: true });
                    }}
                >
                    <div>
                        <h1 className="text-[1.75rem] leading-tight font-semibold">
                            Your look
                        </h1>
                        <p className="mt-3 text-sm text-muted-foreground">
                            Your colour is used for main buttons, highlights and the selected answer in exams. Text on it switches
                            between black and white automatically so it stays readable.
                        </p>
                    </div>
                    {flash?.success && <p className="text-sm text-status-good">{flash.success}</p>}
                    <div className="grid gap-2">
                        <Label htmlFor="name">Institution name</Label>
                        <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                        <InputError message={form.errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="short_name">Short name</Label>
                        <Input id="short_name" value={form.data.short_name} onChange={(e) => form.setData('short_name', e.target.value)} />
                        <InputError message={form.errors.short_name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="brand_color">Brand colour</Label>
                        <div className="flex items-center gap-3">
                            <input
                                id="brand_color"
                                type="color"
                                className="h-10 w-14 cursor-pointer rounded-lg border border-input bg-transparent"
                                value={form.data.brand_color}
                                onChange={(e) => form.setData('brand_color', e.target.value)}
                            />
                            <Input className="font-mono" value={form.data.brand_color} onChange={(e) => form.setData('brand_color', e.target.value)} />
                        </div>
                        <InputError message={form.errors.brand_color} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="logo">Logo (PNG/JPG, square works best)</Label>
                        <Input id="logo" type="file" accept="image/png,image/jpeg,image/webp" onChange={(e) => form.setData('logo', e.target.files?.[0] ?? null)} />
                        <InputError message={form.errors.logo} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="secondary_color">Second colour (optional)</Label>
                        <div className="flex items-center gap-3">
                            <input id="secondary_color" type="color" className="h-10 w-14 cursor-pointer rounded-lg border border-input bg-transparent"
                                value={form.data.secondary_color || '#6366f1'} onChange={(e) => form.setData('secondary_color', e.target.value)} />
                            <Input className="font-mono" placeholder="none" value={form.data.secondary_color} onChange={(e) => form.setData('secondary_color', e.target.value)} />
                        </div>
                        <p className="text-xs text-muted-foreground">Used with the main colour in gradients on the sign-in page and the dashboard.</p>
                        <InputError message={form.errors.secondary_color} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="favicon">Browser tab icon (PNG or ICO, small square)</Label>
                        <Input id="favicon" type="file" accept="image/png,image/x-icon,.ico" onChange={(e) => form.setData('favicon', e.target.files?.[0] ?? null)} />
                        {branding.favicon_url && (
                            <label className="flex items-center gap-2 text-xs text-muted-foreground">
                                <img src={branding.favicon_url} alt="" className="size-4" /> Current icon
                                <input type="checkbox" checked={form.data.remove_favicon} onChange={(e) => form.setData('remove_favicon', e.target.checked)} /> remove
                            </label>
                        )}
                        <InputError message={form.errors.favicon} />
                    </div>
                    <h2 className="pt-4 text-lg font-medium">Sign-in page and dashboard</h2>
                    {text('login_title', 'Sign-in heading', 'For example "Welcome to the e-Learning portal". Blank uses the standard heading.')}
                    {text('login_message', 'Sign-in message', 'A line under the heading, e.g. which number to sign in with.', true)}
                    {text('welcome_message', 'Dashboard welcome message', 'Shown to everyone under "Welcome back".', true)}
                    <h2 className="pt-4 text-lg font-medium">Contact details</h2>
                    {text('official_email', 'Official email', 'Shown on the sign-in page for help.')}
                    {text('phone', 'Phone')}
                    {text('address', 'Address', undefined, true)}
                    <Button disabled={form.processing}>Save branding</Button>
                </form>

                <aside className="space-y-4">
                    <p className="eyebrow">Preview</p>
                    <div className="rounded-lg border border-white/10 bg-[#0b0d10] p-8 text-white" style={{ ['--brand' as string]: form.data.brand_color, backgroundImage: form.data.secondary_color ? `linear-gradient(135deg, #0b0d10 60%, ${form.data.secondary_color}55)` : undefined }}>
                        <p className="text-[11px] font-semibold tracking-[0.22em] text-white/60 uppercase">{form.data.short_name || form.data.name}</p>
                        <p className="mt-3 text-2xl font-semibold tracking-tight">
                            Exams that are <span style={{ color: form.data.brand_color }}>fair to every student.</span>
                        </p>
                        <span className="mt-6 inline-flex rounded-lg px-5 py-2.5 text-sm font-medium" style={{ background: form.data.brand_color, color: readableTextOn(form.data.brand_color) }}>
                            Start exam
                        </span>
                    </div>
                    {branding.domains.length > 0 && (
                        <p className="text-xs text-muted-foreground">
                            Addresses: <span className="font-mono">{branding.domains.join(', ')}</span>
                        </p>
                    )}
                </aside>
            </div>
        </>
    );
}

BrandingPage.layout = { breadcrumbs: [{ title: 'Branding', href: '/admin/branding' }] };
