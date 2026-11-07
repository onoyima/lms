<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Exam\ExamApiController;
use App\Http\Controllers\Exam\VenueController;
use App\Http\Middleware\AuthenticateAttempt;
use Illuminate\Support\Facades\Route;

/*
| Candidate exam API — stateless, token per attempt (no cookies, no sessions), used by the
| web exam room, the desktop exam app and Safe Exam Browser. Prefix: /api
*/
Route::prefix('exam/attempt')->middleware([AuthenticateAttempt::class, 'throttle:exam-api'])->group(function () {
    Route::get('paper', [ExamApiController::class, 'paper']);
    Route::post('answers', [ExamApiController::class, 'answers']);
    Route::post('heartbeat', [ExamApiController::class, 'heartbeat']);
    Route::post('submit', [ExamApiController::class, 'submit']);
    Route::post('evidence', [ExamApiController::class, 'evidence'])->middleware('throttle:exam-evidence');
    Route::post('messages/{message}/ack', [ExamApiController::class, 'acknowledge']);
    Route::post('help', [ExamApiController::class, 'help']);
    Route::post('identity', [ExamApiController::class, 'identity'])->middleware('throttle:exam-identity');
    Route::post('media-token', [\App\Http\Controllers\Media\MediaController::class, 'candidate'])->middleware('throttle:exam-identity');
    Route::post('phone-link', [\App\Http\Controllers\Media\MediaController::class, 'phoneLink'])->middleware('throttle:exam-identity');
});

// Desktop app registers its signing key; an ICT officer approves the computer.
Route::post('devices/enrol', [VenueController::class, 'enrol'])->middleware(['throttle:10,1,api26', 'module:secure_client']);

/*
| Integration API (v1) — for the student information system and other university systems.
| Keys: php artisan apikey:create. Each key has scopes and an optional IP allow-list.
*/
Route::prefix('v1')->middleware('throttle:integration')->group(function () {
    Route::post('sis/students', [IntegrationController::class, 'students'])->middleware('apikey:sis.write');
    Route::post('sis/courses', [IntegrationController::class, 'courses'])->middleware('apikey:sis.write');
    Route::post('sis/registrations', [IntegrationController::class, 'registrations'])->middleware('apikey:sis.write');
    Route::get('sis/runs', [IntegrationController::class, 'runs'])->middleware('apikey:sis.write');
    Route::get('results', [IntegrationController::class, 'results'])->middleware('apikey:results.read');
    Route::post('attendance', [\App\Http\Controllers\Api\ConnectorController::class, 'attendance'])->middleware('apikey:attendance.write');
    Route::get('analytics', [\App\Http\Controllers\Api\ConnectorController::class, 'analytics'])->middleware('apikey:analytics.read');
});

// Plagiarism service replies (signed with the institution's shared secret)
Route::post('v1/plagiarism/callback/{reference}', [\App\Http\Controllers\Api\ConnectorController::class, 'plagiarismCallback'])
    ->where('reference', '[A-Za-z0-9]{40}')->middleware('throttle:120,1,plagcb')->name('api.plagiarism.callback');

// Monitoring probe: database, cache, queue, disk, backups, scheduler (Target 101, 184-185; LMS 140, 143, 245)
Route::get('health', HealthController::class)->middleware('throttle:60,1,api41');

// Media server (LiveKit) webhooks — authenticated by a signature over the body
Route::post('livekit/webhook', [\App\Http\Controllers\Media\MediaController::class, 'webhook'])->middleware('throttle:600,1,lkwh');

// BigBlueButton servers calling back: webhooks (checksum or bearer secret) and signed callbacks
Route::post('conferencing/webhooks/{server}', [\App\Http\Controllers\Conferencing\CallbackController::class, 'webhook'])->whereNumber('server')->middleware('throttle:1200,1,bbbhook')->name('conferencing.webhook');
Route::match(['get', 'post'], 'conferencing/ended/{meeting}', [\App\Http\Controllers\Conferencing\CallbackController::class, 'ended'])->middleware(['signed', 'throttle:120,1,bbbend'])->name('conferencing.ended');
Route::match(['get', 'post'], 'conferencing/recording-ready/{meeting}', [\App\Http\Controllers\Conferencing\CallbackController::class, 'recordingReady'])->middleware(['signed', 'throttle:120,1,bbbrec'])->name('conferencing.recording-ready');

