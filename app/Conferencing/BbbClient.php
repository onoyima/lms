<?php

namespace App\Conferencing;

use App\Models\ConferenceServer;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;

/**
 * The BigBlueButton API, called only from the server.
 *
 *   https://server/bigbluebutton/api/{call}?{query}&checksum=hash({call}{query}{secret})
 *
 * The shared secret never leaves this class: browsers only ever receive a finished join address,
 * which BigBlueButton accepts once.
 */
class BbbClient
{
    public function __construct(private ConferenceServer $server) {}

    public static function for(ConferenceServer $server): self
    {
        return new self($server);
    }

    public function server(): ConferenceServer
    {
        return $this->server;
    }

    private function base(): string
    {
        $url = rtrim($this->server->base_url, '/');
        if (! str_ends_with($url, '/api')) {
            $url = (str_ends_with($url, '/bigbluebutton') ? $url : $url.'/bigbluebutton').'/api';
        }

        return $url;
    }

    /** The signed address of an API call. */
    public function url(string $call, array $params = []): string
    {
        $params = array_filter($params, fn ($v) => $v !== null && $v !== '');
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $algo = in_array($this->server->checksum_algo, ['sha1', 'sha256', 'sha384', 'sha512'], true) ? $this->server->checksum_algo : 'sha256';
        $checksum = hash($algo, $call.$query.$this->server->secret);

        return $this->base().'/'.$call.'?'.($query !== '' ? $query.'&' : '').'checksum='.$checksum;
    }

    /** A dropped connection is tried twice more (every call we make is safe to repeat; a repeat create is a no-op on BigBlueButton). */
    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout(20)->connectTimeout(8)->retry(3, 400, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException, throw: false);
    }

    /** Call the API and return the parsed reply; throws when BigBlueButton says FAILED. */
    public function call(string $call, array $params = [], ?string $body = null): SimpleXMLElement
    {
        $url = $this->url($call, $params);
        try {
            $res = $body !== null
                ? $this->http()->withBody($body, 'application/xml')->post($url)
                : $this->http()->get($url);
        } catch (\Throwable $e) {
            throw new BbbException('The meeting server could not be reached. Try again in a moment.');
        }
        if (! $res->successful()) {
            throw new BbbException('The meeting server answered with an error ('.$res->status().').');
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string) $res->body(), SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($previous);
        if (! $xml) {
            throw new BbbException('The meeting server sent a reply that could not be read.');
        }
        if ((string) $xml->returncode !== 'SUCCESS') {
            throw new BbbException(trim((string) $xml->message) ?: 'The meeting server refused the request.', (string) $xml->messageKey ?: null);
        }

        return $xml;
    }

    /** Create the room (BigBlueButton treats a repeat create for a running meeting as success). Optional presentation XML body. */
    public function create(array $params, ?string $presentationXml = null): SimpleXMLElement
    {
        return $this->call('create', $params, $presentationXml);
    }

    public function joinUrl(array $params): string
    {
        return $this->url('join', $params);
    }

    public function end(string $meetingId): void
    {
        try {
            $this->call('end', ['meetingID' => $meetingId]);
        } catch (BbbException $e) {
            if ($e->messageKey !== 'notFound') { // already over
                throw $e;
            }
        }
    }

    public function isRunning(string $meetingId): bool
    {
        return strtolower((string) $this->call('isMeetingRunning', ['meetingID' => $meetingId])->running) === 'true';
    }

    public function meetingInfo(string $meetingId): ?SimpleXMLElement
    {
        try {
            return $this->call('getMeetingInfo', ['meetingID' => $meetingId]);
        } catch (BbbException $e) {
            if ($e->messageKey === 'notFound') {
                return null;
            }
            throw $e;
        }
    }

    /** @return list<SimpleXMLElement> */
    public function recordings(array $meetingIds): array
    {
        $xml = $this->call('getRecordings', ['meetingID' => implode(',', $meetingIds), 'state' => 'any']);
        $out = [];
        foreach ($xml->recordings->recording ?? [] as $r) {
            $out[] = $r;
        }

        return $out;
    }

    public function publish(string $recordId, bool $publish): void
    {
        $this->call('publishRecordings', ['recordID' => $recordId, 'publish' => $publish ? 'true' : 'false']);
    }

    public function deleteRecording(string $recordId): void
    {
        $this->call('deleteRecordings', ['recordID' => $recordId]);
    }

    /** Health: the API answers and how busy the server is. */
    public function health(): array
    {
        try {
            $root = Http::timeout(10)->connectTimeout(8)->get($this->base());
        } catch (\Throwable) {
            throw new BbbException('The meeting server could not be reached.');
        }
        $version = null;
        if ($root->successful() && ($x = @simplexml_load_string((string) $root->body()))) {
            $version = (string) ($x->version ?? '') ?: null;
        }
        $xml = $this->call('getMeetings');
        $meetings = 0;
        $people = 0;
        foreach ($xml->meetings->meeting ?? [] as $m) {
            $meetings++;
            $people += (int) $m->participantCount;
        }

        return ['version' => $version, 'meetings' => $meetings, 'participants' => $people];
    }

    /** Register our webhook on the server (needs the bbb-webhooks package there). */
    public function registerHook(string $callbackUrl): void
    {
        $this->call('hooks/create', ['callbackURL' => $callbackUrl, 'getRaw' => 'false']);
    }
}
