/**
 * Face analysis in the browser with a model served by this platform (no outside service).
 * Loaded only when an exam or the enrolment page needs it, so other pages stay light.
 * Gives the number of faces, a 128-number template of the main face (compared on the
 * server, never here), and the head's yaw/pitch for "looking away" detection.
 */
import type * as FaceApiModule from '@vladmandic/face-api';

type FaceApi = typeof FaceApiModule;

let api: Promise<FaceApi> | null = null;

export function loadFace(): Promise<FaceApi> {
    api ??= (async () => {
        const faceapi = await import('@vladmandic/face-api');
        const url = '/vendor/face-api/model';
        await Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(url),
            faceapi.nets.faceLandmark68Net.loadFromUri(url),
            faceapi.nets.faceRecognitionNet.loadFromUri(url),
        ]);

        return faceapi;
    })();

    return api;
}

export type FaceReading = { faces: number; descriptor: number[] | null; yaw: number | null; pitch: number | null; score: number | null };

/** Analyse one frame. Only the largest face is described. */
export async function readFace(source: HTMLVideoElement | HTMLImageElement | HTMLCanvasElement, withDescriptor = true): Promise<FaceReading> {
    const faceapi = await loadFace();
    const options = new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 });
    const all = withDescriptor
        ? await faceapi.detectAllFaces(source, options).withFaceLandmarks().withFaceDescriptors()
        : await faceapi.detectAllFaces(source, options).withFaceLandmarks();

    if (all.length === 0) {
        return { faces: 0, descriptor: null, yaw: null, pitch: null, score: null };
    }

    const main = all.reduce((a, b) => (a.detection.box.area > b.detection.box.area ? a : b));
    const pose = headPose(main.landmarks.positions);
    const raw = 'descriptor' in main ? (main as { descriptor: Float32Array }).descriptor : null;
    // a template is only sent when exactly one face is in view
    const descriptor = raw && all.length === 1 ? Array.from(raw).map((v) => Math.round(v * 1e6) / 1e6) : null;

    return { faces: all.length, descriptor, yaw: pose.yaw, pitch: pose.pitch, score: main.detection.score };
}

/**
 * Rough head pose from 68 landmarks: yaw from how far the nose tip sits from the middle of
 * the eyes relative to eye distance, pitch from nose position between eyes and chin.
 * Values are about -1..1; |yaw| > 0.35 or |pitch| > 0.4 means looking well away.
 */
function headPose(p: { x: number; y: number }[]): { yaw: number; pitch: number } {
    const leftEye = p[36], rightEye = p[45], nose = p[30], chin = p[8];
    const eyeMid = { x: (leftEye.x + rightEye.x) / 2, y: (leftEye.y + rightEye.y) / 2 };
    const eyeDist = Math.hypot(rightEye.x - leftEye.x, rightEye.y - leftEye.y) || 1;
    const yaw = (nose.x - eyeMid.x) / eyeDist;
    const faceH = Math.hypot(chin.x - eyeMid.x, chin.y - eyeMid.y) || 1;
    const pitch = (nose.y - eyeMid.y) / faceH - 0.45; // ~0 when facing the screen

    return { yaw: Math.round(yaw * 100) / 100, pitch: Math.round(pitch * 100) / 100 };
}

/** Grab a JPEG of the current frame for a person to review later. */
export function frameToJpeg(video: HTMLVideoElement, width = 480): Promise<Blob | null> {
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = Math.round((video.videoHeight / (video.videoWidth || 1)) * width) || 360;
    canvas.getContext('2d')?.drawImage(video, 0, 0, canvas.width, canvas.height);

    return new Promise((resolve) => canvas.toBlob((b) => resolve(b), 'image/jpeg', 0.8));
}
