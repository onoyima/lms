import { Download, ExternalLink, Loader2, Maximize2, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    driveId,
    fileSize,
    KIND_ICON,
    KIND_LABEL,
    KIND_TONE,
    materialKind,
    vimeoId,
    youTubeId,
} from '@/components/lms/material';
import type { MaterialKind } from '@/components/lms/material';
import { PdfViewer } from '@/components/lms/pdf-viewer';
import { RichContent } from '@/components/rich-content';

type Item = {
    id: number;
    type: string;
    title: string;
    file_name: string | null;
    file_mime: string | null;
    file_size: number | null;
    url: string | null;
};

/**
 * Shows a course material inside the page, whatever it is: PDF pages, a Word document, slides,
 * a spreadsheet, a picture (tap to enlarge), video and audio players, YouTube, Vimeo and Google
 * Drive. Anything the browser cannot show gets a clear download card instead.
 */
export function MaterialViewer({
    item,
    preview,
    onFinished,
}: {
    item: Item;
    preview: string | null;
    onFinished?: () => void;
}) {
    const kind = materialKind(item);
    const fileUrl = `/learn/items/${item.id}/file`;

    if (item.file_name) {
        if (kind === 'pdf') {
            return (
                <PdfViewer
                    url={fileUrl}
                    title={item.file_name}
                    onLastPage={onFinished}
                />
            );
        }

        if (kind === 'image' && item.file_mime !== 'image/svg+xml') {
            return <Picture src={fileUrl} alt={item.title} />;
        }

        if (kind === 'video') {
            return (
                <video
                    src={fileUrl}
                    controls
                    playsInline
                    preload="metadata"
                    className="aspect-video w-full rounded-xl bg-black"
                    controlsList="nodownload"
                    onTimeUpdate={(e) => watched(e.currentTarget, onFinished)}
                >
                    Your browser cannot play this video. Use Download to watch
                    it.
                </video>
            );
        }

        if (kind === 'audio') {
            return (
                <div className="rounded-xl border border-border bg-card p-4">
                    <audio
                        src={fileUrl}
                        controls
                        preload="metadata"
                        className="w-full"
                        onTimeUpdate={(e) =>
                            watched(e.currentTarget, onFinished)
                        }
                    />
                </div>
            );
        }

        if (preview) {
            return <DocumentPreview id={item.id} kind={kind} />;
        }

        return (
            <FileCard
                item={item}
                kind={kind}
                note="This file opens in its own app. Download it to read it."
            />
        );
    }

    if (item.url) {
        const yt = youTubeId(item.url);
        const vm = vimeoId(item.url);
        const dr = driveId(item.url);
        const src = yt
            ? `https://www.youtube-nocookie.com/embed/${yt}?rel=0`
            : vm
              ? `https://player.vimeo.com/video/${vm}`
              : dr
                ? `https://drive.google.com/file/d/${dr}/preview`
                : null;

        if (src) {
            return (
                <div className="grid gap-2">
                    <iframe
                        src={src}
                        title={item.title}
                        className="aspect-video w-full rounded-xl border-0 bg-black"
                        allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen"
                        allowFullScreen
                        loading="lazy"
                        referrerPolicy="strict-origin-when-cross-origin"
                    />
                    <a
                        href={item.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="flex w-fit items-center gap-1 text-xs text-muted-foreground hover:text-brand"
                    >
                        Not playing? Open it on{' '}
                        {yt ? 'YouTube' : vm ? 'Vimeo' : 'Google Drive'}{' '}
                        <ExternalLink className="size-3" />
                    </a>
                </div>
            );
        }
    }

    return null;
}

/** Nine tenths of a video or recording played counts as watched (once). */
function watched(m: HTMLMediaElement, onFinished?: () => void) {
    if (
        onFinished &&
        m.duration > 0 &&
        m.currentTime / m.duration >= 0.9 &&
        !m.dataset.finished
    ) {
        m.dataset.finished = '1';
        onFinished();
    }
}

/** A Word document, slides, a sheet or a text file, converted on the server and shown as a page. */
function DocumentPreview({ id, kind }: { id: number; kind: MaterialKind }) {
    const [state, setState] = useState<{
        html: string;
        error?: string;
        slides?: number;
    } | null>(null);

    useEffect(() => {
        let gone = false;
        fetch(`/learn/items/${id}/preview`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
            .then((d) => !gone && setState(d))
            .catch(
                () =>
                    !gone &&
                    setState({
                        html: '',
                        error: 'This file could not be shown here. Use Download to open it.',
                    }),
            );

        return () => {
            gone = true;
        };
    }, [id]);

    if (!state) {
        return (
            <div
                className="flex items-center gap-3 rounded-xl border border-border bg-card p-6 text-sm text-muted-foreground"
                role="status"
            >
                <Loader2 className="size-4 animate-spin" /> Opening the{' '}
                {KIND_LABEL[kind].toLowerCase()}…
            </div>
        );
    }

    if (state.error || !state.html) {
        return (
            <p className="rounded-xl border border-border bg-muted/40 p-5 text-sm text-muted-foreground">
                {state.error ?? 'This file is empty.'}
            </p>
        );
    }

    return (
        <ZoomablePictures>
            <article
                className={`surface p-5 sm:p-8 ${kind === 'slides' ? '!border-0 !bg-transparent !p-0 !shadow-none' : ''}`}
            >
                {kind === 'slides' && state.slides ? (
                    <p className="mb-3 text-sm text-muted-foreground">
                        {state.slides} slides
                    </p>
                ) : null}
                <RichContent
                    html={state.html}
                    className={`rich-content lesson ${kind === 'sheet' ? 'sheet' : ''}`}
                />
            </article>
        </ZoomablePictures>
    );
}

/** Tap a picture inside a material to see it full screen (diagrams are often too small on a phone). */
export function ZoomablePictures({ children }: { children: React.ReactNode }) {
    const [src, setSrc] = useState<string | null>(null);

    return (
        <div
            onClick={(e) => {
                const t = e.target as HTMLElement;

                if (t.tagName === 'IMG' && (t as HTMLImageElement).src) {
                    setSrc((t as HTMLImageElement).src);
                }
            }}
        >
            {children}
            {src && <Lightbox src={src} onClose={() => setSrc(null)} />}
        </div>
    );
}

function Picture({ src, alt }: { src: string; alt: string }) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="group relative block w-full overflow-hidden rounded-xl border border-border bg-card"
                aria-label={`Enlarge ${alt}`}
            >
                <img
                    src={src}
                    alt={alt}
                    className="mx-auto max-h-[75vh] w-auto max-w-full object-contain"
                />
                <span className="absolute right-2 bottom-2 flex items-center gap-1 rounded-lg bg-black/60 px-2 py-1 text-xs text-white opacity-90">
                    <Maximize2 className="size-3.5" /> Enlarge
                </span>
            </button>
            {open && <Lightbox src={src} onClose={() => setOpen(false)} />}
        </>
    );
}

function Lightbox({ src, onClose }: { src: string; onClose: () => void }) {
    useEffect(() => {
        const k = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        window.addEventListener('keydown', k);
        const o = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        return () => {
            window.removeEventListener('keydown', k);
            document.body.style.overflow = o;
        };
    }, [onClose]);

    return (
        <div
            role="dialog"
            aria-modal="true"
            aria-label="Picture"
            className="fixed inset-0 z-[80] overflow-auto bg-black/90"
            onClick={onClose}
        >
            <button
                type="button"
                onClick={onClose}
                className="fixed top-3 right-3 z-10 grid size-10 place-items-center rounded-full bg-white/15 text-white hover:bg-white/25"
                aria-label="Close"
            >
                <X className="size-5" />
            </button>
            {/* pinch to zoom works on phones; on a computer the picture shows at full size and scrolls */}
            <div className="grid min-h-full place-items-center p-4">
                <img
                    src={src}
                    alt=""
                    className="max-w-none touch-pinch-zoom sm:max-w-full"
                    onClick={(e) => e.stopPropagation()}
                />
            </div>
        </div>
    );
}

export function FileCard({
    item,
    kind,
    note,
}: {
    item: Item;
    kind: MaterialKind;
    note?: string;
}) {
    const Icon = KIND_ICON[kind];

    return (
        <div className="flex flex-wrap items-center gap-4 rounded-xl border border-border bg-card p-5">
            <span
                className={`grid size-12 shrink-0 place-items-center rounded-xl ${KIND_TONE[kind]}`}
            >
                <Icon className="size-6" />
            </span>
            <span className="min-w-0 flex-1">
                <span className="block truncate font-medium">
                    {item.file_name}
                </span>
                <span className="block text-xs text-muted-foreground">
                    {KIND_LABEL[kind]}
                    {item.file_size ? ` · ${fileSize(item.file_size)}` : ''}
                    {note ? ` · ${note}` : ''}
                </span>
            </span>
            <a
                href={`/learn/items/${item.id}/file?download=1`}
                className="flex items-center gap-1.5 rounded-xl bg-brand px-4 py-2 text-sm font-medium text-brand-foreground"
            >
                <Download className="size-4" /> Download
            </a>
        </div>
    );
}
