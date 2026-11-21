/**
 * Turns a PDF into one picture per page, in the browser (pdf.js, bundled with the app, so it works
 * without internet). The pictures are what the AI reads, and diagrams are cut out of them.
 */
export async function pdfToPages(file: File, onProgress?: (done: number, total: number) => void, maxPages = 60): Promise<File[]> {
    const pdfjs = await import('pdfjs-dist/legacy/build/pdf.mjs');
    const worker = (await import('pdfjs-dist/legacy/build/pdf.worker.min.mjs?url')).default;
    pdfjs.GlobalWorkerOptions.workerSrc = worker;
    const doc = await pdfjs.getDocument({ data: new Uint8Array(await file.arrayBuffer()) }).promise;
    const total = Math.min(doc.numPages, maxPages);
    const pages: File[] = [];

    for (let n = 1; n <= total; n++) {
        const page = await doc.getPage(n);
        // about 1600 px across: sharp enough for small print, subscripts and symbols
        const base = page.getViewport({ scale: 1 });
        const viewport = page.getViewport({ scale: Math.min(3, 1600 / base.width) });
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(viewport.width);
        canvas.height = Math.round(viewport.height);
        const ctx = canvas.getContext('2d')!;
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        await page.render({ canvasContext: ctx, viewport }).promise;
        const blob = await new Promise<Blob>((resolve, reject) => canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('page picture failed'))), 'image/jpeg', 0.9));
        pages.push(new File([blob], `page-${String(n).padStart(3, '0')}.jpg`, { type: 'image/jpeg' }));
        page.cleanup();
        onProgress?.(n, total);
    }

    await doc.destroy();

    return pages;
}

/**
 * The text of a PDF made from Word or a typed document, line by line, with a blank line where the
 * page has a gap (as between two questions). Read in the browser, so the server needs no PDF tools.
 * A scanned PDF has no text: the result is empty and the setter is pointed to AI reading.
 */
export async function pdfToText(file: File): Promise<string> {
    const pdfjs = await import('pdfjs-dist/legacy/build/pdf.mjs');
    const worker = (await import('pdfjs-dist/legacy/build/pdf.worker.min.mjs?url')).default;
    pdfjs.GlobalWorkerOptions.workerSrc = worker;
    const doc = await pdfjs.getDocument({ data: new Uint8Array(await file.arrayBuffer()) }).promise;
    const out: string[] = [];

    for (let n = 1; n <= doc.numPages; n++) {
        const page = await doc.getPage(n);
        const content = await page.getTextContent();
        type Piece = { x: number; y: number; h: number; s: string };
        const pieces: Piece[] = [];

        for (const it of content.items as { str?: string; transform?: number[]; height?: number }[]) {
            if (!it.str || !it.transform) {
continue;
}

            pieces.push({ x: it.transform[4], y: it.transform[5], h: it.height || Math.abs(it.transform[3]) || 10, s: it.str });
        }

        // rows: pieces whose baselines are within a third of a line of each other
        pieces.sort((a, b) => b.y - a.y || a.x - b.x);
        const rows: { y: number; h: number; parts: Piece[] }[] = [];

        for (const p of pieces) {
            const row = rows.find((r) => Math.abs(r.y - p.y) < Math.max(2, p.h / 3));

            if (row) {
row.parts.push(p);
} else {
rows.push({ y: p.y, h: p.h, parts: [p] });
}
        }

        rows.sort((a, b) => b.y - a.y);
        const heights = rows.map((r) => r.h).sort((a, b) => a - b);
        const lineHeight = heights[Math.floor(heights.length / 2)] || 12;
        let last: number | null = null;

        for (const r of rows) {
            const text = r.parts
                .sort((a, b) => a.x - b.x)
                .map((p) => p.s)
                .join(' ')
                .replace(/\s+/g, ' ')
                .trim();

            if (!text) {
continue;
}

            if (last !== null && last - r.y > lineHeight * 1.9) {
out.push('');
}

            out.push(text);
            last = r.y;
        }

        out.push('');
        page.cleanup();
    }

    await doc.destroy();

    return out.join('\n').replace(/\n{3,}/g, '\n\n').trim();
}
