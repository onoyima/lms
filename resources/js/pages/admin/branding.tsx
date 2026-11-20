import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { compressImage } from '@/lib/compress-image';
import { readableTextOn } from '@/lib/contrast';

type Branding = {
    name: string; short_name: string; theme?: string; brand: string; logo_url: string | null; domains: string[]; accent: string | null; favicon_url: string | null;
    background_mode?: 'theme' | 'own' | 'none'; background_url?: string | null;
    login_title: string | null; login_message: string | null; welcome_message: string | null; contact: { email?: string; phone?: string; address?: string } | null;
};
type Form = {
    name: string; short_name: string; theme: string; brand_color: string; logo: File | null; secondary_color: string; favicon: File | null; remove_favicon: boolean;
    background_mode: 'theme' | 'own' | 'none'; background: File | null;
    login_title: string; login_message: string; welcome_message: string; official_email: string; phone: string; address: string;
};

const THEMES = [
    { key: 'sapphire', name: 'Sapphire', deep: '#0b1f4b', primary: '#3d7bff', canvas: '#f2f5fb' },
    { key: 'forest', name: 'Forest & gold', deep: '#08362b', primary: '#d9a43a', canvas: '#f1f5f2' },
    { key: 'plum', name: 'Plum', deep: '#1e1240', primary: '#8b6cff', canvas: '#f4f2fb' },
];

export default function BrandingPage({ branding }: { branding: Branding }) {
    const { flash } = usePage().props;
    const [preview, setPreview] = useState<string | null>(null);
    const form = useForm<Form>({
        name: branding.name,
        short_name: branding.short_name,
        theme: branding.theme ?? 'sapphire',
        brand_color: branding.brand,
        logo: null,
        secondary_color: branding.accent ?? '',
        favicon: null,
        remove_favicon: false,
        background_mode: branding.background_mode ?? 'theme',
        background: null,
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
                        <h1 className="text-[1.9rem] leading-tight font-semibold">
                            Your look
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Pick the colour theme for menus, buttons and highlights. Your own colour is used for your crest, on sign-in pages and on printed documents.
                        </p>
                    </div>
                    {flash?.success && <p className="text-sm text-status-good">{flash.success}</p>}
                    <fieldset className="grid gap-2">
                        <legend className="mb-2 text-sm font-medium">Theme</legend>
                        <div className="grid grid-cols-3 gap-3">
                            {THEMES.map((t) => (
                                <button key={t.key} type="button" aria-pressed={form.data.theme === t.key} onClick={() => {
                                    form.setData('theme', t.key);
                                    document.documentElement.dataset.theme = t.key; // preview straight away
                                }}
                                className={`group overflow-hidden rounded-2xl border text-left transition-[transform,box-shadow] hover:-translate-y-0.5 ${form.data.theme === t.key ? 'border-brand shadow-[0_0_0_3px_var(--brand-soft)]' : 'border-border'}`}>
                                    <span className="flex h-20" style={{ background: t.deep }}>
                                        <span className="w-1/3 border-r border-white/10" />
                                        <span className="flex flex-1 flex-col justify-end gap-1.5 p-2.5" style={{ background: t.canvas }}>
                                            <i className="h-2 w-3/4 rounded-full" style={{ background: t.deep, opacity: 0.85 }} />
                                            <i className="h-3.5 w-1/2 rounded-md" style={{ background: t.primary }} />
                                        </span>
                                    </span>
                                    <span className="flex items-center justify-between bg-card px-3 py-2 text-sm font-semibold">{t.name}{form.data.theme === t.key && <span className="size-2 rounded-full bg-brand" />}</span>
                                </button>
                            ))}
                        </div>
                        <InputError message={form.errors.theme} />
                    </fieldset>
                    <fieldset className="grid gap-2">
                        <legend className="mb-2 text-sm font-medium">Picture behind the glass</legend>
                        <div className="grid gap-3 sm:grid-cols-3">
                            {([
                                ['theme', "The theme's campus", `url(/images/backgrounds/${form.data.theme}.webp)`],
                                ['own', 'Our own photo', preview ? `url(${preview})` : branding.background_url ? `url(${branding.background_url})` : 'none'],
                                ['none', 'No picture', 'none'],
                            ] as const).map(([key, label, img]) => (
                                <button key={key} type="button" aria-pressed={form.data.background_mode === key}
                                    onClick={() => {
                                        form.setData('background_mode', key);
                                        const pic = key === 'none' ? 'none' : key === 'own' ? (img === 'none' ? '' : img) : '';

                                        if (pic) {
                                            document.documentElement.style.setProperty('--wash-image', pic);
                                        } else {
                                            document.documentElement.style.removeProperty('--wash-image');
                                        }
                                    }}
                                    className={`overflow-hidden rounded-2xl border text-left ${form.data.background_mode === key ? 'border-brand shadow-[0_0_0_3px_var(--brand-soft)]' : 'border-border'}`}>
                                    <span className="block h-20 bg-muted bg-cover bg-center" style={{ backgroundImage: img }} />
                                    <span className="block bg-card px-3 py-2 text-sm font-semibold">{label}</span>
                                </button>
                            ))}
                        </div>
                        {form.data.background_mode === 'own' && (
                            <div className="grid gap-2">
                                <Label htmlFor="background">Upload a wide photo (JPG, PNG or WebP, at least 1200 × 500; large photos are made smaller for you)</Label>
                                <Input id="background" type="file" accept="image/jpeg,image/png,image/webp" onChange={async (e) => {
                                    const picked = e.target.files?.[0];
                                    const f = picked ? await compressImage(picked, { maxWidth: 2400, maxHeight: 1400 }) : null;
                                    form.setData('background', f);
                                    setPreview(f ? URL.createObjectURL(f) : null);
                                }} />
                                <p className="text-xs text-muted-foreground">A photo of your campus works well. It sits softly behind the top of every page, the sign-in page and the glass panels.</p>
                                <InputError message={form.errors.background} />
                            </div>
                        )}
                    </fieldset>
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
                        <Label htmlFor="brand_color">Your colour (crest, sign-in, documents)</Label>
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
                        <Input id="logo" type="file" accept="image/png,image/jpeg,image/webp" onChange={async (e) => {
                            const file = e.target.files?.[0];
                            form.setData('logo', file ? await compressImage(file, { maxWidth: 1024, maxHeight: 1024 }) : null);
                        }} />
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
                        <Input id="favicon" type="file" accept="image/png,image/x-icon,.ico" onChange={async (e) => {
                            const file = e.target.files?.[0];
                            form.setData('favicon', file ? await compressImage(file, { maxWidth: 256, maxHeight: 256 }) : null);
                        }} />
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
                    <div className="auth-deep relative overflow-hidden rounded-2xl p-6 text-white shadow-[0_24px_50px_-28px_var(--deep)]"
                        style={form.data.background_mode === 'none' ? { ['--wash-image' as string]: 'none' } : preview && form.data.background_mode === 'own' ? { ['--wash-image' as string]: `url(${preview})` } : undefined}>
                        <div className="flex items-center gap-3">
                            <span className="grid size-10 shrink-0 place-items-center overflow-hidden rounded-xl text-sm font-bold" style={{ background: form.data.brand_color, color: readableTextOn(form.data.brand_color) }}>
                                {branding.logo_url ? <img src={branding.logo_url} alt="" className="size-full bg-white object-contain p-1" /> : (form.data.short_name || form.data.name).slice(0, 1)}
                            </span>
                            <span className="min-w-0"><b className="block truncate font-display">{form.data.name}</b><span className="text-xs text-white/60">Sign-in page</span></span>
                        </div>
                        <p className="mt-10 font-display text-xl leading-snug font-semibold">{form.data.login_title || 'Log in to your account'}</p>
                        <div className="mt-4 grid gap-2 rounded-xl border border-white/15 bg-white/10 p-3 backdrop-blur">
                            <i className="h-7 rounded-md bg-white/80" />
                            <i className="h-7 rounded-md bg-white/80" />
                            <i className="h-8 rounded-md bg-brand" />
                        </div>
                    </div>
                    <p className="text-xs text-muted-foreground">The theme and picture change here and around this page as you choose them. Save to apply them for everyone.</p>
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
