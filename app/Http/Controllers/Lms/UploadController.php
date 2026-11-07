<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use App\Models\CourseItem;
use App\Models\UploadSession;
use App\Services\Lms\AssignmentService;
use App\Services\Lms\LmsAccess;
use App\Services\Lms\Uploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Resumable uploads for large assignment files. */
class UploadController extends Controller
{
    public function __construct(private Uploads $uploads, private LmsAccess $access) {}

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['purpose' => ['required', 'regex:/^(assignment|recording):\d+$/'], 'filename' => ['required', 'string', 'max:255'], 'size' => ['required', 'integer', 'min:1'], 'mime' => ['nullable', 'string', 'max:120']]);
        [$kind, $id] = explode(':', $data['purpose']);
        if ($kind === 'recording') {
            // a lecture recording, uploaded by someone who teaches the course (LMS 30)
            $meeting = \App\Models\ClassMeeting::findOrFail((int) $id);
            $this->access->authorizeTeach($request->user(), \App\Models\CourseOffering::findOrFail($meeting->course_offering_id));
            abort_unless(in_array(strtolower(pathinfo($data['filename'], PATHINFO_EXTENSION)), ['mp4', 'webm', 'm4a', 'mp3'], true), 422, 'Upload an MP4 or WebM video, or an M4A or MP3 audio file.');
            abort_if($data['size'] > 4 * 1024 * 1024 * 1024, 422, 'Recordings must be under 4 GB.');
            $session = $this->uploads->start($request->user(), $data['purpose'], $data['filename'], $data['size'], $data['mime'] ?? null);

            return response()->json(['id' => $session->id, 'chunk' => 8 * 1024 * 1024, 'received' => 0], 201);
        }
        $item = CourseItem::findOrFail((int) $id);
        $this->access->authorizeOpen($request->user(), $item);
        abort_unless($item->type === 'assignment' && $this->access->learns($request->user(), $item->offering), 403);
        $s = AssignmentService::settings($item);
        $ext = strtolower(pathinfo($data['filename'], PATHINFO_EXTENSION));
        abort_unless($s['file'] && in_array($ext, $s['file_types'], true), 422, 'This file type is not accepted ('.implode(', ', $s['file_types']).').');
        abort_if($data['size'] > $s['max_mb'] * 1024 * 1024, 422, "Files must be under {$s['max_mb']} MB.");
        $session = $this->uploads->start($request->user(), $data['purpose'], $data['filename'], $data['size'], $data['mime'] ?? null);

        return response()->json(['id' => $session->id, 'chunk' => 4 * 1024 * 1024, 'received' => 0], 201);
    }

    public function chunk(Request $request, string $id): JsonResponse
    {
        $s = UploadSession::findOrFail($id);
        try {
            $received = $this->uploads->append($s, $request->user(), $request->integer('offset'), $request->getContent());
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            if ($e->getStatusCode() === 409) {
                return response()->json(['received' => (int) $e->getMessage(), 'message' => 'Continue from the server position.'], 409);
            }
            throw $e;
        }

        return response()->json(['received' => $received, 'complete' => $received === $s->size]);
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $s = UploadSession::where('id', $id)->where('user_id', $request->user()->id)->firstOrFail();

        return response()->json(['received' => $s->received, 'size' => $s->size, 'status' => $s->status]);
    }
}
