/**
 * Camera and screen recording in the candidate's browser, for hosting without a media server.
 * Every few seconds the recorder hands over a piece; pieces are first saved in the browser
 * (IndexedDB) and then uploaded, so a dropped connection, a reload or even a crash only delays
 * them. The server stores a piece sent twice only once.
 */

export type RecordPlan = { camera: boolean; screen: boolean; audio: boolean; camera_kbps: number; screen_kbps: number; chunk_seconds: number };

type Piece = { id: string; attempt: number; key: string; kind: 'camera' | 'screen'; seq: number; offset: number; final: boolean; type: string; blob: Blob };

const DB = 'exam-recordings';
const STORE = 'pieces';
const memory = new Map<string, Piece>(); // used when the browser has no IndexedDB (private windows)

function openDb(): Promise<IDBDatabase | null> {
    return new Promise((resolve) => {
        try {
            const req = indexedDB.open(DB, 1);
            req.onupgradeneeded = () => req.result.createObjectStore(STORE, { keyPath: 'id' });
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => resolve(null);
        } catch {
            resolve(null);
        }
    });
}

let dbPromise: Promise<IDBDatabase | null> | null = null;
const db = () => (dbPromise ??= openDb());

async function put(p: Piece): Promise<void> {
    const d = await db();

    if (!d) {
        memory.set(p.id, p);

        return;
    }

    await new Promise<void>((resolve) => {
        try {
            const tx = d.transaction(STORE, 'readwrite');
            tx.objectStore(STORE).put(p);
            tx.oncomplete = () => resolve();
            tx.onerror = () => (memory.set(p.id, p), resolve());
        } catch {
            memory.set(p.id, p);
            resolve();
        }
    });
}

async function remove(id: string): Promise<void> {
    memory.delete(id);
    const d = await db();

    if (!d) {
        return;
    }

    await new Promise<void>((resolve) => {
        try {
            const tx = d.transaction(STORE, 'readwrite');
            tx.objectStore(STORE).delete(id);
            tx.oncomplete = () => resolve();
            tx.onerror = () => resolve();
        } catch {
            resolve();
        }
    });
}

async function pending(attempt: number): Promise<Piece[]> {
    const fromMemory = [...memory.values()].filter((p) => p.attempt === attempt);
    const d = await db();

    if (!d) {
        return fromMemory;
    }

    const stored = await new Promise<Piece[]>((resolve) => {
        try {
            const req = d.transaction(STORE, 'readonly').objectStore(STORE).getAll();
            req.onsuccess = () => resolve((req.result as Piece[]).filter((p) => p.attempt === attempt));
            req.onerror = () => resolve([]);
        } catch {
            resolve([]);
        }
    });

    return [...stored, ...fromMemory].sort((a, b) => (a.key === b.key ? a.seq - b.seq : a.id < b.id ? -1 : 1));
}

function randomKey(): string {
    const a = new Uint8Array(16);
    crypto.getRandomValues(a);

    return Array.from(a, (b) => b.toString(16).padStart(2, '0')).join('');
}

function pickType(): string {
    const options = ['video/webm;codecs=vp8,opus', 'video/webm;codecs=vp8', 'video/webm', 'video/mp4'];

    return options.find((t) => typeof MediaRecorder !== 'undefined' && MediaRecorder.isTypeSupported?.(t)) ?? '';
}

export type RecorderStatus = { recording: ('camera' | 'screen')[]; waiting: number; failed: boolean };

/**
 * Uploads pieces (oldest first) and keeps trying while any are waiting. `token` is read each time,
 * so a new exam window (with a new token) also sends pieces left behind by an older one.
 */
export class RecordingUploader {
    private busy = false;
    private stopped = false;
    private timer: number | null = null;

    constructor(private attempt: number, private token: () => string, private onCount: (waiting: number) => void) {}

    start() {
        this.stopped = false;
        void this.drain();
    }

    stop() {
        this.stopped = true;

        if (this.timer) {
            window.clearTimeout(this.timer);
        }
    }

    async add(p: Omit<Piece, 'id' | 'attempt'>) {
        await put({ ...p, attempt: this.attempt, id: `${this.attempt}:${p.key}:${String(p.seq).padStart(7, '0')}` });
        void this.drain();
    }

    /** Try to send everything now (e.g. right before leaving); resolves when the queue is empty or sending fails. */
    async flush(): Promise<number> {
        await this.drain();

        return (await pending(this.attempt)).length;
    }

    private later(ms: number) {
        if (this.stopped) {
            return;
        }

        if (this.timer) {
            window.clearTimeout(this.timer);
        }

        this.timer = window.setTimeout(() => void this.drain(), ms);
    }

    async drain(): Promise<void> {
        if (this.busy) {
            return;
        }

        this.busy = true;
        let failures = 0;

        try {
            for (;;) {
                const list = await pending(this.attempt);
                this.onCount(list.length);

                if (list.length === 0) {
                    return;
                }

                const p = list[0];
                const fd = new FormData();
                fd.append('key', p.key);
                fd.append('kind', p.kind);
                fd.append('seq', String(p.seq));
                fd.append('offset_ms', String(p.offset));
                fd.append('final', p.final ? '1' : '0');
                fd.append('file', p.blob, p.type.startsWith('video/mp4') ? 'piece.mp4' : 'piece.webm');
                let status = 0;
                let reason = '';

                try {
                    const res = await fetch('/api/exam/attempt/recording', {
                        method: 'POST',
                        headers: { Accept: 'application/json', Authorization: `Bearer ${this.token()}` },
                        body: fd,
                    });
                    status = res.status;
                    reason = status === 409 ? String(((await res.json().catch(() => ({}))) as { reason?: string }).reason ?? '') : '';
                } catch {
                    status = 0;
                }

                if (status === 409 && reason === 'session_moved') {
                    // the exam moved to another window: that window sends what is left here
                    this.stopped = true;

                    return;
                }

                if ((status >= 200 && status < 300) || status === 403 || status === 413 || status === 422) {
                    // stored, or never storable (not part of this exam / too big): either way it leaves the queue
                    await remove(p.id);
                    failures = 0;
                    continue;
                }

                if (status === 409) {
                    // the exam is closed and the late-arrival window is over
                    await remove(p.id);
                    continue;
                }

                failures++;
                this.later(Math.min(30000, 2000 * 2 ** Math.min(failures, 4)));

                return;
            }
        } finally {
            this.busy = false;
        }
    }
}

/** Records one stream in pieces and hands each piece to the uploader. */
export class PieceRecorder {
    private rec: MediaRecorder | null = null;
    private seq = 0;
    private started = 0;
    private key = randomKey();
    private stopping = false;

    constructor(private kind: 'camera' | 'screen', private stream: MediaStream, private kbps: number, private seconds: number, private uploader: RecordingUploader, private onFail: (why: string) => void) {}

    static supported(): boolean {
        return typeof MediaRecorder !== 'undefined' && pickType() !== '';
    }

    start() {
        const type = pickType();

        try {
            this.rec = new MediaRecorder(this.stream, { mimeType: type, videoBitsPerSecond: this.kbps * 1000, audioBitsPerSecond: 32000 });
        } catch {
            this.onFail('recorder_unavailable');

            return;
        }

        this.started = Date.now();
        this.rec.ondataavailable = (e) => {
            if (e.data && e.data.size > 0) {
                const final = this.stopping && this.rec?.state === 'inactive';
                void this.uploader.add({ key: this.key, kind: this.kind, seq: this.seq++, offset: Date.now() - this.started, final, type: this.rec?.mimeType || type, blob: e.data });
            }
        };
        this.rec.onerror = () => this.onFail('recorder_error');
        this.rec.start(this.seconds * 1000);
    }

    /** Stop and send the last piece (marked final so the server closes the recording at once). */
    stop(): Promise<void> {
        return new Promise((resolve) => {
            if (!this.rec || this.rec.state === 'inactive') {
                resolve();

                return;
            }

            this.stopping = true;
            this.rec.addEventListener('stop', () => window.setTimeout(resolve, 50), { once: true });
            this.rec.stop();
        });
    }

    get active(): boolean {
        return !!this.rec && this.rec.state === 'recording';
    }
}
