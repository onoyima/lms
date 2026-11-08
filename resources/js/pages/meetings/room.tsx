import { Head } from '@inertiajs/react';
import { Hand, MessageSquare, Mic, MicOff, MonitorUp, PhoneOff, Users, Video, VideoOff, Volume2, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Toaster } from '@/components/ui/sonner';

/**
 * Built-in meeting room. Each browser sends its camera and microphone straight to every other browser
 * (WebRTC, one connection per pair); the platform only passes the connection details between them by
 * polling, so no meeting server is needed. Every connection carries exactly one audio and one video
 * channel from the start, so turning the camera off, sharing the screen and switching back only swap
 * what is sent on them (no renegotiation).
 */

type Card = { peer_id: string; name: string; role: string; audio: boolean; video: boolean; hand: boolean; screen: boolean; guest: boolean };
type Signal = { id: number; from: string; type: string; payload: string | null };
type Poll = { state: string; meeting: string; peers: Card[]; waiting: Card[]; signals: Signal[]; last: number; me: Card };
type Props = {
    meeting: { title: string; starts_at: string; ends_at: string; welcome: string | null; lock_cameras: boolean; lock_microphones: boolean; lock_chat: boolean; mute_on_start: boolean };
    me: Card & { moderator: boolean; state: string };
    ice: RTCIceServer[];
    endpoints: { base: string; exit: string; end: string | null };
    max: number;
    pollMs: number;
    csrf: string;
};
type Link = { pc: RTCPeerConnection; pending: RTCIceCandidateInit[]; makingOffer: boolean; since: number };
type Chat = { from: string; name: string; text: string; at: string; mine: boolean };

const initials = (name: string) => name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('') || '?';

function Tile({ name, stream, muted, mirror, audio, video, hand, screen, conn, label, onNeedsSound }: {
    name: string; stream: MediaStream | null; muted: boolean; mirror?: boolean; audio: boolean; video: boolean; hand: boolean; screen?: boolean; conn?: string; label?: string; onNeedsSound?: () => void;
}) {
    const ref = useRef<HTMLVideoElement>(null);

    useEffect(() => {
        const el = ref.current;

        if (!el) {
            return;
        }

        if (el.srcObject !== stream) {
            el.srcObject = stream;
        }

        if (stream) {
            el.play().catch(() => onNeedsSound?.());
        }
    }, [stream, onNeedsSound]);

    return (
        <div className="relative flex min-h-0 items-center justify-center overflow-hidden rounded-xl bg-[#1c2128]" data-peer-tile={label ?? 'remote'} data-conn={conn ?? 'self'}>
            <video ref={ref} autoPlay playsInline muted={muted} className={`h-full w-full ${screen ? 'object-contain' : 'object-cover'} ${mirror && !screen ? '-scale-x-100' : ''} ${video && stream ? '' : 'invisible'}`} />
            {!(video && stream) && (
                <div className="absolute inset-0 flex items-center justify-center">
                    <span className="flex size-20 items-center justify-center rounded-full bg-white/10 text-2xl font-semibold text-white/90">{initials(name)}</span>
                </div>
            )}
            <div className="absolute inset-x-2 bottom-2 flex items-center gap-1.5">
                <span className="truncate rounded-md bg-black/55 px-2 py-0.5 text-xs text-white">{name}</span>
                {!audio && <span className="rounded-md bg-black/55 p-1 text-white" title="Microphone off"><MicOff className="size-3.5" /></span>}
                {conn && conn !== 'connected' && <span className="rounded-md bg-amber-500/90 px-1.5 py-0.5 text-[11px] text-black">{conn === 'failed' ? 'reconnecting' : 'connecting'}</span>}
            </div>
            {hand && <span className="absolute right-2 top-2 rounded-full bg-amber-400 p-1.5 text-black" title="Hand raised"><Hand className="size-4" /></span>}
        </div>
    );
}

export default function Room({ meeting, me, ice, endpoints, max, pollMs, csrf }: Props) {
    const [state, setState] = useState(me.state);
    const [meetingStatus, setMeetingStatus] = useState('live');
    const [peers, setPeers] = useState<Card[]>([]);
    const [waiting, setWaiting] = useState<Card[]>([]);
    const [streams, setStreams] = useState<Record<string, MediaStream>>({});
    const [conns, setConns] = useState<Record<string, string>>({});
    const [local, setLocal] = useState<MediaStream | null>(null);
    const [mic, setMic] = useState(!(meeting.mute_on_start && !me.moderator) && !(meeting.lock_microphones && !me.moderator));
    const [cam, setCam] = useState(!(meeting.lock_cameras && !me.moderator));
    const [sharing, setSharing] = useState(false);
    const [hand, setHand] = useState(false);
    const [panel, setPanel] = useState<'chat' | 'people' | null>(null);
    const [chat, setChat] = useState<Chat[]>([]);
    const [unread, setUnread] = useState(0);
    const [draft, setDraft] = useState('');
    const [notice, setNotice] = useState<string | null>(meeting.welcome);
    const [needsSound, setNeedsSound] = useState(false);
    const [mediaError, setMediaError] = useState<string | null>(null);

    const links = useRef(new Map<string, Link>());
    const after = useRef(0);
    const firstIn = useRef(true);
    const localRef = useRef<MediaStream | null>(null);
    const audioTrack = useRef<MediaStreamTrack | null>(null);
    const cameraTrack = useRef<MediaStreamTrack | null>(null);
    const screenTrack = useRef<MediaStreamTrack | null>(null);
    const flags = useRef({ audio: mic, video: cam, hand, screen: sharing });
    const stateRef = useRef(state);
    const panelRef = useRef(panel);
    const stopped = useRef(false);

    const offerRef = useRef<(peerId: string) => void>(() => undefined);
    const [hasAudio, setHasAudio] = useState(false);

    useEffect(() => {
        flags.current = { audio: mic, video: cam || sharing, hand, screen: sharing };
    }, [mic, cam, sharing, hand]);
    useEffect(() => {
        stateRef.current = state;
        panelRef.current = panel;
    }, [state, panel]);

    const post = useCallback(async (path: string, body: Record<string, unknown> = {}, keepalive = false) => {
        const res = await fetch(endpoints.base + path, {
            method: 'POST', credentials: 'same-origin', keepalive,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body),
        });

        if (!res.ok) {
            throw new Error(String(res.status));
        }

        return res.json();
    }, [endpoints.base, csrf]);

    const send = useCallback((to: string | null, type: string, payload: string | null) => post('/signal', { to, type, payload }).catch(() => undefined), [post]);

    /** What goes out on the video channel: the shared screen, else the camera, else nothing. */
    const outgoingVideo = () => screenTrack.current ?? cameraTrack.current;

    const refreshRemote = useCallback((peerId: string, pc: RTCPeerConnection) => {
        const tracks = pc.getReceivers().map((r) => r.track).filter((t): t is MediaStreamTrack => !!t && t.readyState === 'live');
        setStreams((s) => ({ ...s, [peerId]: new MediaStream(tracks) }));
    }, []);

    const dropLink = useCallback((peerId: string) => {
        links.current.get(peerId)?.pc.close();
        links.current.delete(peerId);
        setStreams((s) => {
            const n = { ...s };
            delete n[peerId];

            return n;
        });
        setConns((c) => {
            const n = { ...c };
            delete n[peerId];

            return n;
        });
    }, []);

    const makeLink = useCallback((peerId: string): Link => {
        dropLink(peerId);
        const pc = new RTCPeerConnection({ iceServers: ice });
        const link: Link = { pc, pending: [], makingOffer: false, since: Date.now() };
        links.current.set(peerId, link);
        pc.onicecandidate = (e) => {
            if (e.candidate) {
                void send(peerId, 'candidate', JSON.stringify(e.candidate.toJSON()));
            }
        };
        pc.ontrack = () => refreshRemote(peerId, pc);
        pc.onconnectionstatechange = () => {
            setConns((c) => ({ ...c, [peerId]: pc.connectionState }));

            if (pc.connectionState === 'failed' && me.peer_id < peerId) {
                // one side (the lower id) starts again
                setTimeout(() => links.current.get(peerId)?.pc === pc && offerRef.current(peerId), 1500);
            }
        };

        return link;
    }, [ice, send, refreshRemote, dropLink, me.peer_id]);

    const flush = async (link: Link) => {
        for (const c of link.pending.splice(0)) {
            await link.pc.addIceCandidate(c).catch(() => undefined);
        }
    };

    const offer = useCallback(async (peerId: string) => {
        const link = makeLink(peerId);
        const { pc } = link;
        const stream = localRef.current ?? new MediaStream();
        pc.addTransceiver(audioTrack.current ?? 'audio', { direction: 'sendrecv', streams: [stream] });
        pc.addTransceiver(outgoingVideo() ?? 'video', { direction: 'sendrecv', streams: [stream] });
        link.makingOffer = true;

        try {
            await pc.setLocalDescription(await pc.createOffer());
            await send(peerId, 'offer', JSON.stringify(pc.localDescription));
        } finally {
            link.makingOffer = false;
        }
    }, [makeLink, send]);

    useEffect(() => {
        offerRef.current = (peerId: string) => void offer(peerId);
    }, [offer]);

    const answer = useCallback(async (peerId: string, sdp: RTCSessionDescriptionInit) => {
        const existing = links.current.get(peerId);

        // both sides offered at once: the lower id gives way and answers; the higher keeps its own offer
        if (existing && (existing.makingOffer || existing.pc.signalingState === 'have-local-offer') && me.peer_id > peerId) {
            return;
        }

        const link = makeLink(peerId);
        const { pc } = link;
        await pc.setRemoteDescription(sdp);

        for (const t of pc.getTransceivers()) {
            t.direction = 'sendrecv';
            const kind = t.receiver.track?.kind;
            await t.sender.replaceTrack(kind === 'audio' ? audioTrack.current : outgoingVideo()).catch(() => undefined);

            if (localRef.current && 'setStreams' in t.sender) {
                (t.sender as RTCRtpSender & { setStreams: (s: MediaStream) => void }).setStreams(localRef.current);
            }
        }

        await pc.setLocalDescription(await pc.createAnswer());
        await send(peerId, 'answer', JSON.stringify(pc.localDescription));
        await flush(link);
    }, [makeLink, send, me.peer_id]);

    const handle = useCallback(async (s: Signal) => {
        const payload = s.payload ?? '';

        try {
            if (s.type === 'offer') {
                await answer(s.from, JSON.parse(payload));
            } else if (s.type === 'answer') {
                const link = links.current.get(s.from);

                if (link && link.pc.signalingState === 'have-local-offer') {
                    await link.pc.setRemoteDescription(JSON.parse(payload));
                    await flush(link);
                }
            } else if (s.type === 'candidate') {
                const link = links.current.get(s.from);
                const c = JSON.parse(payload) as RTCIceCandidateInit;

                if (!link) {
                    return;
                }

                if (link.pc.remoteDescription) {
                    await link.pc.addIceCandidate(c).catch(() => undefined);
                } else {
                    link.pending.push(c);
                }
            } else if (s.type === 'chat') {
                const c = JSON.parse(payload) as { name: string; text: string; at: string };
                setChat((list) => [...list.slice(-199), { from: s.from, name: c.name, text: c.text, at: c.at, mine: false }]);

                if (panelRef.current !== 'chat') {
                    setUnread((n) => n + 1);
                }
            } else if (s.type === 'mute') {
                if (audioTrack.current) {
                    audioTrack.current.enabled = false;
                }

                setMic(false);
                setNotice('The host turned your microphone off.');
            }
        } catch {
            // a broken message from one browser must not stop the room
        }
    }, [answer]);

    // ── camera and microphone ───────────────────────────────────────────────
    useEffect(() => {
        let cancelled = false;

        (async () => {
            const video = { width: { ideal: 640 }, height: { ideal: 360 }, frameRate: { ideal: 15 } };
            let stream: MediaStream | null = null;

            try {
                stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true }, video });
            } catch {
                try {
                    stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                    setMediaError('Your camera is not available, so others will hear you but not see you.');
                } catch {
                    setMediaError('The browser did not allow the camera or microphone. You can still watch and listen; allow them in the address bar and reload to take part.');
                }
            }

            if (cancelled) {
                stream?.getTracks().forEach((t) => t.stop());

                return;
            }

            if (stream) {
                audioTrack.current = stream.getAudioTracks()[0] ?? null;
                cameraTrack.current = stream.getVideoTracks()[0] ?? null;

                if (audioTrack.current) {
                    audioTrack.current.enabled = flags.current.audio;
                }

                if (cameraTrack.current && !flags.current.video) {
                    cameraTrack.current.stop();
                    stream.removeTrack(cameraTrack.current);
                    cameraTrack.current = null;
                }
            }

            localRef.current = stream ?? new MediaStream();
            setHasAudio(!!audioTrack.current);
            setLocal(localRef.current);
        })();

        return () => {
            cancelled = true;
        };
    }, []);

    const setVideoOut = async (track: MediaStreamTrack | null) => {
        for (const { pc } of links.current.values()) {
            const t = pc.getTransceivers().find((x) => x.receiver.track?.kind === 'video');
            await t?.sender.replaceTrack(track).catch(() => undefined);
        }
    };

    const toggleMic = () => {
        if (!mic && meeting.lock_microphones && !me.moderator) {
            return setNotice('The host has switched microphones off for this meeting.');
        }

        if (audioTrack.current) {
            audioTrack.current.enabled = !mic;
        }

        setMic(!mic);
    };

    const toggleCam = async () => {
        if (cam) {
            cameraTrack.current?.stop();

            if (cameraTrack.current) {
                localRef.current?.removeTrack(cameraTrack.current);
            }

            cameraTrack.current = null;

            if (!screenTrack.current) {
                await setVideoOut(null);
            }

            setCam(false);
            setLocal(localRef.current ? new MediaStream(localRef.current.getTracks()) : null);

            return;
        }

        if (meeting.lock_cameras && !me.moderator) {
            return setNotice('The host has switched cameras off for this meeting.');
        }

        try {
            const s = await navigator.mediaDevices.getUserMedia({ video: { width: { ideal: 640 }, height: { ideal: 360 }, frameRate: { ideal: 15 } } });
            cameraTrack.current = s.getVideoTracks()[0];
            localRef.current?.addTrack(cameraTrack.current);

            if (!screenTrack.current) {
                await setVideoOut(cameraTrack.current);
            }

            setCam(true);
            setLocal(localRef.current ? new MediaStream(localRef.current.getTracks()) : null);
        } catch {
            setNotice('The camera could not be started. Check that no other app is using it.');
        }
    };

    const stopShare = async () => {
        screenTrack.current?.stop();
        screenTrack.current = null;
        setSharing(false);
        await setVideoOut(cameraTrack.current);
    };

    const toggleShare = async () => {
        if (sharing) {
            return stopShare();
        }

        try {
            const s = await navigator.mediaDevices.getDisplayMedia({ video: { frameRate: { ideal: 10 } }, audio: false });
            screenTrack.current = s.getVideoTracks()[0];
            screenTrack.current.onended = () => void stopShare();
            await setVideoOut(screenTrack.current);
            setSharing(true);
        } catch {
            // the person closed the picker
        }
    };

    // ── checking in with the platform ──────────────────────────────────────
    useEffect(() => {
        if (!local) {
            return;
        }

        let timer: ReturnType<typeof setTimeout>;
        let failures = 0;

        const tick = async () => {
            if (stopped.current) {
                return;
            }

            try {
                const r = (await post('/poll', { after: after.current, ...flags.current })) as Poll;
                failures = 0;
                after.current = Math.max(after.current, r.last);
                setState(r.state);
                setMeetingStatus(r.meeting);
                setWaiting(r.waiting);

                if (r.state === 'in') {
                    const ids = new Set(r.peers.map((p) => p.peer_id));

                    for (const id of [...links.current.keys()]) {
                        if (!ids.has(id)) {
                            dropLink(id);
                        }
                    }

                    if (firstIn.current) {
                        // just came in: call everyone already here (they answer)
                        firstIn.current = false;

                        for (const p of r.peers) {
                            void offer(p.peer_id);
                        }
                    }

                    for (const s of r.signals) {
                        await handle(s);
                    }

                    // someone here for a while with no connection (a lost message): the lower id calls
                    for (const p of r.peers) {
                        const l = links.current.get(p.peer_id);

                        if (!l && me.peer_id < p.peer_id) {
                            setTimeout(() => !links.current.get(p.peer_id) && void offer(p.peer_id), 4000);
                        }
                    }

                    setPeers(r.peers);
                }

                if (['left', 'removed', 'refused'].includes(r.state) || r.meeting === 'ended') {
                    stopped.current = true;

                    for (const id of [...links.current.keys()]) {
                        dropLink(id);
                    }
                }
            } catch {
                failures++;
            }

            timer = setTimeout(tick, failures ? Math.min(10000, pollMs * (failures + 1)) : pollMs);
        };

        void tick();

        return () => clearTimeout(timer);
    }, [local, post, handle, offer, dropLink, pollMs, me.peer_id]);

    // leaving: tell the platform even when the tab is closed
    useEffect(() => {
        const bye = () => {
            if (!stopped.current) {
                void post('/leave', {}, true).catch(() => undefined);
            }
        };
        window.addEventListener('pagehide', bye);

        return () => window.removeEventListener('pagehide', bye);
    }, [post]);

    useEffect(() => () => {
        localRef.current?.getTracks().forEach((t) => t.stop());
        screenTrack.current?.stop();
        links.current.forEach(({ pc }) => pc.close());
    }, []);

    const leave = async () => {
        stopped.current = true;
        await post('/leave').catch(() => undefined);
        localRef.current?.getTracks().forEach((t) => t.stop());
        window.location.href = endpoints.exit;
    };

    const endForAll = async () => {
        if (!endpoints.end || !confirm('End the meeting for everyone?')) {
            return;
        }

        stopped.current = true;
        await fetch(endpoints.end, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } }).catch(() => undefined);
        window.location.href = endpoints.exit;
    };

    const sendChat = async () => {
        const text = draft.trim();

        if (!text) {
            return;
        }

        setDraft('');
        setChat((list) => [...list, { from: me.peer_id, name: me.name, text, at: new Date().toISOString(), mine: true }]);
        await send(null, 'chat', text);
    };

    const admit = (peer: string, yes: boolean) => post('/admit', { peer, admit: yes }).catch(() => undefined);
    const remove = (peer: string) => confirm('Remove this person from the meeting?') && post('/remove', { peer }).catch(() => undefined);

    // ── screens other than the room ────────────────────────────────────────
    const ended = meetingStatus === 'ended';
    const gone = ['left', 'removed', 'refused'].includes(state);

    if (ended || gone || state === 'waiting') {
        const text = ended ? 'The meeting has ended.' : state === 'removed' ? 'The host removed you from the meeting.' : state === 'refused' ? 'The host did not let you in.'
            : state === 'left' ? 'You joined this meeting in another window, so this one was closed.' : 'Please wait: a host will let you in shortly.';

        return (
            <div className="flex min-h-dvh flex-col items-center justify-center gap-5 bg-[#0f1216] p-6 text-center text-white">
                <Head title={meeting.title} />
                <p className="text-sm uppercase tracking-wide text-white/50">{meeting.title}</p>
                <p className="max-w-md text-xl font-semibold">{text}</p>
                {state === 'waiting' && local && <div className="h-48 w-72"><Tile name={me.name} stream={local} muted mirror audio={mic} video={cam} hand={false} /></div>}
                <button className="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10" onClick={() => (state === 'waiting' ? void leave() : (window.location.href = endpoints.exit))}>
                    {state === 'waiting' ? 'Leave' : 'Back'}
                </button>
</div>
        <Toaster />
    );
}

    const tiles = 1 + peers.length;
    const cols = tiles <= 1 ? 'grid-cols-1' : tiles <= 4 ? 'grid-cols-1 sm:grid-cols-2' : 'grid-cols-2 lg:grid-cols-3';
    const btn = (on: boolean) => `flex size-12 items-center justify-center rounded-full transition ${on ? 'bg-white/10 hover:bg-white/20' : 'bg-red-600 hover:bg-red-500'}`;

    return (
        <div className="flex h-dvh flex-col bg-[#0f1216] text-white">
            <Head title={meeting.title} />
            <header className="flex shrink-0 items-center justify-between gap-3 px-4 py-2.5">
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold">{meeting.title}</p>
                    <p className="text-xs text-white/50">{tiles} of {max} in the room</p>
                </div>
                {waiting.length > 0 && me.moderator && (
                    <button className="rounded-full bg-amber-400 px-3 py-1 text-xs font-semibold text-black" onClick={() => setPanel('people')}>{waiting.length} waiting to join</button>
                )}
            </header>

            {(notice || mediaError) && (
                <div className="mx-4 mb-2 flex items-start justify-between gap-3 rounded-lg bg-white/10 px-3 py-2 text-sm">
                    <span>{mediaError ?? notice}</span>
                    <button aria-label="Close" onClick={() => (mediaError ? setMediaError(null) : setNotice(null))}><X className="size-4" /></button>
                </div>
            )}
            {needsSound && (
                <button className="mx-4 mb-2 flex items-center justify-center gap-2 rounded-lg bg-brand px-3 py-2 text-sm font-semibold text-brand-foreground" onClick={() => {
                    document.querySelectorAll('video').forEach((v) => void v.play().catch(() => undefined));
                    setNeedsSound(false);
                }}><Volume2 className="size-4" /> Turn on sound</button>
            )}

            <div className="flex min-h-0 flex-1 gap-3 px-4 pb-3">
                <main className={`grid min-h-0 flex-1 auto-rows-fr gap-3 ${cols}`}>
                    <Tile label="me" name={`${me.name} (you)`} stream={local} muted mirror audio={mic} video={cam || sharing} hand={hand} screen={sharing} />
                    {peers.map((p) => (
                        <Tile key={p.peer_id} label={p.peer_id} name={p.name} stream={streams[p.peer_id] ?? null} muted={false} audio={p.audio} video={p.video} hand={p.hand} screen={p.screen}
                            conn={conns[p.peer_id] ?? 'new'} onNeedsSound={() => setNeedsSound(true)} />
                    ))}
                </main>

                {panel && (
                    <aside className="flex w-full max-w-xs shrink-0 flex-col overflow-hidden rounded-xl bg-[#1c2128] max-md:fixed max-md:inset-x-3 max-md:bottom-24 max-md:top-16 max-md:z-10 max-md:max-w-none">
                        <div className="flex items-center justify-between border-b border-white/10 px-3 py-2">
                            <p className="text-sm font-semibold">{panel === 'chat' ? 'Chat' : 'People'}</p>
                            <button aria-label="Close panel" onClick={() => setPanel(null)}><X className="size-4" /></button>
                        </div>
                        {panel === 'chat' ? (
                            <>
                                <div className="flex-1 space-y-2 overflow-y-auto p-3 text-sm">
                                    {chat.length === 0 && <p className="text-white/50">No messages yet.</p>}
                                    {chat.map((c, i) => (
                                        <div key={i} className={c.mine ? 'text-right' : ''}>
                                            <p className="text-[11px] text-white/50">{c.mine ? 'You' : c.name}</p>
                                            <p className={`inline-block max-w-full whitespace-pre-wrap break-words rounded-lg px-2.5 py-1.5 text-left ${c.mine ? 'bg-brand text-brand-foreground' : 'bg-white/10'}`}>{c.text}</p>
                                        </div>
                                    ))}
                                </div>
                                <form className="flex gap-2 border-t border-white/10 p-2" onSubmit={(e) => (e.preventDefault(), void sendChat())}>
                                    <input aria-label="Message" className="min-w-0 flex-1 rounded-md bg-white/10 px-2 py-1.5 text-sm outline-none placeholder:text-white/40"
                                        placeholder={meeting.lock_chat && !me.moderator ? 'Chat is off for this meeting' : 'Write a message'} disabled={meeting.lock_chat && !me.moderator}
                                        value={draft} onChange={(e) => setDraft(e.target.value)} maxLength={1000} />
                                    <button className="rounded-md bg-white/10 px-3 text-sm disabled:opacity-40" disabled={!draft.trim()}>Send</button>
                                </form>
                            </>
                        ) : (
                            <div className="flex-1 overflow-y-auto p-3 text-sm">
                                {me.moderator && waiting.length > 0 && (
                                    <div className="mb-3 space-y-2">
                                        <p className="text-xs uppercase tracking-wide text-amber-300">Waiting to join</p>
                                        {waiting.map((w) => (
                                            <div key={w.peer_id} className="flex items-center justify-between gap-2">
                                                <span className="truncate">{w.name}</span>
                                                <span className="flex shrink-0 gap-1">
                                                    <button className="rounded bg-emerald-600 px-2 py-0.5 text-xs" onClick={() => void admit(w.peer_id, true)}>Let in</button>
                                                    <button className="rounded bg-white/10 px-2 py-0.5 text-xs" onClick={() => void admit(w.peer_id, false)}>Refuse</button>
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                                <p className="mb-1 text-xs uppercase tracking-wide text-white/50">In the room</p>
                                <div className="flex items-center justify-between py-1"><span>{me.name} (you)</span>{!mic && <MicOff className="size-3.5 text-white/60" />}</div>
                                {peers.map((p) => (
                                    <div key={p.peer_id} className="flex items-center justify-between gap-2 py-1">
                                        <span className="truncate">{p.name}{p.role === 'host' ? ' · host' : ''}</span>
                                        <span className="flex shrink-0 items-center gap-1">
                                            {p.hand && <Hand className="size-3.5 text-amber-300" />}
                                            {!p.audio && <MicOff className="size-3.5 text-white/60" />}
                                            {me.moderator && p.audio && <button className="rounded bg-white/10 px-1.5 text-[11px]" onClick={() => void send(p.peer_id, 'mute', null)}>Mute</button>}
                                            {me.moderator && p.role !== 'host' && <button className="rounded bg-white/10 px-1.5 text-[11px]" onClick={() => void remove(p.peer_id)}>Remove</button>}
                                        </span>
                                    </div>
                                ))}
                                {me.moderator && peers.length > 0 && <button className="mt-3 w-full rounded-md bg-white/10 py-1.5 text-xs" onClick={() => void send(null, 'mute', null)}>Mute everyone</button>}
                            </div>
                        )}
                    </aside>
                )}
            </div>

            <footer className="flex shrink-0 flex-wrap items-center justify-center gap-2 px-4 pb-4">
                <button className={btn(mic)} onClick={toggleMic} aria-label={mic ? 'Turn microphone off' : 'Turn microphone on'} title={mic ? 'Microphone on' : 'Microphone off'} disabled={!hasAudio && !mic}>
                    {mic ? <Mic className="size-5" /> : <MicOff className="size-5" />}
                </button>
                <button className={btn(cam)} onClick={() => void toggleCam()} aria-label={cam ? 'Turn camera off' : 'Turn camera on'} title={cam ? 'Camera on' : 'Camera off'}>
                    {cam ? <Video className="size-5" /> : <VideoOff className="size-5" />}
                </button>
                {'getDisplayMedia' in (navigator.mediaDevices ?? {}) && (
                    <button className={`flex size-12 items-center justify-center rounded-full ${sharing ? 'bg-brand text-brand-foreground' : 'bg-white/10 hover:bg-white/20'}`} onClick={() => void toggleShare()} aria-label={sharing ? 'Stop sharing your screen' : 'Share your screen'} title="Share screen">
                        <MonitorUp className="size-5" />
                    </button>
                )}
                <button className={`flex size-12 items-center justify-center rounded-full ${hand ? 'bg-amber-400 text-black' : 'bg-white/10 hover:bg-white/20'}`} onClick={() => setHand(!hand)} aria-label={hand ? 'Lower hand' : 'Raise hand'} title="Raise hand">
                    <Hand className="size-5" />
                </button>
                <button className="relative flex size-12 items-center justify-center rounded-full bg-white/10 hover:bg-white/20" onClick={() => (setPanel(panel === 'chat' ? null : 'chat'), setUnread(0))} aria-label="Chat" title="Chat">
                    <MessageSquare className="size-5" />
                    {unread > 0 && <span className="absolute -right-0.5 -top-0.5 rounded-full bg-red-600 px-1.5 text-[10px] font-semibold">{unread}</span>}
                </button>
                <button className="relative flex size-12 items-center justify-center rounded-full bg-white/10 hover:bg-white/20" onClick={() => setPanel(panel === 'people' ? null : 'people')} aria-label="People" title="People">
                    <Users className="size-5" />
                    {waiting.length > 0 && me.moderator && <span className="absolute -right-0.5 -top-0.5 rounded-full bg-amber-400 px-1.5 text-[10px] font-semibold text-black">{waiting.length}</span>}
                </button>
                <button className="flex h-12 items-center gap-2 rounded-full bg-red-600 px-5 text-sm font-semibold hover:bg-red-500" onClick={() => void leave()} aria-label="Leave">
                    <PhoneOff className="size-5" /> Leave
                </button>
                {endpoints.end && <button className="h-12 rounded-full border border-red-500/60 px-4 text-sm text-red-300 hover:bg-red-500/10" onClick={() => void endForAll()}>End for everyone</button>}
            </footer>
        </div>
    );
}
