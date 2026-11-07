<?php

namespace App\Conferencing;

use App\Models\MeetingRecording;
use App\Models\MeetingTranscript;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Transcripts of live-class recordings and their translation.
 *  - Speech to text: any service with the OpenAI-style /v1/audio/transcriptions API (a self-hosted
 *    faster-whisper server keeps the audio on campus). The audio comes from the BigBlueButton
 *    recording (presentation format: video/webcams.webm or .mp4 on the recording server).
 *  - Translation: a LibreTranslate-compatible /translate API, segment by segment, so timings stay.
 */
class Transcription
{
    public function enabled(): bool
    {
        return (string) config('conferencing.transcription.url') !== '';
    }

    public function translationEnabled(): bool
    {
        return (string) config('conferencing.translation.url') !== '';
    }

    /** Where the recording's audio can be downloaded from the recording server. */
    public function audioUrls(MeetingRecording $rec): array
    {
        $playback = (string) $rec->playback_url;
        $parts = parse_url($playback);
        if (! $playback || empty($parts['host'])) {
            return [];
        }
        $origin = ($parts['scheme'] ?? 'https').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $id = rawurlencode($rec->record_id);

        return ["$origin/presentation/$id/video/webcams.mp4", "$origin/presentation/$id/video/webcams.webm", "$origin/presentation/$id/deskshare/deskshare.mp4"];
    }

    public function transcribe(MeetingTranscript $t): void
    {
        $rec = $t->recording;
        $t->update(['status' => 'working', 'error' => null, 'provider' => (string) config('conferencing.transcription.model')]);
        $file = $this->download($rec);
        try {
            $req = Http::timeout(1800)->attach('file', fopen($file, 'r'), 'audio'.(str_ends_with($file, '.webm') ? '.webm' : '.mp4'));
            if ($key = config('conferencing.transcription.key')) {
                $req = $req->withToken($key);
            }
            $res = $req->post(rtrim((string) config('conferencing.transcription.url'), '/').'/v1/audio/transcriptions', array_filter([
                'model' => config('conferencing.transcription.model'), 'response_format' => 'verbose_json',
                'language' => config('conferencing.transcription.language') ?: null, 'timestamp_granularities[]' => 'segment',
            ]));
            if (! $res->successful()) {
                throw new RuntimeException('The speech-to-text service answered '.$res->status().'.');
            }
            $body = $res->json() ?? [];
            $segments = collect($body['segments'] ?? [])->map(fn ($s) => ['start' => round((float) ($s['start'] ?? 0), 2), 'end' => round((float) ($s['end'] ?? 0), 2), 'text' => trim((string) ($s['text'] ?? ''))])
                ->filter(fn ($s) => $s['text'] !== '')->values()->all();
            if (! $segments && trim((string) ($body['text'] ?? '')) !== '') {
                $segments = [['start' => 0, 'end' => (float) ($rec->duration_seconds ?? 0), 'text' => trim($body['text'])]];
            }
            $t->update(['status' => 'complete', 'segments' => $segments, 'text' => trim((string) ($body['text'] ?? collect($segments)->pluck('text')->implode(' '))),
                'language' => $body['language'] ?? $t->language]);
        } finally {
            @unlink($file);
        }
    }

    public function translate(MeetingTranscript $t): void
    {
        $source = MeetingTranscript::findOrFail($t->source_id);
        $t->update(['status' => 'working', 'error' => null, 'provider' => 'translation']);
        $segments = (array) $source->segments;
        $out = [];
        foreach (array_chunk($segments, 50) as $chunk) {
            $res = Http::timeout(300)->acceptJson()->post(rtrim((string) config('conferencing.translation.url'), '/').'/translate', array_filter([
                'q' => array_map(fn ($s) => $s['text'], $chunk), 'source' => $this->short($source->language) ?: 'auto', 'target' => $t->language,
                'format' => 'text', 'api_key' => config('conferencing.translation.key') ?: null,
            ]));
            if (! $res->successful()) {
                throw new RuntimeException('The translation service answered '.$res->status().'.');
            }
            $texts = (array) ($res->json('translatedText') ?? []);
            foreach ($chunk as $i => $s) {
                $out[] = ['start' => $s['start'], 'end' => $s['end'], 'text' => is_array($texts) ? (string) ($texts[$i] ?? '') : (string) $texts];
            }
        }
        $t->update(['status' => 'complete', 'segments' => $out, 'text' => collect($out)->pluck('text')->implode(' ')]);
    }

    /** "english" (Whisper's name) or "en-US" → "en" */
    private function short(?string $lang): ?string
    {
        if (! $lang) {
            return null;
        }
        $names = ['english' => 'en', 'french' => 'fr', 'arabic' => 'ar', 'hausa' => 'ha', 'yoruba' => 'yo', 'igbo' => 'ig', 'spanish' => 'es', 'portuguese' => 'pt', 'chinese' => 'zh', 'german' => 'de'];

        return $names[strtolower($lang)] ?? strtolower(substr($lang, 0, 2));
    }

    private function download(MeetingRecording $rec): string
    {
        $max = (int) config('conferencing.transcription.max_mb', 500) * 1024 * 1024;
        foreach ($this->audioUrls($rec) as $url) {
            $tmp = tempnam(sys_get_temp_dir(), 'rec').(str_ends_with($url, '.webm') ? '.webm' : '.mp4');
            try {
                $res = Http::timeout(600)->withOptions(['sink' => $tmp])->get($url);
            } catch (\Throwable) {
                @unlink($tmp);

                continue;
            }
            if ($res->successful() && filesize($tmp) > 1000 && filesize($tmp) <= $max) {
                return $tmp;
            }
            @unlink($tmp);
        }
        throw new RuntimeException('The recording\'s audio could not be downloaded from the meeting server.');
    }
}
