/**
 * Noise alerts (Target 31): listens to the microphone level in the browser only — no audio
 * leaves the computer for this — and reports sustained talking or noise as a proctoring event.
 */
export function watchNoise(stream: MediaStream, onNoise: (level: number, seconds: number) => void, opts: { threshold?: number; seconds?: number; cooldownMs?: number } = {}) {
    const threshold = opts.threshold ?? 0.06; // RMS of the signal, 0..1
    const needed = opts.seconds ?? 3;
    const cooldown = opts.cooldownMs ?? 60000;
    const Ctx = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;

    if (!Ctx || stream.getAudioTracks().length === 0) {
        return () => {};
    }

    const ctx = new Ctx();
    const source = ctx.createMediaStreamSource(stream);
    const analyser = ctx.createAnalyser();
    analyser.fftSize = 2048;
    source.connect(analyser);
    const buf = new Float32Array(analyser.fftSize);
    let loud = 0;
    let peak = 0;
    let last = 0;

    const timer = window.setInterval(() => {
        analyser.getFloatTimeDomainData(buf);
        let sum = 0;

        for (const v of buf) {
            sum += v * v;
        }

        const rms = Math.sqrt(sum / buf.length);
        loud = rms > threshold ? loud + 0.5 : Math.max(0, loud - 0.5);
        peak = Math.max(peak, rms);

        if (loud >= needed && Date.now() - last > cooldown) {
            last = Date.now();
            onNoise(Math.round(peak * 1000) / 1000, loud);
            loud = 0;
            peak = 0;
        }
    }, 500);

    return () => {
        window.clearInterval(timer);
        source.disconnect();
        void ctx.close();
    };
}
