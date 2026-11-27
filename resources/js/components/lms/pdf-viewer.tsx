import { Loader2, Minus, Plus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type PdfDoc = {
    numPages: number;
    getPage: (n: number) => Promise<PdfPage>;
    destroy: () => Promise<void>;
};
type PdfPage = {
    getViewport: (o: { scale: number }) => { width: number; height: number };
    render: (o: {
        canvasContext: CanvasRenderingContext2D;
        viewport: unknown;
    }) => { promise: Promise<void>; cancel: () => void };
    cleanup: () => void;
};

/**
 * A PDF drawn in the page with pdf.js (bundled with the app, so it works offline and on phones
 * whose browser cannot show PDFs itself). Pages are drawn only when they come into view, at the
 * width of the column, sharp on high-density screens; zoom makes them larger and scrolls sideways.
 */
export function PdfViewer({
    url,
    title,
    onLastPage,
}: {
    url: string;
    title: string;
    onLastPage?: () => void;
}) {
    const box = useRef<HTMLDivElement>(null);
    const [doc, setDoc] = useState<PdfDoc | null>(null);
    const [sizes, setSizes] = useState<{ w: number; h: number }[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [zoom, setZoom] = useState(1);
    const [width, setWidth] = useState(0);
    const [page, setPage] = useState(0); // 0 until a page has really been seen
    const told = useRef(false);

    useEffect(() => {
        if (doc && page === doc.numPages && onLastPage && !told.current) {
            told.current = true;
            onLastPage();
        }
    }, [doc, page, onLastPage]);

    useEffect(() => {
        let cancelled = false;
        let loaded: PdfDoc | null = null;
        (async () => {
            try {
                const pdfjs = await import('pdfjs-dist/legacy/build/pdf.mjs');
                const worker = (
                    await import('pdfjs-dist/legacy/build/pdf.worker.min.mjs?url')
                ).default;
                pdfjs.GlobalWorkerOptions.workerSrc = worker;
                loaded = (await pdfjs.getDocument({
                    url,
                    withCredentials: true,
                }).promise) as unknown as PdfDoc;

                if (cancelled) {
                    return;
                }

                // every page takes the first page's shape until it is drawn (then its own), so the
                // scroll height is right at once even for a long book
                const v = (await loaded.getPage(1)).getViewport({ scale: 1 });
                setSizes(
                    Array.from({ length: loaded.numPages }, () => ({
                        w: v.width,
                        h: v.height,
                    })),
                );
                setDoc(loaded);
            } catch {
                if (!cancelled) {
                    setError(
                        'This PDF could not be shown here. Use Download to open it.',
                    );
                }
            }
        })();

        return () => {
            cancelled = true;
            loaded?.destroy().catch(() => undefined);
        };
    }, [url]);

    useEffect(() => {
        const el = box.current;

        if (!el) {
            return;
        }

        const ro = new ResizeObserver(() => setWidth(el.clientWidth));
        ro.observe(el);
        setWidth(el.clientWidth);

        return () => ro.disconnect();
    }, []);

    if (error) {
        return (
            <p className="rounded-xl border border-border bg-muted/40 p-5 text-sm text-muted-foreground">
                {error}
            </p>
        );
    }

    const pageWidth = Math.max(240, (width - 2) * zoom);

    return (
        <div className="grid gap-2">
            <div className="sticky top-[76px] z-10 flex items-center justify-between gap-2 rounded-xl border border-border bg-card/95 px-3 py-1.5 text-sm shadow-sm backdrop-blur">
                <span className="text-muted-foreground tabular-nums">
                    {doc
                        ? `Page ${Math.max(1, page)} of ${doc.numPages}`
                        : 'Opening…'}
                </span>
                <span className="flex items-center gap-1">
                    <button
                        type="button"
                        className="grid size-8 place-items-center rounded-lg hover:bg-muted disabled:opacity-40"
                        onClick={() =>
                            setZoom((z) => Math.max(0.6, +(z - 0.2).toFixed(1)))
                        }
                        disabled={zoom <= 0.6}
                        aria-label="Zoom out"
                    >
                        <Minus className="size-4" />
                    </button>
                    <button
                        type="button"
                        className="min-w-12 rounded-lg px-1 text-xs tabular-nums hover:bg-muted"
                        onClick={() => setZoom(1)}
                        aria-label="Fit to width"
                    >
                        {Math.round(zoom * 100)}%
                    </button>
                    <button
                        type="button"
                        className="grid size-8 place-items-center rounded-lg hover:bg-muted disabled:opacity-40"
                        onClick={() =>
                            setZoom((z) => Math.min(3, +(z + 0.2).toFixed(1)))
                        }
                        disabled={zoom >= 3}
                        aria-label="Zoom in"
                    >
                        <Plus className="size-4" />
                    </button>
                </span>
            </div>
            <div
                ref={box}
                className="max-h-[80vh] overflow-auto rounded-xl border border-border bg-[#525659] p-2 sm:p-3"
                aria-label={title}
            >
                {!doc && (
                    <div className="grid h-60 place-items-center text-white/80">
                        <Loader2 className="size-6 animate-spin" />
                    </div>
                )}
                {doc && (
                    <div
                        className="mx-auto grid gap-3"
                        style={{ width: pageWidth }}
                    >
                        {sizes.map((s, i) => (
                            <PdfPageView
                                key={i}
                                doc={doc}
                                n={i + 1}
                                width={pageWidth}
                                ratio={s.h / s.w}
                                onSeen={setPage}
                            />
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}

function PdfPageView({
    doc,
    n,
    width,
    ratio,
    onSeen,
}: {
    doc: PdfDoc;
    n: number;
    width: number;
    ratio: number;
    onSeen: (n: number) => void;
}) {
    const ref = useRef<HTMLCanvasElement>(null);
    const [visible, setVisible] = useState(false);
    const [shape, setShape] = useState(ratio);

    useEffect(() => {
        const el = ref.current;

        if (!el) {
            return;
        }

        const io = new IntersectionObserver(
            (entries) => {
                for (const e of entries) {
                    if (e.isIntersecting) {
                        setVisible(true);

                        if (e.intersectionRatio > 0.4) {
                            onSeen(n);
                        }
                    }
                }
            },
            { rootMargin: '600px 0px', threshold: [0, 0.4] },
        );
        io.observe(el);

        return () => io.disconnect();
    }, [n, onSeen]);

    useEffect(() => {
        if (!visible || !ref.current) {
            return;
        }

        let task: { cancel: () => void } | null = null;
        let cancelled = false;
        const canvas = ref.current;
        (async () => {
            const page = await doc.getPage(n);

            if (cancelled) {
                return;
            }

            const base = page.getViewport({ scale: 1 });
            setShape(base.height / base.width);
            const dpr = Math.min(window.devicePixelRatio || 1, 2.5);
            const viewport = page.getViewport({
                scale: (width / base.width) * dpr,
            });
            canvas.width = Math.round(viewport.width);
            canvas.height = Math.round(viewport.height);
            const ctx = canvas.getContext('2d');

            if (!ctx) {
                return;
            }

            const r = page.render({ canvasContext: ctx, viewport });
            task = r;
            await r.promise.catch(() => undefined);
            page.cleanup();
        })();

        return () => {
            cancelled = true;
            task?.cancel();
        };
    }, [visible, doc, n, width]);

    return (
        <canvas
            ref={ref}
            className="block w-full rounded-[3px] bg-white shadow"
            style={{ aspectRatio: `1 / ${shape}` }}
            aria-label={`Page ${n}`}
        />
    );
}
