/**
 * Small interface sounds for the Midnight theme, made in the browser with Web Audio
 * (no files to download, nothing until the person first clicks). Quiet by design and
 * switched off with one tap; the choice is kept in this browser.
 */
export type Cue = 'tap' | 'open' | 'toggle' | 'success' | 'nope';

const KEY = 'ui-sound';
let ctx: AudioContext | null = null;
let last = 0;

export function soundOn(): boolean {
    try {
        return localStorage.getItem(KEY) !== 'off';
    } catch {
        return true;
    }
}

export function setSound(on: boolean): void {
    try {
        localStorage.setItem(KEY, on ? 'on' : 'off');
    } catch {
        // storage unavailable: the choice lasts for this page only
    }

    window.dispatchEvent(new CustomEvent('ui-sound', { detail: on }));
}

function audio(): AudioContext | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const AC = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;

    if (!AC) {
        return null;
    }

    ctx ??= new AC();

    if (ctx.state === 'suspended') {
        void ctx.resume();
    }

    return ctx;
}

function tone(a: AudioContext, freq: number, start: number, dur: number, gain: number, type: OscillatorType = 'sine', to?: number): void {
    const o = a.createOscillator();
    const g = a.createGain();
    o.type = type;
    o.frequency.setValueAtTime(freq, start);

    if (to) {
        o.frequency.exponentialRampToValueAtTime(to, start + dur);
    }

    g.gain.setValueAtTime(0.0001, start);
    g.gain.exponentialRampToValueAtTime(gain, start + 0.006);
    g.gain.exponentialRampToValueAtTime(0.0001, start + dur);
    o.connect(g).connect(a.destination);
    o.start(start);
    o.stop(start + dur + 0.02);
}

export function play(cue: Cue = 'tap'): void {
    if (!soundOn()) {
        return;
    }

    const now = performance.now();

    if (now - last < 45) {
        return; // one sound per click, even when several handlers fire
    }

    last = now;
    const a = audio();

    if (!a) {
        return;
    }

    const t = a.currentTime;

    switch (cue) {
        case 'tap':
            tone(a, 1250, t, 0.045, 0.035, 'triangle', 900);
            break;
        case 'open':
            tone(a, 520, t, 0.08, 0.03, 'sine');
            tone(a, 780, t + 0.05, 0.1, 0.028, 'sine');
            break;
        case 'toggle':
            tone(a, 900, t, 0.05, 0.03, 'square', 1200);
            break;
        case 'success':
            tone(a, 660, t, 0.09, 0.03);
            tone(a, 880, t + 0.07, 0.09, 0.03);
            tone(a, 1320, t + 0.14, 0.16, 0.026);
            break;
        case 'nope':
            tone(a, 220, t, 0.12, 0.04, 'triangle', 160);
            break;
    }
}
