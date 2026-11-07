<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** The words spoken in a recording (from a speech-to-text service), or a translation of them. */
class MeetingTranscript extends Model
{
    use BelongsToTenant;

    protected $fillable = ['meeting_recording_id', 'source_id', 'kind', 'language', 'status', 'segments', 'text', 'provider', 'error', 'requested_by'];

    protected function casts(): array
    {
        return ['segments' => 'array'];
    }

    public function recording(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MeetingRecording::class, 'meeting_recording_id');
    }

    /** WebVTT, for the video player and for captions. */
    public function toVtt(): string
    {
        $out = "WEBVTT\n\n";
        foreach ((array) $this->segments as $i => $s) {
            $out .= ($i + 1)."\n".self::time($s['start'] ?? 0, '.').' --> '.self::time($s['end'] ?? 0, '.')."\n".trim((string) ($s['text'] ?? ''))."\n\n";
        }

        return $out;
    }

    public function toSrt(): string
    {
        $out = '';
        foreach ((array) $this->segments as $i => $s) {
            $out .= ($i + 1)."\n".self::time($s['start'] ?? 0, ',').' --> '.self::time($s['end'] ?? 0, ',')."\n".trim((string) ($s['text'] ?? ''))."\n\n";
        }

        return $out;
    }

    private static function time(float|int|string $seconds, string $sep): string
    {
        $ms = (int) round((float) $seconds * 1000);

        return sprintf('%02d:%02d:%02d%s%03d', intdiv($ms, 3_600_000), intdiv($ms % 3_600_000, 60_000), intdiv($ms % 60_000, 1000), $sep, $ms % 1000);
    }
}
