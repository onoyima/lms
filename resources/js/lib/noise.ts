/**
 * Noise alerts (Target 31): listens to the microphone level in the browser only — no audio
 * leaves the computer for this — and reports sustained talking or noise as a proctoring event.
 */
export function watchNoise(stream: MediaStream, onNoise: (level: number, seconds: number) => void, opts: { threshold?: number; seconds?: number; cooldownMs?: number } = {}) {
    // the room's own background (fans, generators, a busy hall) is measured for the first 20 seconds,
    // and only sound well above it — held for several seconds — counts, so steady noise never alerts
    let threshold = opts.threshold ?? 0.06; // RMS of the signal, 0..1
    const needed = opts.seconds ?? 5;
    const calibration: number[] = [];
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

        if (calibration.length < 40) {
            calibration.push(rms);

            if (calibration.length === 40) {
                const sorted = [...calibration].sort((a, b) => a - b);
                const background = sorted[Math.floor(sorted.length * 0.6)];
                threshold = Math.max(threshold, background * 4);
            }

            return;
        }

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
