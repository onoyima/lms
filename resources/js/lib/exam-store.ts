/**
 * Everything the exam room needs to carry on without the server, kept on this computer:
 * the paper, the attempt's clock, the token, and every answer with the moment it was written.
 *
 * Two copies, so a power cut, a crash or a full disk never loses an answer:
 *  - IndexedDB, written with "strict" durability (the browser waits for the disk before it
 *    reports success), which survives the computer switching off;
 *  - localStorage, written synchronously on every change, which survives a tab crash.
 * On load the two are merged, newest answer (highest sequence) wins.
 *
 * The store also notes when the exam page was last running ("alive") and whether it was left
 * properly (page closed or reloaded) or not (power cut, crash, flat battery). Only the second
 * gives time back.
 */

export type StoredAnswer = { response: unknown; flagged: boolean; seq: number; at: number };
export type StoredSession = {
    candidateId: number;
    attemptId: number;
    token: string;
    paper: unknown;
    state: Record<string, unknown>;
    settings: Record<string, unknown>;
    clockOffset: number;
    savedAt: number;
};
export type Snapshot = {
    session: StoredSession | null;
    answers: Record<number, StoredAnswer>;
    queue: Record<number, StoredAnswer>;
    aliveAt: number | null;
    exit: 'clean' | 'running' | null;
};

const DB = 'exam-room';
const VERSION = 1;

function openDb(): Promise<IDBDatabase | null> {
    return new Promise((resolve) => {
        try {
            const req = indexedDB.open(DB, VERSION);
            req.onupgradeneeded = () => {
                const db = req.result;

                for (const s of ['session', 'answers', 'queue']) {
                    if (!db.objectStoreNames.contains(s)) {
                        db.createObjectStore(s);
                    }
                }
            };
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => resolve(null);
            req.onblocked = () => resolve(null);
        } catch {
            resolve(null);
        }
    });
}

function ls<T>(key: string, fallback: T): T {
    try {
        const v = localStorage.getItem(key);

        return v === null ? fallback : (JSON.parse(v) as T);
    } catch {
        return fallback;
    }
}

function lsSet(key: string, value: unknown): void {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // storage full: IndexedDB still has it, but the exams office should know
        window.dispatchEvent(new Event('exam-storage-failed'));
    }
}

function newest(a: Record<number, StoredAnswer>, b: Record<number, StoredAnswer>): Record<number, StoredAnswer> {
    const out = { ...a };

    for (const [k, v] of Object.entries(b)) {
        const id = Number(k);

        if (!out[id] || v.seq > out[id].seq) {
            out[id] = v;
        }
    }

    return out;
}

export class ExamStore {
    private db: Promise<IDBDatabase | null>;
    private readonly k: { answers: string; queue: string; alive: string; exit: string; session: string };

    constructor(private readonly candidateId: number) {
        this.db = typeof indexedDB === 'undefined' ? Promise.resolve(null) : openDb();
        this.k = {
            answers: `exam:all:${candidateId}`,
            queue: `exam:answers:${candidateId}`, // the name used before, so answers queued by an older page are kept
            alive: `exam:alive:${candidateId}`,
            exit: `exam:exit:${candidateId}`,
            session: `exam:session:${candidateId}`,
        };
    }

    private async idb<T>(store: string, mode: IDBTransactionMode, run: (s: IDBObjectStore) => IDBRequest<T> | void): Promise<T | undefined> {
        const db = await this.db;

        if (!db) {
            return undefined;
        }

        return new Promise((resolve) => {
            try {
                const tx = db.transaction(store, mode, { durability: 'strict' } as IDBTransactionOptions);
                const req = run(tx.objectStore(store));
                tx.oncomplete = () => resolve(req ? (req.result as T) : undefined);
                tx.onerror = () => resolve(undefined);
                tx.onabort = () => resolve(undefined);
            } catch {
                resolve(undefined);
            }
        });
    }

    async load(): Promise<Snapshot> {
        const id = this.candidateId;
        const [light, paper, answers, queue] = await Promise.all([
            this.idb<Omit<StoredSession, 'paper'>>('session', 'readonly', (s) => s.get(id)),
            this.idb<unknown>('session', 'readonly', (s) => s.get(`${id}:paper`)),
            this.idb<Record<number, StoredAnswer>>('answers', 'readonly', (s) => s.get(id)),
            this.idb<Record<number, StoredAnswer>>('queue', 'readonly', (s) => s.get(id)),
        ]);
        const lsSession = ls<Omit<StoredSession, 'paper'> | null>(this.k.session, null);
        // the newer of the two copies of the clock; the paper itself is only in IndexedDB
        const base = [light, lsSession].filter(Boolean).sort((a, b) => (b!.savedAt ?? 0) - (a!.savedAt ?? 0))[0] ?? null;
        const session = base && paper ? ({ ...base, paper } as StoredSession) : null;

        return {
            session,
            answers: newest(answers ?? {}, ls(this.k.answers, {})),
            queue: newest(queue ?? {}, ls(this.k.queue, {})),
            aliveAt: ls<number | null>(this.k.alive, null) ?? (lsSession?.savedAt ?? null),
            exit: ls<'clean' | 'running' | null>(this.k.exit, null),
        };
    }

    /** The paper, clock and token, so the exam can reopen on this computer without the server. */
    saveSession(s: StoredSession): void {
        const { paper, ...light } = s;
        lsSet(this.k.session, light);
        void this.idb('session', 'readwrite', (st) => {
            st.put(light, this.candidateId);

            if (paper) {
                st.put(paper, `${this.candidateId}:paper`);
            }
        });
    }

    /** The latest clock and status only (the paper is already kept). */
    saveState(state: Record<string, unknown>, clockOffset: number): void {
        const current = ls<Omit<StoredSession, 'paper'> | null>(this.k.session, null);

        if (!current) {
            return;
        }

        const light = { ...current, state, clockOffset, savedAt: Date.now() + clockOffset };
        lsSet(this.k.session, light);
        void this.idb('session', 'readwrite', (st) => st.put(light, this.candidateId));
    }

    /** Called on every answer: both copies of all answers and of the queue still to be sent. */
    saveAnswers(all: Record<number, StoredAnswer>, queue: Record<number, StoredAnswer>): void {
        lsSet(this.k.answers, all);
        lsSet(this.k.queue, queue);
        void this.idb('answers', 'readwrite', (st) => st.put(all, this.candidateId));
        void this.idb('queue', 'readwrite', (st) => st.put(queue, this.candidateId));
    }

    saveQueue(queue: Record<number, StoredAnswer>): void {
        lsSet(this.k.queue, queue);
        void this.idb('queue', 'readwrite', (st) => st.put(queue, this.candidateId));
    }

    /** "The exam page is running now" (server clock), every few seconds. */
    alive(at: number): void {
        lsSet(this.k.alive, at);
        lsSet(this.k.exit, 'running');
    }

    /** The page was closed or reloaded on purpose: no time is given back for the gap. */
    leftCleanly(): void {
        lsSet(this.k.exit, 'clean');
    }

    /** After the exam is submitted nothing of it stays on this computer. */
    async clear(): Promise<void> {
        for (const k of Object.values(this.k)) {
            try {
                localStorage.removeItem(k);
            } catch {
                // ignore
            }
        }

        await Promise.all(['session', 'answers', 'queue'].map((s) => this.idb(s, 'readwrite', (st) => st.delete(this.candidateId))));
        await this.idb('session', 'readwrite', (st) => st.delete(`${this.candidateId}:paper`));

        try {
            navigator.serviceWorker?.controller?.postMessage({ type: 'forget', url: location.pathname });
        } catch {
            // ignore
        }
    }
}

/**
 * Keeps the exam page itself on this computer (a small service worker for the exam room only),
 * so a reload or a restart while the server cannot be reached still opens the exam.
 * Needs a secure address (https, or localhost); without one, answers are still kept, but the
 * page cannot reopen until the server is back.
 */
export function keepExamPageOffline(): void {
    if (!('serviceWorker' in navigator) || !window.isSecureContext) {
        return;
    }

    navigator.serviceWorker.register('/exam-sw.js', { scope: '/my/exams/' }).then(async (reg) => {
        const sw = reg.active ?? reg.installing ?? reg.waiting;
        const send = () => {
            const files = performance.getEntriesByType('resource').map((e) => e.name).filter((u) => {
                const p = new URL(u, location.href);

                return p.origin === location.origin && (p.pathname.startsWith('/build/') || p.pathname.startsWith('/vendor/face-api/') || p.pathname.startsWith('/favicon'));
            });
            (reg.active ?? navigator.serviceWorker.controller)?.postMessage({ type: 'keep', page: location.pathname, files });
        };

        if (reg.active) {
            send();
        } else {
            sw?.addEventListener('statechange', () => reg.active && send());
        }
    }).catch(() => undefined);
}
