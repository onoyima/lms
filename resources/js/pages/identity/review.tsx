import { Head, Link, router } from '@inertiajs/react';
import { Check, Loader2, Trash2, Upload, X } from 'lucide-react';
import { useState } from 'react';
import { Flash, formatDateTime, PageHeader, Status } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';
import { readFace } from '@/lib/face';
import type { Paginated } from '@/types';

type Row = {
    id: number;
    user: { id: number; name: string; identifier: string | null; email: string } | null;
    status: string;
    note: string | null;
    id_distance: number | null;
    has_photo: boolean;
    has_id_photo: boolean;
    enrolled_at: string | null;
};

const loadImage = (file: File) =>
    new Promise<HTMLImageElement>((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = reject;
        img.src = URL.createObjectURL(file);
    });

/** Registry: compare enrolled faces with ID photos, verify or reject, and upload ID photos in bulk. */
export default function Review({ profiles, status, counts, threshold }: { profiles: Paginated<Row>; status: string; counts: Record<string, number>; threshold: number }) {
    const [log, setLog] = useState<string[]>([]);
    const [busy, setBusy] = useState(false);

    // ID photos named by matric number or email (e.g. VUG-CHM-26-0001.jpg); templates are computed here, in this browser
    const upload = async (files: FileList | null) => {
        if (!files?.length) {
            return;
        }

        setBusy(true);
        const out: string[] = [];

        for (const file of Array.from(files)) {
            const identifier = file.name.replace(/\.[^.]+$/, '').replace(/-/g, '/');

            try {
                const r = await readFace(await loadImage(file), true);

                if (r.faces !== 1 || !r.descriptor) {
                    out.push(`${file.name}: ${r.faces === 0 ? 'no face found' : 'more than one face'}`);
                    continue;
                }

                const fd = new FormData();
                fd.append('identifier', identifier);
                fd.append('descriptor', JSON.stringify(r.descriptor));
                fd.append('photo', file);
                await new Promise<void>((resolve) =>
                    router.post('/admin/identity/id-photo', fd, {
                        forceFormData: true, preserveScroll: true, preserveState: true,
                        onSuccess: () => out.push(`${file.name}: saved`),
                        onError: (e) => out.push(`${file.name}: ${Object.values(e).join(' ')}`),
                        onFinish: () => resolve(),
                    }),
                );
            } catch {
                out.push(`${file.name}: could not read the image`);
            }

            setLog([...out]);
        }

        setBusy(false);
    };

    return (
        <>
            <Head title="Identity checks" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader
                    title="Identity"
                    accent="verification"
                    lead={`Compare each enrolled face with the official ID photo. A difference score under ${threshold * 0.9} is verified automatically; everything else needs a person.`}
                    actions={
                        <label className={`flex cursor-pointer items-center gap-2 rounded-xl bg-brand px-4 py-2 text-sm font-medium text-brand-foreground ${busy ? 'opacity-60' : ''}`}>
                            {busy ? <Loader2 className="size-4 animate-spin" /> : <Upload className="size-4" />} Upload ID photos
                            <input type="file" accept="image/jpeg,image/png,image/webp" multiple className="hidden" disabled={busy} onChange={(e) => upload(e.target.files)} />
                        </label>
                    }
                />
                <Flash />
                <p className="text-xs text-muted-foreground">Name each photo after the matric number with dashes for slashes, e.g. <span className="font-mono">VUG-CHM-26-0001.jpg</span>, or after the person's email.</p>
                {log.length > 0 && <div className="surface max-h-40 overflow-y-auto p-3 font-mono text-xs">{log.map((l, i) => <p key={i}>{l}</p>)}</div>}
                <div className="flex flex-wrap gap-2 text-sm">
                    {['pending', 'verified', 'rejected', 'all'].map((s) => (
                        <Link key={s} href={`/admin/identity?status=${s}`} className={`rounded-xl border px-3 py-1.5 ${status === s ? 'border-brand bg-brand-soft' : 'border-border'}`}>
                            {s} {s !== 'all' && <span className="text-muted-foreground">{counts[s] ?? 0}</span>}
                        </Link>
                    ))}
                </div>
                {profiles.data.length === 0 && <div className="surface p-10 text-center text-sm text-muted-foreground">Nothing here.</div>}
                <div className="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                    {profiles.data.map((p) => (
                        <article key={p.id} className="surface grid gap-3 p-5">
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <p className="font-medium">{p.user?.name}</p>
                                    <p className="font-mono text-xs text-muted-foreground">{p.user?.identifier ?? p.user?.email}</p>
                                </div>
                                <Status value={p.status} />
                            </div>
                            <div className="grid grid-cols-2 gap-2 text-xs">
                                <figure>
                                    {p.has_photo ? <img src={`/admin/identity/${p.id}/photo/enrolment`} alt="Enrolled face" loading="lazy" className="aspect-square w-full rounded-lg object-cover" /> : <div className="grid aspect-square place-items-center rounded-lg bg-muted">not enrolled</div>}
                                    <figcaption className="mt-1">Enrolled {p.enrolled_at ? formatDateTime(p.enrolled_at) : ''}</figcaption>
                                </figure>
                                <figure>
                                    {p.has_id_photo ? <img src={`/admin/identity/${p.id}/photo/id`} alt="ID photo" loading="lazy" className="aspect-square w-full rounded-lg object-cover" /> : <div className="grid aspect-square place-items-center rounded-lg bg-muted">no ID photo</div>}
                                    <figcaption className="mt-1">ID photo</figcaption>
                                </figure>
                            </div>
                            <p className="text-xs">
                                Difference score: <strong className={p.id_distance !== null && p.id_distance >= threshold ? 'text-status-critical' : ''}>{p.id_distance ?? '—'}</strong> {p.note && <span className="text-muted-foreground">· {p.note}</span>}
                            </p>
                            <div className="flex gap-2">
                                <Button size="sm" disabled={!p.has_photo} onClick={() => router.post(`/admin/identity/${p.id}`, { approve: true }, { preserveScroll: true })}><Check className="size-4" /> Same person</Button>
                                <Button size="sm" variant="outline" onClick={() => {
 const note = prompt('Why? (shown to the person, who is asked to enrol again)');

 if (note) {
 router.post(`/admin/identity/${p.id}`, { approve: false, note }, { preserveScroll: true }); 
} 
}}><X className="size-4" /> Reject</Button>
                                <Button size="sm" variant="ghost" aria-label="Delete face data" onClick={() => confirm('Delete this face data?') && router.delete(`/admin/identity/${p.id}`, { preserveScroll: true })}><Trash2 className="size-4" /></Button>
                            </div>
                        </article>
                    ))}
                </div>
            </div>
        </>
    );
}

Review.layout = { breadcrumbs: [{ title: 'Identity', href: '/admin/identity' }] };
