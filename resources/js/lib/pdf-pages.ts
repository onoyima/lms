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
