/**
 * Resumable upload: sends a large file in pieces and, after a dropped connection, asks the
 * server how much arrived and carries on from there.
 */
const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');

export async function resumableUpload(file: File, purpose: string, onProgress: (pct: number) => void): Promise<string> {
    const headers = { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' };
    const start = await fetch('/uploads', { method: 'POST', credentials: 'same-origin', headers: { ...headers, 'Content-Type': 'application/json' }, body: JSON.stringify({ purpose, filename: file.name, size: file.size, mime: file.type || null }) });
    const init = await start.json().catch(() => ({}));

    if (!start.ok) {
        throw new Error(init.message ?? 'The upload could not start.');
    }

    let offset = 0;
    let failures = 0;

    while (offset < file.size) {
        const piece = file.slice(offset, offset + init.chunk);

        try {
            const res = await fetch(`/uploads/${init.id}?offset=${offset}`, { method: 'PUT', credentials: 'same-origin', headers: { ...headers, 'Content-Type': 'application/octet-stream' }, body: piece });
            const body = await res.json().catch(() => ({}));

            if (res.ok || res.status === 409) {
                offset = body.received; // 409: the server tells us where to continue
                failures = 0;
                onProgress(Math.round((offset / file.size) * 100));
            } else {
                throw new Error(body.message ?? `Upload failed (${res.status}).`);
            }
        } catch (e) {
            // network drop: wait and ask the server where it got to
            if (++failures > 8) {
                throw e instanceof Error ? e : new Error('Upload failed.');
            }

            await new Promise((r) => setTimeout(r, Math.min(30000, 1000 * 2 ** failures)));
            const st = await fetch(`/uploads/${init.id}`, { credentials: 'same-origin', headers }).then((r) => r.json()).catch(() => null);

            if (st && typeof st.received === 'number') {
                offset = st.received;
            }
        }
    }

    return init.id as string;
}
