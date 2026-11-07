/**
 * Live media through the platform's own LiveKit server, loaded only when an exam uses it.
 * Candidates publish; proctors subscribe. Connection quality is reported so poor links are
 * visible to proctors and can fall back to snapshots.
 */
import type * as LK from 'livekit-client';

let lib: Promise<typeof LK> | null = null;

export const loadLive = () => (lib ??= import('livekit-client'));

export type Quality = 'excellent' | 'good' | 'poor' | 'lost' | 'unknown';

/** Publish existing tracks (the exam room already holds the camera and screen streams). */
export async function publish(url: string, token: string, tracks: { track: MediaStreamTrack; source: 'camera' | 'microphone' | 'screen_share' }[], onQuality?: (q: Quality) => void, onDisconnect?: () => void) {
    const L = await loadLive();
    const room = new L.Room({ adaptiveStream: true, dynacast: true, disconnectOnPageLeave: true });
    room.on(L.RoomEvent.ConnectionQualityChanged, (q, p) => p.isLocal && onQuality?.(q as Quality));
    room.on(L.RoomEvent.Disconnected, () => onDisconnect?.());
    await room.connect(url, token, { autoSubscribe: false });
    const map = { camera: L.Track.Source.Camera, microphone: L.Track.Source.Microphone, screen_share: L.Track.Source.ScreenShare };

    for (const t of tracks) {
        await room.localParticipant.publishTrack(t.track, { source: map[t.source], simulcast: t.source === 'camera', videoCodec: 'vp8' });
    }

    return room;
}

/** Add one more camera (e.g. a USB camera pointed at the desk) to a room that is already live. */
export async function publishExtra(room: LK.Room, track: MediaStreamTrack, name: string) {
    const L = await loadLive();

    return room.localParticipant.publishTrack(track, { source: L.Track.Source.Unknown, name, simulcast: false, videoCodec: 'vp8' });
}

export type LinkStats = { kbps: number | null; rttMs: number | null; jitterMs: number | null; lossPct: number | null };

/** What a proctor's link to one stream looks like: bitrate, round trip, jitter and loss (Target 146). */
export async function readStats(track: LK.RemoteTrack, previous?: { bytes: number; at: number }): Promise<{ stats: LinkStats; mark: { bytes: number; at: number } }> {
    const report = await track.getRTCStatsReport();
    let bytes = 0;
    let rtt: number | null = null;
    let jitter: number | null = null;
    let lost = 0;
    let received = 0;
    report?.forEach((s: Record<string, unknown>) => {
        if (s.type === 'inbound-rtp') {
            bytes += Number(s.bytesReceived ?? 0);
            jitter = typeof s.jitter === 'number' ? Math.round((s.jitter as number) * 1000) : jitter;
            lost += Number(s.packetsLost ?? 0);
            received += Number(s.packetsReceived ?? 0);
        }

        if (s.type === 'candidate-pair' && s.state === 'succeeded' && typeof s.currentRoundTripTime === 'number') {
            rtt = Math.round((s.currentRoundTripTime as number) * 1000);
        }
    });
    const now = Date.now();
    const kbps = previous && now > previous.at ? Math.max(0, Math.round(((bytes - previous.bytes) * 8) / (now - previous.at))) : null;

    return { stats: { kbps, rttMs: rtt, jitterMs: jitter, lossPct: received + lost > 0 ? Math.round((lost / (received + lost)) * 1000) / 10 : null }, mark: { bytes, at: now } };
}

export type Tile = { identity: string; source: string; track: LK.RemoteTrack; quality: Quality };

/** Watch a sitting: every candidate camera, phone and screen as it arrives. */
export async function watch(url: string, token: string, onChange: (tiles: Tile[]) => void) {
    const L = await loadLive();
    const room = new L.Room({ adaptiveStream: true });
    const tiles = new Map<string, Tile>();
    const emit = () => onChange([...tiles.values()]);
    const name = (pub: LK.RemoteTrackPublication) => (pub.source === L.Track.Source.Unknown && pub.trackName ? pub.trackName : pub.source);
    const key = (p: LK.RemoteParticipant, pub: LK.RemoteTrackPublication) => `${p.identity}:${name(pub)}`;

    room.on(L.RoomEvent.TrackSubscribed, (track, pub, p) => {
        if (track.kind === 'video' || pub.source === L.Track.Source.Microphone) {
            tiles.set(key(p, pub), { identity: p.identity, source: name(pub), track, quality: (p.connectionQuality as Quality) ?? 'unknown' });
            emit();
        }
    });
    room.on(L.RoomEvent.TrackUnsubscribed, (_t, pub, p) => {
        tiles.delete(key(p, pub));
        emit();
    });
    room.on(L.RoomEvent.ConnectionQualityChanged, (q, p) => {
        tiles.forEach((t) => t.identity === p.identity && (t.quality = q as Quality));
        emit();
    });
    await room.connect(url, token, { autoSubscribe: true });

    return room;
}
