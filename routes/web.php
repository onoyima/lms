<?php

use App\Http\Controllers\Lms;
use App\Http\Controllers\Admin\BrandingController;
use App\Http\Controllers\Admin\ModuleController;
use App\Http\Controllers\Candidate\CandidateExamController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Exam\ExamAdminController;
use App\Http\Controllers\Exam\SessionController;
use App\Http\Controllers\Exam\VenueController;
use App\Http\Controllers\Marking\MarkingController;
use App\Http\Controllers\Proctor\IncidentController;
use App\Http\Controllers\Proctor\ProctorController;
use App\Http\Controllers\Results\ResultController;
use App\Http\Controllers\QuestionBank\ImportController;
use App\Http\Controllers\QuestionBank\QuestionController;
use App\Http\Controllers\WellKnownController;
use Illuminate\Support\Facades\Route;

Route::get('/', [\App\Http\Controllers\LandingController::class, 'show'])->name('home');
Route::post('apply', [\App\Http\Controllers\LandingController::class, 'apply'])->middleware('throttle:5,10,apply')->name('apply.store');

// Paystack payment notifications: exempt from CSRF and checked by HMAC signature instead
// Meeting-screen colours for BigBlueButton (public, colours only)
Route::get('conferencing/theme/{tenant}.css', [\App\Http\Controllers\Conferencing\CallbackController::class, 'theme'])->whereNumber('tenant')->middleware('throttle:120,1,bbbcss')->name('conferencing.theme');
// Guest links: people without an account join one meeting with the name they type (no sign-in)
Route::middleware(['module:conferencing', 'throttle:60,1,guestmtg'])->group(function () {
    $gc = \App\Http\Controllers\Conferencing\GuestController::class;
    $rc = \App\Http\Controllers\Conferencing\RoomController::class;
    Route::get('join/{token}', [$gc, 'show'])->where('token', '[A-Za-z0-9]{32}')->name('meetings.guest');
    Route::post('join/{token}', [$gc, 'join'])->where('token', '[A-Za-z0-9]{32}')->middleware('throttle:10,1,guestjoin')->name('meetings.guest.join');
    Route::get('join/{token}/status', [$gc, 'status'])->where('token', '[A-Za-z0-9]{32}')->name('meetings.guest.status');
    Route::get('join/{token}/room', [$rc, 'show'])->where('token', '[A-Za-z0-9]{32}')->name('meetings.guest.room');
});
Route::middleware(['module:conferencing'])->group(function () {
    $rc = \App\Http\Controllers\Conferencing\RoomController::class;
    Route::post('join/{token}/room/poll', [$rc, 'poll'])->where('token', '[A-Za-z0-9]{32}')->middleware('throttle:120,1,groompoll');
    Route::post('join/{token}/room/signal', [$rc, 'signal'])->where('token', '[A-Za-z0-9]{32}')->middleware('throttle:600,1,groomsig');
    Route::post('join/{token}/room/leave', [$rc, 'leave'])->where('token', '[A-Za-z0-9]{32}');
});
// Preloaded slides, fetched by the BigBlueButton server through a short-lived signed link
Route::get('conferencing/slides/{meeting}/{index}', [\App\Http\Controllers\Conferencing\MeetingController::class, 'slide'])->whereNumber('index')->middleware(['signed', 'throttle:60,1,bbbslide'])->name('conferencing.slide');

// Platform support sign-in (single-use link from the platform console; see SupportSessionController)
Route::get('support/enter/{token}', [\App\Http\Controllers\SupportSessionController::class, 'enter'])->where('token', '[A-Za-z0-9]{48}')->middleware(['auth', 'throttle:10,1,supenter'])->name('support.enter');
Route::post('announcements/{id}/dismiss', [\App\Http\Controllers\NotificationController::class, 'dismissAnnouncement'])->whereNumber('id')->middleware(['auth', 'throttle:30,1,anndis'])->name('announcements.dismiss');
Route::post('support/leave', [\App\Http\Controllers\SupportSessionController::class, 'leave'])->middleware('auth')->name('support.leave');
Route::post('webhooks/mail', [\App\Http\Controllers\Admin\MailLogController::class, 'webhook'])->middleware('throttle:300,1,mailwh')->name('webhooks.mail');
Route::post('webhooks/paystack', [\App\Http\Controllers\Admin\BillingController::class, 'webhook'])->middleware('throttle:120,1,pswh')->name('webhooks.paystack');

Route::get('.well-known/exam-platform.json', WellKnownController::class)->name('well-known');

// Public verification of issued documents (no sign-in; rate-limited)
Route::get('verify/{code}', [\App\Http\Controllers\DocumentController::class, 'verify'])->where('code', '[A-Za-z0-9]{16}')->middleware('throttle:30,1,web25')->name('verify');

// Single sign-on (SAML 2.0 / OpenID Connect) — the reply endpoints are exempt from CSRF and checked by signature instead
Route::prefix('sso/{slug}')->where(['slug' => '[A-Za-z0-9_-]+'])->middleware(['throttle:sso', 'module:enterprise_hosting'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Auth\SsoController::class, 'redirect'])->middleware('guest')->name('sso.redirect');
    Route::get('callback', [\App\Http\Controllers\Auth\SsoController::class, 'callback'])->middleware('guest')->name('sso.callback');
    Route::post('acs', [\App\Http\Controllers\Auth\SsoController::class, 'acs'])->middleware('guest')->name('sso.acs');
    Route::get('metadata', [\App\Http\Controllers\Auth\SsoController::class, 'metadata'])->name('sso.metadata');
});

// Private calendar feed for phone/desktop calendar apps (secret link, no sign-in)
Route::get('calendar/{token}.ics', [\App\Http\Controllers\Lms\CalendarController::class, 'feed'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:30,1,ics')->name('calendar.feed');

// A candidate's phone as a second camera: reached by a one-time link from the exam screen (no sign-in on the phone)
Route::get('phone/{code}', [\App\Http\Controllers\Media\MediaController::class, 'phonePage'])->where('code', '[A-Za-z0-9]{40}')->middleware('throttle:30,1,phone')->name('phone.show');
Route::post('phone/{code}/token', [\App\Http\Controllers\Media\MediaController::class, 'phoneToken'])->where('code', '[A-Za-z0-9]{40}')->middleware('throttle:10,1,phonetok')->name('phone.token');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::middleware('permission:audit.view')->group(function () {
        Route::get('admin/audit', [\App\Http\Controllers\AuditController::class, 'index'])->name('audit.index');
        Route::get('admin/audit/export', [\App\Http\Controllers\AuditController::class, 'download'])->middleware('throttle:10,1,audexp')->name('audit.export');
    });
    // Live classes and meetings (module: conferencing)
    Route::middleware('module:conferencing')->group(function () {
        $mc = \App\Http\Controllers\Conferencing\MeetingController::class;
        Route::get('meetings', [$mc, 'index'])->name('meetings.index');
        Route::get('meetings/create', [$mc, 'create'])->name('meetings.create');
        Route::post('meetings', [$mc, 'store'])->middleware('throttle:30,1,mtgnew')->name('meetings.store');
        Route::get('meetings/{meeting}', [$mc, 'show'])->name('meetings.show');
        Route::get('meetings/{meeting}/edit', [$mc, 'edit'])->name('meetings.edit');
        Route::put('meetings/{meeting}', [$mc, 'update'])->name('meetings.update');
        Route::get('meetings/{meeting}/status', [$mc, 'status'])->middleware('throttle:60,1,mtgstat')->name('meetings.status');
        Route::post('meetings/{meeting}/join', [$mc, 'join'])->middleware('throttle:20,1,mtgjoin')->name('meetings.join');
        Route::post('meetings/{meeting}/end', [$mc, 'end'])->name('meetings.end');
        Route::post('meetings/{meeting}/cancel', [$mc, 'cancel'])->name('meetings.cancel');
        Route::post('meetings/{meeting}/participants', [$mc, 'addParticipants'])->middleware('throttle:30,1,mtgppl')->name('meetings.participants');
        Route::put('meetings/{meeting}/participants/{user}', [$mc, 'setRole'])->whereNumber('user')->name('meetings.participants.role');
        Route::get('meetings/{meeting}/attendance.csv', [$mc, 'attendanceCsv'])->middleware('throttle:20,1,mtgcsv')->name('meetings.attendance');
        Route::post('meetings/{meeting}/recordings/sync', [$mc, 'syncRecordings'])->middleware('throttle:10,1,mtgrsync')->name('meetings.recordings.sync');
        Route::get('meetings/{meeting}/recordings/{recording}', [$mc, 'play'])->whereNumber('recording')->middleware('throttle:60,1,mtgplay')->name('meetings.recordings.play');
        Route::post('meetings/{meeting}/recordings/{recording}', [$mc, 'updateRecording'])->whereNumber('recording')->name('meetings.recordings.update');
        Route::post('meetings/{meeting}/slides', [$mc, 'uploadSlide'])->middleware('throttle:20,1,mtgslide')->name('meetings.slides');
        Route::delete('meetings/{meeting}/slides/{index}', [$mc, 'deleteSlide'])->whereNumber('index')->name('meetings.slides.delete');
        Route::post('meetings/{meeting}/recordings/{recording}/transcribe', [$mc, 'transcribe'])->whereNumber('recording')->middleware('throttle:10,1,mtgtrx')->name('meetings.transcribe');
        Route::post('meetings/{meeting}/transcripts/{transcript}/translate', [$mc, 'translate'])->whereNumber('transcript')->middleware('throttle:10,1,mtgtrl')->name('meetings.translate');
        Route::get('meetings/{meeting}/transcripts/{transcript}.{format}', [$mc, 'transcriptFile'])->whereNumber('transcript')->whereIn('format', ['vtt', 'srt', 'txt'])->name('meetings.transcript');
        $rc = \App\Http\Controllers\Conferencing\RoomController::class;
        Route::get('meetings/{meeting}/room', [$rc, 'show'])->name('meetings.room');
        Route::post('meetings/{meeting}/room/poll', [$rc, 'poll'])->middleware('throttle:120,1,roompoll')->name('meetings.room.poll');
        Route::post('meetings/{meeting}/room/signal', [$rc, 'signal'])->middleware('throttle:600,1,roomsig')->name('meetings.room.signal');
        Route::post('meetings/{meeting}/room/admit', [$rc, 'admit'])->name('meetings.room.admit');
        Route::post('meetings/{meeting}/room/remove', [$rc, 'remove'])->name('meetings.room.remove');
        Route::post('meetings/{meeting}/room/leave', [$rc, 'leave'])->name('meetings.room.leave');
        Route::post('meetings/{meeting}/guest-link', [\App\Http\Controllers\Conferencing\GuestController::class, 'save'])->middleware('throttle:20,1,mtgguest')->name('meetings.guest-link');
        Route::get('meeting-reports', [\App\Http\Controllers\Conferencing\MeetingReportController::class, 'index'])->name('meetings.reports');
        Route::get('meeting-reports/export', [\App\Http\Controllers\Conferencing\MeetingReportController::class, 'export'])->middleware('throttle:20,1,mtgrep')->name('meetings.reports.export');
        Route::get('conferencing/playback/{recording}', [$mc, 'playback'])->middleware('signed')->name('conferencing.playback');
        Route::middleware('permission:conferencing.manage')->group(function () {
            $sc = \App\Http\Controllers\Conferencing\ServerController::class;
            Route::get('admin/conferencing', [$sc, 'index'])->name('admin.conferencing');
            Route::post('admin/conferencing/servers', [$sc, 'store']);
            Route::put('admin/conferencing/servers/{server}', [$sc, 'update'])->whereNumber('server');
            Route::delete('admin/conferencing/servers/{server}', [$sc, 'destroy'])->whereNumber('server');
            Route::post('admin/conferencing/servers/{server}/check', [$sc, 'check'])->whereNumber('server')->middleware('throttle:20,1,bbbchk');
        });
    });
    Route::prefix('admin/maintenance')->middleware('permission:institution.manage')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\MaintenanceController::class, 'index'])->name('maintenance.index');
        Route::post('/', [\App\Http\Controllers\Admin\MaintenanceController::class, 'store'])->name('maintenance.store');
        Route::delete('{id}', [\App\Http\Controllers\Admin\MaintenanceController::class, 'destroy'])->whereNumber('id')->name('maintenance.destroy');
    });
    Route::prefix('admin/sso')->middleware(['permission:institution.manage', 'module:enterprise_hosting'])->group(function () {
        Route::get('/', [\App\Http\Controllers\Auth\SsoController::class, 'index'])->name('sso.index');
        Route::post('/', [\App\Http\Controllers\Auth\SsoController::class, 'store'])->name('sso.store');
        Route::put('{provider}', [\App\Http\Controllers\Auth\SsoController::class, 'update'])->name('sso.update');
        Route::delete('{provider}', [\App\Http\Controllers\Auth\SsoController::class, 'destroy'])->name('sso.destroy');
    });
    Route::prefix('my/identity')->middleware('throttle:identity')->group(function () {
        Route::get('/', [\App\Http\Controllers\IdentityController::class, 'mine'])->name('identity.mine');
        Route::post('/', [\App\Http\Controllers\IdentityController::class, 'enrol'])->middleware('throttle:face-enrol')->name('identity.enrol');
        Route::get('photo', [\App\Http\Controllers\IdentityController::class, 'photo'])->name('identity.photo');
        Route::delete('/', [\App\Http\Controllers\IdentityController::class, 'eraseMine'])->name('identity.erase-mine');
    });
    Route::prefix('admin/identity')->middleware('permission:users.manage')->group(function () {
        Route::get('/', [\App\Http\Controllers\IdentityController::class, 'index'])->name('identity.index');
        Route::post('id-photo', [\App\Http\Controllers\IdentityController::class, 'idPhoto'])->middleware('throttle:600,1,web50')->name('identity.id-photo');
        Route::get('{profile}/photo/{which}', [\App\Http\Controllers\IdentityController::class, 'staffPhoto'])->name('identity.staff-photo');
        Route::post('{profile}', [\App\Http\Controllers\IdentityController::class, 'decide'])->name('identity.decide');
        Route::delete('{profile}', [\App\Http\Controllers\IdentityController::class, 'erase'])->name('identity.erase');
    });
    Route::prefix('my/documents')->middleware('throttle:20,1,web55')->group(function () {
        Route::get('result-statement', [\App\Http\Controllers\DocumentController::class, 'resultStatement'])->name('documents.statement');
        Route::get('transcript', [\App\Http\Controllers\DocumentController::class, 'transcript'])->name('documents.transcript');
        Route::get('registration-slip', [\App\Http\Controllers\DocumentController::class, 'registrationSlip'])->name('documents.slip');
        Route::get('exam-slip/{candidate}', [\App\Http\Controllers\DocumentController::class, 'examSlip'])->name('documents.exam-slip');
    });
    Route::middleware('permission:academics.manage')->group(function () {
        Route::get('admin/students/{student}/result-statement', [\App\Http\Controllers\DocumentController::class, 'resultStatement']);
        Route::get('admin/students/{student}/transcript', [\App\Http\Controllers\DocumentController::class, 'transcript']);
    });

    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('docs/{slug?}', [\App\Http\Controllers\DocsController::class, 'institution'])->where('slug', '[a-z0-9-]+')->name('docs');
    Route::get('templates', [\App\Http\Controllers\TemplateController::class, 'index'])->name('templates');
    Route::get('templates/{key}', [\App\Http\Controllers\TemplateController::class, 'download'])->where('key', '[a-z_]+')->middleware('throttle:60,1,tpl')->name('templates.download');
    Route::post('learn/submissions/{submission}/plagiarism', [\App\Http\Controllers\Admin\IntegrationController::class, 'checkSubmission'])->whereNumber('submission')->middleware('throttle:30,1,plagck');
    Route::put('dashboard/layout', [DashboardController::class, 'saveLayout'])->middleware('throttle:30,1,dashlay')->name('dashboard.layout');

    // Formal exception requests (LMS 178) and the hardware exception register (Target 141)
    $ex = \App\Http\Controllers\Admin\ExceptionController::class;
    Route::get('my/exceptions', [$ex, 'mine'])->name('exceptions.mine');
    Route::post('my/exceptions', [$ex, 'store'])->middleware('throttle:10,10,exreq')->name('exceptions.store');
    Route::post('my/exceptions/{exception}/withdraw', [$ex, 'withdraw'])->whereNumber('exception');
    Route::get('exceptions/{exception}/evidence', [$ex, 'evidence'])->whereNumber('exception')->middleware('throttle:30,1,exev')->name('exceptions.evidence');
    Route::get('admin/exceptions', [$ex, 'index'])->name('exceptions.index');
    Route::post('admin/exceptions/{exception}/decide', [$ex, 'decide'])->whereNumber('exception');
    Route::post('admin/hardware-exceptions', [$ex, 'storeHardware'])->middleware('throttle:60,1,hwex');
    Route::post('admin/hardware-exceptions/{hardware}', [$ex, 'decideHardware'])->whereNumber('hardware');

    // Institution settings
    Route::middleware('permission:users.manage')->prefix('admin/people')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\PeopleController::class, 'index'])->name('people');
        Route::post('/', [\App\Http\Controllers\Admin\PeopleController::class, 'store'])->middleware('throttle:30,1,web71');
        Route::post('import', [\App\Http\Controllers\Admin\PeopleController::class, 'import'])->middleware('throttle:5,1,web72');
        Route::put('{user}', [\App\Http\Controllers\Admin\PeopleController::class, 'update']);
        Route::post('{user}/delegate', [\App\Http\Controllers\Admin\PeopleController::class, 'delegate']);
        Route::put('{user}/placement', [\App\Http\Controllers\Admin\PeopleController::class, 'placement']);
        Route::delete('grants/{grant}', [\App\Http\Controllers\Admin\PeopleController::class, 'revoke']);
    });

    Route::middleware('permission:roles.manage')->prefix('admin/roles')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\RoleController::class, 'index'])->name('roles');
        Route::post('/', [\App\Http\Controllers\Admin\RoleController::class, 'store'])->middleware('throttle:30,1,rolec');
        Route::put('{role}', [\App\Http\Controllers\Admin\RoleController::class, 'update'])->whereNumber('role');
        Route::post('{role}/reset', [\App\Http\Controllers\Admin\RoleController::class, 'reset'])->whereNumber('role');
        Route::delete('{role}', [\App\Http\Controllers\Admin\RoleController::class, 'destroy'])->whereNumber('role');
    });

    Route::middleware('permission:academics.manage')->prefix('admin/academics')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\AcademicController::class, 'index'])->name('academics');
        Route::post('units/{kind}', [\App\Http\Controllers\Admin\AcademicController::class, 'saveUnit'])->whereIn('kind', ['faculty', 'department', 'programme']);
        Route::delete('units/{kind}/{id}', [\App\Http\Controllers\Admin\AcademicController::class, 'deleteUnit'])->whereIn('kind', ['faculty', 'department', 'programme', 'course']);
        Route::post('courses', [\App\Http\Controllers\Admin\AcademicController::class, 'saveCourse']);
        Route::post('plan', [\App\Http\Controllers\Admin\AcademicController::class, 'savePlan']);
        Route::post('sessions', [\App\Http\Controllers\Admin\AcademicController::class, 'saveSession']);
        Route::post('semesters/{semester}', [\App\Http\Controllers\Admin\AcademicController::class, 'semesterAction']);
        Route::post('offerings', [\App\Http\Controllers\Admin\AcademicController::class, 'saveOffering']);
        Route::post('offerings/{offering}/streams', [\App\Http\Controllers\Admin\AcademicController::class, 'streams']);
        Route::post('offerings/bulk', [\App\Http\Controllers\Admin\AcademicController::class, 'bulkOfferings'])->middleware('throttle:30,1,offbulk');
        Route::post('courses/import', [\App\Http\Controllers\Admin\AcademicController::class, 'importCourses'])->middleware('throttle:10,1,crsimp');
    });

    Route::middleware('permission:institution.manage')->group(function () {
        Route::get('admin/integrations', [\App\Http\Controllers\Admin\IntegrationController::class, 'index'])->name('integrations')->middleware('permission:integrations.manage');
        Route::post('admin/integrations/keys', [\App\Http\Controllers\Admin\IntegrationController::class, 'createKey'])->middleware(['permission:integrations.manage', 'throttle:10,1,apikeyc']);
        Route::delete('admin/integrations/keys/{id}', [\App\Http\Controllers\Admin\IntegrationController::class, 'revokeKey'])->whereNumber('id')->middleware('permission:integrations.manage');
        Route::put('admin/integrations/directory', [\App\Http\Controllers\Admin\IntegrationController::class, 'saveDirectory'])->middleware('permission:integrations.manage');
        Route::post('admin/integrations/directory/test', [\App\Http\Controllers\Admin\IntegrationController::class, 'testDirectory'])->middleware(['permission:integrations.manage', 'throttle:10,1,dirtest']);
        Route::post('admin/integrations/directory/sync', [\App\Http\Controllers\Admin\IntegrationController::class, 'syncDirectory'])->middleware(['permission:integrations.manage', 'throttle:6,1,dirsync']);
        Route::put('admin/integrations/{name}', [\App\Http\Controllers\Admin\IntegrationController::class, 'saveConnector'])->whereIn('name', ['plagiarism', 'analytics', 'mail', 'notify'])->middleware('permission:integrations.manage');
        Route::post('admin/integrations/mail/test', [\App\Http\Controllers\Admin\IntegrationController::class, 'testMail'])->middleware(['permission:integrations.manage', 'throttle:5,1,mailtest']);
        Route::post('admin/integrations/analytics/push', [\App\Http\Controllers\Admin\IntegrationController::class, 'pushAnalytics'])->middleware(['permission:integrations.manage', 'throttle:5,1,anpush']);
        Route::get('admin/system-health', [\App\Http\Controllers\Monitoring\SystemHealthController::class, 'institution'])->name('system-health');
        Route::post('admin/system-health/devices', [\App\Http\Controllers\Monitoring\SystemHealthController::class, 'storeDevice'])->middleware('throttle:30,1,netdev');
        Route::delete('admin/system-health/devices/{id}', [\App\Http\Controllers\Monitoring\SystemHealthController::class, 'deleteDevice'])->whereNumber('id');
        Route::post('admin/system-health/probe', [\App\Http\Controllers\Monitoring\SystemHealthController::class, 'probeNow'])->middleware('throttle:6,1,netprobe');
        Route::get('admin/mail-log', [\App\Http\Controllers\Admin\MailLogController::class, 'index'])->name('mail-log');
        Route::get('admin/policy', [\App\Http\Controllers\Admin\PolicyController::class, 'show'])->name('policy');
        Route::put('admin/policy', [\App\Http\Controllers\Admin\PolicyController::class, 'update']);
        Route::put('admin/policy/campus/{campus}', [\App\Http\Controllers\Admin\PolicyController::class, 'updateCampus'])->where('campus', '[a-z0-9_]{1,40}');
        Route::post('admin/policy/versions/{version}/restore', [\App\Http\Controllers\Admin\PolicyController::class, 'restore'])->whereNumber('version');
    });

    Route::prefix('admin/reports')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\ReportController::class, 'index'])->name('reports');
        Route::get('{report}/export/{format}', [\App\Http\Controllers\Admin\ReportController::class, 'export'])->middleware('throttle:20,1,web98');
        Route::post('{report}/request/{format}', [\App\Http\Controllers\Admin\ReportController::class, 'requestExport'])->middleware('throttle:10,1,web99');
        Route::post('requests/{id}', [\App\Http\Controllers\Admin\ReportController::class, 'decideExport']);
    });
    Route::get('admin/access-review', [\App\Http\Controllers\Admin\ReportController::class, 'accessReview'])->name('access-review');
    Route::post('admin/access-review', [\App\Http\Controllers\Admin\ReportController::class, 'completeReview']);

    Route::middleware('permission:institution.manage')->group(function () {
        Route::get('admin/branding', [BrandingController::class, 'edit'])->name('admin.branding.edit');
        Route::post('admin/branding', [BrandingController::class, 'update'])->name('admin.branding.update');
    });

    // Institution billing: the institution's own subscription, invoices and payments
    Route::prefix('institution/billing')->middleware('permission:billing.manage')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\BillingController::class, 'index'])->name('billing.index');
        Route::post('invoices/{invoice}/pay', [\App\Http\Controllers\Admin\BillingController::class, 'pay'])->whereNumber('invoice')->middleware('throttle:10,1,billpay')->name('billing.pay');
        Route::get('callback', [\App\Http\Controllers\Admin\BillingController::class, 'callback'])->middleware('throttle:20,1,billcb')->name('billing.callback');
        Route::get('invoices/{invoice}/pdf', [\App\Http\Controllers\Admin\BillingController::class, 'document'])->whereNumber('invoice')->middleware('throttle:30,1,billpdf')->name('billing.document');
        Route::post('invoices/{invoice}/coupon', [\App\Http\Controllers\Admin\BillingController::class, 'coupon'])->whereNumber('invoice')->middleware('throttle:10,1,billcoupon')->name('billing.coupon');
        Route::post('change', [\App\Http\Controllers\Admin\BillingController::class, 'change'])->middleware('throttle:10,1,billchg')->name('billing.change');
        Route::post('addons', [\App\Http\Controllers\Admin\BillingController::class, 'addon'])->middleware('throttle:10,1,billadd')->name('billing.addon');
        Route::post('cancel', [\App\Http\Controllers\Admin\BillingController::class, 'cancel'])->middleware('throttle:10,1,billcan')->name('billing.cancel');
    });

    // Platform console (SaaS operator only). Runs with no institution in context: institution
    // records cannot load here, and platform administrators cannot open institution pages.
    Route::prefix('platform')->middleware('platform.admin')->name('platform.')->group(function () {
        $pc = \App\Http\Controllers\Platform\PlatformController::class;
        Route::get('/', [$pc, 'overview'])->name('overview');
        Route::get('institutions', [$pc, 'institutions'])->name('institutions');
        Route::post('institutions', [$pc, 'storeInstitution'])->middleware('throttle:20,1,plinst')->name('institutions.store');
        Route::get('institutions/{tenant}', [$pc, 'institution'])->whereNumber('tenant')->name('institution');
        Route::put('institutions/{tenant}', [$pc, 'updateInstitution'])->whereNumber('tenant')->name('institution.update');
        Route::post('institutions/{tenant}/subscription', [$pc, 'changeSubscription'])->whereNumber('tenant')->name('institution.subscription');
        Route::post('institutions/{tenant}/invoice', [$pc, 'invoice'])->whereNumber('tenant')->middleware('throttle:20,1,plinv')->name('institution.invoice');
        Route::post('institutions/{tenant}/support', [\App\Http\Controllers\SupportSessionController::class, 'start'])->whereNumber('tenant')->middleware('throttle:10,1,plsupport')->name('institution.support');
        Route::post('institutions/{tenant}/limits', [$pc, 'saveLimits'])->whereNumber('tenant')->name('institution.limits');
        Route::post('institutions/{tenant}/extend', [$pc, 'extend'])->whereNumber('tenant')->name('institution.extend');
        Route::post('institutions/{tenant}/cancel', [$pc, 'cancelSubscription'])->whereNumber('tenant')->name('institution.cancel');
        Route::post('institutions/{tenant}/grants', [$pc, 'grantModule'])->whereNumber('tenant')->name('institution.grant');
        Route::delete('institutions/{tenant}/grants/{module}', [$pc, 'revokeGrant'])->whereNumber('tenant')->where('module', '[a-z0-9_]+')->name('institution.grant.revoke');
        Route::post('institutions/{tenant}/addons', [$pc, 'addAddon'])->whereNumber('tenant')->name('institution.addons');
        Route::delete('institutions/{tenant}/addons/{addon}', [$pc, 'endAddon'])->whereNumber('tenant')->whereNumber('addon')->name('institution.addons.end');
        Route::get('invoices/{invoice}/pdf', [$pc, 'document'])->whereNumber('invoice')->name('invoice.pdf');
        Route::get('settings', [$pc, 'settings'])->name('settings');
        Route::put('settings', [$pc, 'saveSettings'])->name('settings.update');
        Route::get('announcements', [$pc, 'announcements'])->name('announcements');
        Route::post('announcements', [$pc, 'saveAnnouncement'])->name('announcements.store');
        Route::put('announcements/{announcement}', [$pc, 'saveAnnouncement'])->whereNumber('announcement')->name('announcements.update');
        Route::delete('announcements/{announcement}', [$pc, 'deleteAnnouncement'])->whereNumber('announcement')->name('announcements.destroy');
        Route::put('maintenance', [$pc, 'saveMaintenance'])->name('maintenance');
        Route::get('audit', [\App\Http\Controllers\AuditController::class, 'platform'])->name('audit');
        Route::get('audit/export', [\App\Http\Controllers\AuditController::class, 'platformDownload'])->middleware('throttle:10,1,plaudexp')->name('audit.export');
        $sc = \App\Http\Controllers\Conferencing\ServerController::class;
        Route::get('conferencing', [$sc, 'index'])->name('conferencing');
        Route::post('conferencing/servers', [$sc, 'store']);
        Route::put('conferencing/servers/{server}', [$sc, 'update'])->whereNumber('server');
        Route::delete('conferencing/servers/{server}', [$sc, 'destroy'])->whereNumber('server');
        Route::post('conferencing/servers/{server}/check', [$sc, 'check'])->whereNumber('server')->middleware('throttle:20,1,pbbbchk');
        Route::get('branding', [$pc, 'branding'])->name('branding');
        Route::post('branding', [$pc, 'saveBranding'])->name('branding.update');
        Route::get('coupons', [$pc, 'coupons'])->name('coupons');
        Route::post('coupons', [$pc, 'saveCoupon'])->name('coupons.store');
        Route::put('coupons/{coupon}', [$pc, 'saveCoupon'])->whereNumber('coupon')->name('coupons.update');
        Route::post('invoices/{invoice}/void', [$pc, 'voidInvoice'])->whereNumber('invoice')->name('invoice.void');
        Route::post('invoices/{invoice}/payment', [$pc, 'recordPayment'])->whereNumber('invoice')->name('invoice.payment');
        Route::get('applications', [$pc, 'applications'])->name('applications');
        Route::post('applications/{application}', [$pc, 'decideApplication'])->whereNumber('application')->name('applications.decide');
        Route::get('plans', [$pc, 'plans'])->name('plans');
        Route::post('plans', [$pc, 'savePlan'])->name('plans.store');
        Route::put('plans/{plan}', [$pc, 'savePlan'])->whereNumber('plan')->name('plans.update');
        Route::get('payments', [$pc, 'payments'])->name('payments');
        Route::get('admins', [$pc, 'admins'])->name('admins');
        Route::post('admins', [$pc, 'storeAdmin'])->middleware('throttle:10,1,pladm')->name('admins.store');
        Route::post('admins/{user}/toggle', [$pc, 'disableAdmin'])->whereNumber('user')->name('admins.toggle');
        $sh = \App\Http\Controllers\Monitoring\SystemHealthController::class;
        Route::get('health', [$sh, 'platform'])->name('health');
        Route::post('health/alerts/{id}/ack', [$sh, 'acknowledge'])->whereNumber('id');
        Route::post('health/drill', [$sh, 'drill'])->middleware('throttle:3,10,drill')->name('health.drill');
        Route::get('docs/{slug?}', [\App\Http\Controllers\DocsController::class, 'platform'])->where('slug', '[a-z0-9-]+')->name('docs');
        Route::get('security', [$pc, 'security'])->name('security');
        Route::post('security', [$pc, 'runSecurity'])->middleware('throttle:6,1,secchk')->name('security.run');
        Route::get('modules', [ModuleController::class, 'index'])->name('modules.index');
        Route::put('modules/{tenant}', [ModuleController::class, 'update'])->name('modules.update');
    });

    // Question bank (module: question_bank)
    Route::middleware(['module:question_bank'])->prefix('question-bank')->name('question-bank.')->group(function () {
        Route::get('/', [QuestionController::class, 'index'])->middleware('permission:questions.view')->name('index');
        Route::get('export', [QuestionController::class, 'export'])->middleware(['permission:questions.view', 'throttle:10,1,web119'])->name('export');
        Route::middleware('permission:questions.create')->group(function () {
            Route::get('questions/create', [QuestionController::class, 'create'])->name('create');
            Route::post('questions', [QuestionController::class, 'store'])->name('store');
            Route::get('questions/{question}/edit', [QuestionController::class, 'edit'])->name('edit');
            Route::put('questions/{question}', [QuestionController::class, 'update'])->name('update');
            Route::post('preview', [QuestionController::class, 'preview'])->middleware('throttle:120,1,web125')->name('preview');
            Route::get('import', [ImportController::class, 'create'])->name('import.create');
            Route::post('import', [ImportController::class, 'store'])->name('import.store');
            Route::get('imports/{import}', [ImportController::class, 'show'])->name('imports.show');
            Route::post('imports/{import}/commit', [ImportController::class, 'commit'])->name('imports.commit');
        });
        Route::post('moderate', [QuestionController::class, 'moderate'])->middleware('permission:questions.moderate')->name('moderate');
    });

    // ── Exams (module: question_bank = exam delivery) ─────────────────────────
    Route::middleware(['module:question_bank', 'permission:exams.manage'])->group(function () {
        Route::get('exams', [ExamAdminController::class, 'index'])->name('exams.index');
        Route::post('exams', [ExamAdminController::class, 'store'])->name('exams.store');
        Route::get('exams/{exam}', [ExamAdminController::class, 'show'])->name('exams.show');
        Route::post('exams/{exam}/anomalies', [ExamAdminController::class, 'runAnomalies'])->middleware('throttle:10,1,anomrun')->middleware('module:analytics');
        Route::post('anomalies/{anomaly}', [ExamAdminController::class, 'reviewAnomaly'])->whereNumber('anomaly')->middleware('module:analytics');
        Route::put('exams/{exam}', [ExamAdminController::class, 'update'])->name('exams.update');
        Route::post('exams/{exam}/transition', [ExamAdminController::class, 'transition'])->name('exams.transition');
        Route::post('exams/{exam}/follow-up', [ExamAdminController::class, 'followUp'])->name('exams.follow-up');
        Route::get('exam-calendar', [\App\Http\Controllers\Exam\SchedulingController::class, 'calendar'])->name('exams.calendar');
        Route::post('exam-calendar/generate', [\App\Http\Controllers\Exam\SchedulingController::class, 'generate'])->middleware('throttle:10,1,web143')->name('exams.calendar.generate');
        Route::post('exam-calendar/runs/{run}/commit', [\App\Http\Controllers\Exam\SchedulingController::class, 'commit'])->whereNumber('run')->name('exams.calendar.commit');
        Route::post('exam-calendar/runs/{run}/discard', [\App\Http\Controllers\Exam\SchedulingController::class, 'discard'])->whereNumber('run')->name('exams.calendar.discard');
        Route::post('exam-calendar/bulk', [\App\Http\Controllers\Exam\SchedulingController::class, 'bulk'])->name('exams.calendar.bulk');
        Route::post('exam-calendar/roster', [\App\Http\Controllers\Exam\SchedulingController::class, 'roster'])->name('exams.calendar.roster');
        Route::post('sessions/{session}/recheck', [SessionController::class, 'recheck'])->name('sessions.recheck');
        Route::get('sessions/{session}/slips', [\App\Http\Controllers\DocumentController::class, 'sessionSlips'])->middleware('throttle:10,1,web149')->name('sessions.slips');
        Route::post('exams/{exam}/sections', [ExamAdminController::class, 'storeSection'])->name('exams.sections.store');
        Route::put('exams/{exam}/sections/{section}', [ExamAdminController::class, 'updateSection'])->name('exams.sections.update');
        Route::delete('exams/{exam}/sections/{section}', [ExamAdminController::class, 'destroySection'])->name('exams.sections.destroy');
        Route::post('exams/{exam}/sections/{section}/questions', [ExamAdminController::class, 'attachQuestions'])->name('exams.sections.attach');
        Route::delete('exams/{exam}/sections/{section}/questions/{question}', [ExamAdminController::class, 'detachQuestion'])->name('exams.sections.detach');
        Route::get('exams/{exam}/question-search', [ExamAdminController::class, 'searchQuestions'])->name('exams.question-search');
        Route::put('exams/{exam}/criteria', [ExamAdminController::class, 'saveCriteria'])->name('exams.criteria');
        Route::post('exams/{exam}/examiners', [ExamAdminController::class, 'assignExaminer'])->name('exams.examiners.store');
        Route::delete('exams/{exam}/examiners/{user}', [ExamAdminController::class, 'removeExaminer'])->name('exams.examiners.destroy');
        $ea = \App\Http\Controllers\Exam\ExamExaminerAccountController::class;
        Route::post('exams/{exam}/examiner-accounts', [$ea, 'store'])->middleware('throttle:30,1,examacct')->name('exams.examiner-accounts.store');
        Route::post('exams/{exam}/examiner-accounts/{account}/reset-session', [$ea, 'resetSession'])->name('exams.examiner-accounts.reset');
        Route::put('exams/{exam}/examiner-accounts/{account}/password', [$ea, 'password'])->name('exams.examiner-accounts.password');
        Route::delete('exams/{exam}/examiner-accounts/{account}', [$ea, 'destroy'])->name('exams.examiner-accounts.destroy');

        Route::post('exams/{exam}/sessions', [SessionController::class, 'store'])->name('sessions.store');
        Route::get('sessions/{session}', [SessionController::class, 'show'])->name('sessions.show');
        Route::put('sessions/{session}', [SessionController::class, 'update'])->name('sessions.update');
        Route::post('sessions/{session}/candidates', [SessionController::class, 'addCandidates'])->name('sessions.candidates.store');
        Route::put('sessions/{session}/candidates/{candidate}', [SessionController::class, 'updateCandidate'])->name('sessions.candidates.update');
        Route::post('sessions/{session}/pins', [SessionController::class, 'generatePins'])->name('sessions.pins')->middleware('module:cbt_halls');
        Route::post('sessions/{session}/seats', [SessionController::class, 'allocateSeats'])->name('sessions.seats')->middleware('module:cbt_halls');
        Route::post('sessions/{session}/proctors', [SessionController::class, 'assignProctor'])->name('sessions.proctors.store');
        Route::delete('sessions/{session}/proctors/{assignment}', [SessionController::class, 'removeProctor'])->name('sessions.proctors.destroy');

        Route::get('exam-network', [\App\Http\Controllers\Exam\NetworkController::class, 'index'])->name('exams.network')->middleware('module:cbt_halls');
        Route::get('exam-network/history', [\App\Http\Controllers\Exam\NetworkController::class, 'batch'])->name('exams.network.history')->middleware('module:cbt_halls');
        Route::put('exam-network', [\App\Http\Controllers\Exam\NetworkController::class, 'saveAddress'])->name('exams.network.save')->middleware('module:cbt_halls');
        Route::post('exam-network/approve-all', [\App\Http\Controllers\Exam\NetworkController::class, 'approveAll'])->name('exams.network.approve-all')->middleware('module:cbt_halls');
        Route::post('exam-network/reset', [\App\Http\Controllers\Exam\NetworkController::class, 'reset'])->name('exams.network.reset')->middleware('module:cbt_halls');
        Route::put('exam-network/computers/{computer}', [\App\Http\Controllers\Exam\NetworkController::class, 'decide'])->name('exams.network.decide')->middleware('module:cbt_halls');
        Route::delete('exam-network/computers/{computer}', [\App\Http\Controllers\Exam\NetworkController::class, 'destroy'])->name('exams.network.destroy')->middleware('module:cbt_halls');
        Route::get('venues', [VenueController::class, 'index'])->name('venues.index')->middleware('module:cbt_halls');
        Route::post('venues', [VenueController::class, 'store'])->name('venues.store')->middleware('module:cbt_halls');
        Route::put('devices/{device}', [VenueController::class, 'updateDevice'])->name('devices.update')->middleware('module:cbt_halls');
    });

    // Check-in at the hall door: invigilators
    Route::post('sessions/{session}/candidates/{candidate}/check-in', [SessionController::class, 'checkIn'])
        ->middleware(['module:question_bank', 'permission:exams.invigilate'])->name('sessions.check-in');

    // ── Exam board: live statistics, score board, rescaling (exams office: all; examiners: their own) ──
    Route::middleware(['module:question_bank'])->prefix('exam-board')->name('exam-board.')->group(function () {
        $c = \App\Http\Controllers\Exam\ExamBoardController::class;
        Route::get('/', [$c, 'index'])->name('index');
        Route::get('{exam}', [$c, 'show'])->name('show');
        Route::get('{exam}/export', [$c, 'export'])->middleware('throttle:20,1,boardexp')->name('export');
        Route::post('{exam}/scale', [$c, 'applyScale'])->name('scale');
        Route::post('{exam}/candidates', [$c, 'addCandidates'])->name('candidates');
        Route::post('{exam}/questions', [$c, 'upload'])->middleware('throttle:20,1,boardup')->name('upload');
        Route::get('{exam}/questions/{import}', [$c, 'preview'])->name('import');
        Route::post('{exam}/questions/{import}/commit', [$c, 'commit'])->name('import.commit');
    });

    // ── Proctoring console & incidents (module: browser_proctoring) ───────────
    // Watching a sitting: proctors and invigilators assigned to it, the exams office, and the exam's own
    // examiners and course lecturers (ProctorController::authorizeSession decides for each sitting)
    Route::middleware(['module:browser_proctoring'])->group(function () {
        Route::get('proctor', [ProctorController::class, 'index'])->name('proctor.index');
        Route::get('proctor/sessions/{session}', [ProctorController::class, 'console'])->name('proctor.console');
        Route::get('proctor/sessions/{session}/live', [ProctorController::class, 'live'])->name('proctor.live');
        Route::get('proctor/recordings/{recording}', [ProctorController::class, 'recording'])->name('proctor.recording')->middleware('module:live_proctoring');
        Route::get('proctor/sessions/{session}/media-token', [\App\Http\Controllers\Media\MediaController::class, 'proctor'])->middleware('throttle:30,1,pmedia')->name('proctor.media')->middleware('module:live_proctoring');
        Route::get('proctor/attempts/{attempt}/timeline', [ProctorController::class, 'timeline'])->name('proctor.timeline');
        Route::get('proctor/attempts/{attempt}/reference/{which}', [ProctorController::class, 'referencePhoto'])->whereIn('which', ['enrolment', 'id'])->name('proctor.reference');
        Route::post('proctor/attempts/{attempt}/action', [ProctorController::class, 'act'])->name('proctor.act');
        Route::get('proctor/evidence/{evidence}', [ProctorController::class, 'snapshot'])->name('proctor.evidence');
    });
    Route::middleware(['module:browser_proctoring', 'permission:exams.proctor|exams.invigilate|exams.manage'])->group(function () {
        Route::put('proctor/layout', [ProctorController::class, 'saveLayout'])->middleware('throttle:30,1,web182')->name('proctor.layout');
        Route::post('proctor/training', [ProctorController::class, 'startTraining'])->middleware('throttle:5,1,web183')->name('proctor.training');
        Route::delete('proctor/training/{session}', [ProctorController::class, 'endTraining'])->name('proctor.training.end');
        Route::get('proctor/shifts', [\App\Http\Controllers\Exam\SchedulingController::class, 'shifts'])->name('proctor.shifts');
        Route::post('proctor/availability', [\App\Http\Controllers\Exam\SchedulingController::class, 'saveAvailability'])->name('proctor.availability');
        Route::delete('proctor/availability/{id}', [\App\Http\Controllers\Exam\SchedulingController::class, 'deleteAvailability'])->name('proctor.availability.delete');
        Route::post('proctor/sessions/{session}/action', [ProctorController::class, 'sessionAction'])->name('proctor.session-action');

        Route::get('incidents', [IncidentController::class, 'index'])->name('incidents.index');
        Route::post('incidents', [IncidentController::class, 'store'])->name('incidents.store');
        Route::get('incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
        Route::put('incidents/{incident}', [IncidentController::class, 'update'])->name('incidents.update');
        Route::post('incidents/{incident}/notes', [IncidentController::class, 'note'])->name('incidents.notes');
    });

    // ── Marking (essay queue; practical & viva sheets) ────────────────────────
    Route::middleware(['module:question_bank', 'permission:results.mark'])->group(function () {
        Route::get('marking', [MarkingController::class, 'index'])->name('marking.index');
        Route::get('marking/{exam}/essays', [MarkingController::class, 'essays'])->name('marking.essays');
        Route::post('marking/{exam}/essays', [MarkingController::class, 'markEssay'])->name('marking.essays.store');
        Route::get('marking/{exam}/panel', [MarkingController::class, 'panel'])->name('marking.panel');
        Route::post('marking/{exam}/panel', [MarkingController::class, 'savePanel'])->name('marking.panel.store');
    });

    // ── Results & analytics (module: results; analytics module for item analysis) ──
    Route::middleware(['module:results'])->group(function () {
        Route::get('results/{exam}', [ResultController::class, 'show'])->name('results.show');
        Route::post('results/{exam}/transition', [ResultController::class, 'transition'])->name('results.transition');
        Route::get('results/{exam}/export', [ResultController::class, 'export'])->name('results.export');
        Route::post('results/items/{result}/withhold', [ResultController::class, 'withhold'])->name('results.withhold');
        Route::post('results/items/{result}/change', [ResultController::class, 'requestChange'])->name('results.change');
        Route::post('results/changes/{change}', [ResultController::class, 'decideChange'])->name('results.change.decide');
        Route::post('results/appeals/{appeal}', [ResultController::class, 'decideAppeal'])->name('results.appeal.decide');
    });
    Route::get('results/{exam}/analytics', [ResultController::class, 'analytics'])->middleware(['module:analytics', 'permission:results.mark'])->name('results.analytics');

    // ── Candidate side ────────────────────────────────────────────────────────
    Route::middleware(['module:question_bank'])->prefix('my')->name('candidate.')->group(function () {
        Route::get('exams', [CandidateExamController::class, 'index'])->name('exams');
        Route::get('exams/{candidate}', [CandidateExamController::class, 'room'])->name('room');
        Route::post('exams/{candidate}/start', [CandidateExamController::class, 'start'])->middleware('throttle:exam-start')->name('start');
        Route::get('exams/{candidate}/seb', [CandidateExamController::class, 'sebConfig'])->name('seb')->middleware('module:secure_client');
        Route::get('results', [CandidateExamController::class, 'results'])->name('results');
        Route::post('results/{result}/appeal', [CandidateExamController::class, 'appeal'])->name('appeal');
    });

    // ── LMS (native) ─────────────────────────────────────────────────────────
    // ── LMS extras: calendar, search, messages, help centre, uploads ───────────
    Route::middleware(['module:lms'])->group(function () {
        Route::get('learn/calendar', [Lms\CalendarController::class, 'show'])->name('calendar.show');
        Route::post('learn/calendar/token', [Lms\CalendarController::class, 'token'])->middleware('throttle:10,1,caltok')->name('calendar.token');
        Route::put('learn/offerings/{offering}/schedule', [Lms\CalendarController::class, 'saveSchedule'])->name('calendar.schedule');
        Route::get('learn/search', [Lms\CalendarController::class, 'search'])->middleware('throttle:60,1,search')->name('search');

        Route::get('messages', [Lms\MessageController::class, 'index'])->name('messages.index');
        Route::get('messages/recipients', [Lms\MessageController::class, 'recipients'])->middleware('throttle:60,1,msgrec')->name('messages.recipients');
        Route::post('messages', [Lms\MessageController::class, 'store'])->middleware('throttle:20,1,msgnew')->name('messages.store');
        Route::post('messages/{conversation}', [Lms\MessageController::class, 'reply'])->middleware('throttle:60,1,msgreply')->name('messages.reply');
        Route::post('messages/{conversation}/mute', [Lms\MessageController::class, 'mute'])->name('messages.mute');
        Route::post('messages/items/{message}/report', [Lms\MessageController::class, 'report'])->whereNumber('message')->name('messages.report');

        Route::post('uploads', [Lms\UploadController::class, 'start'])->middleware('throttle:30,1,upstart')->name('uploads.start');
        Route::put('uploads/{id}', [Lms\UploadController::class, 'chunk'])->whereUuid('id')->middleware('throttle:600,1,upchunk')->name('uploads.chunk');
        Route::get('uploads/{id}', [Lms\UploadController::class, 'status'])->whereUuid('id')->name('uploads.status');
    });
    Route::get('help', [Lms\HelpController::class, 'index'])->name('help.index');
    Route::post('help/tickets', [Lms\HelpController::class, 'storeTicket'])->middleware('throttle:10,1,ticket')->name('help.tickets.store');
    Route::get('help/tickets/{ticket}', [Lms\HelpController::class, 'ticket'])->name('help.ticket');
    Route::post('help/tickets/{ticket}', [Lms\HelpController::class, 'replyTicket'])->middleware('throttle:60,1,ticketreply')->name('help.ticket.reply');
    Route::post('help/articles', [Lms\HelpController::class, 'saveArticle'])->name('help.articles.store');
    Route::put('help/articles/{article}', [Lms\HelpController::class, 'saveArticle'])->name('help.articles.update');
    Route::post('help/articles/{article}/feedback', [Lms\HelpController::class, 'feedback'])->middleware('throttle:20,1,helpfb')->name('help.feedback');
    Route::get('help/{slug}', [Lms\HelpController::class, 'article'])->where('slug', '[a-z0-9-]+')->name('help.article');

    Route::middleware(['module:lms'])->prefix('learn')->name('learn.')->group(function () {
        Route::get('courses', [Lms\CourseController::class, 'index'])->name('courses');
        Route::get('courses/{offering}', [Lms\CourseController::class, 'show'])->name('course');
        Route::put('courses/{offering}/outline', [Lms\CourseController::class, 'updateOutline']);
        Route::post('courses/{offering}/review', [Lms\CourseController::class, 'review']);
        Route::post('courses/{offering}/sections', [Lms\CourseController::class, 'storeSection']);
        Route::post('courses/{offering}/weeks', [Lms\CourseController::class, 'setupWeeks'])->middleware('throttle:20,1,weeks');
        Route::put('courses/{offering}/sections/{section}', [Lms\CourseController::class, 'updateSection']);
        Route::post('courses/{offering}/items', [Lms\CourseController::class, 'storeItem']);
        Route::post('courses/{offering}/copy', [Lms\CourseController::class, 'copy']);
        Route::get('courses/{offering}/class-list', [Lms\RegistrationController::class, 'classList']);
        Route::get('courses/{offering}/gradebook', [Lms\GradebookController::class, 'show'])->name('gradebook');
        Route::put('courses/{offering}/gradebook/categories', [Lms\GradebookController::class, 'categories']);
        Route::post('courses/{offering}/gradebook/items', [Lms\GradebookController::class, 'storeItem']);
        Route::post('courses/{offering}/gradebook/transition', [Lms\GradebookController::class, 'transition']);
        Route::get('courses/{offering}/gradebook/export', [Lms\GradebookController::class, 'export']);
        Route::post('grade-items/{gradeItem}/entry', [Lms\GradebookController::class, 'entry']);
        Route::get('courses/{offering}/attendance', [Lms\CommunityController::class, 'attendance'])->name('attendance');
        Route::post('courses/{offering}/meetings', [Lms\CommunityController::class, 'storeMeeting']);
        Route::post('meetings/{meeting}/records', [Lms\CommunityController::class, 'mark']);
        Route::post('meetings/{meeting}/recording', [Lms\CommunityController::class, 'saveRecording'])->middleware('throttle:30,1,recsave');
        Route::get('meetings/{meeting}/recording', [Lms\CommunityController::class, 'recording'])->middleware('throttle:120,1,recplay')->name('meetings.recording');
        Route::post('attendance/check-in', [Lms\CommunityController::class, 'checkIn']);

        Route::get('items/{item}', [Lms\ItemController::class, 'show'])->name('item');
        Route::post('items/{item}', [Lms\CourseController::class, 'updateItem']);
        Route::delete('items/{item}', [Lms\CourseController::class, 'destroyItem']);
        Route::post('items/{item}/complete', [Lms\CourseController::class, 'complete']);
        Route::get('items/{item}/file', [Lms\CourseController::class, 'download'])->name('item.file');
        Route::post('items/{item}/submit', [Lms\ItemController::class, 'submit'])->middleware('throttle:20,1,web260');
        Route::post('items/{item}/release', [Lms\ItemController::class, 'release']);
        Route::post('items/{item}/extensions', [Lms\ItemController::class, 'extend']);
        Route::post('items/{item}/groups', [Lms\ItemController::class, 'saveGroups']);
        Route::post('items/{item}/quiz/start', [Lms\ItemController::class, 'startQuiz']);
        Route::post('items/{item}/quiz/publish', [Lms\ItemController::class, 'publishQuiz']);
        Route::get('items/{item}/quiz/questions', [Lms\ItemController::class, 'quizQuestions']);
        Route::post('items/{item}/quiz/questions', [Lms\ItemController::class, 'quizAttach']);
        Route::post('items/{item}/threads', [Lms\ItemController::class, 'storeThread'])->middleware('throttle:10,1,web268');
        Route::get('submissions/{submission}/file', [Lms\ItemController::class, 'submissionFile']);
        Route::post('submissions/{submission}/mark', [Lms\ItemController::class, 'mark']);
        Route::post('submissions/{submission}/peer', [Lms\ItemController::class, 'peerReview']);
        Route::get('threads/{thread}', [Lms\ItemController::class, 'thread'])->name('thread');
        Route::post('threads/{thread}/posts', [Lms\ItemController::class, 'reply'])->middleware('throttle:30,1,web273');
        Route::post('threads/{thread}/moderate', [Lms\ItemController::class, 'moderate']);

        Route::get('registration', [Lms\RegistrationController::class, 'show'])->name('registration');
        Route::post('registration', [Lms\RegistrationController::class, 'store']);
        Route::get('registration/slip', [Lms\RegistrationController::class, 'slip'])->name('slip');
        Route::post('registration/override', [Lms\RegistrationController::class, 'override']);
        Route::post('registration/window', [Lms\RegistrationController::class, 'window']);
        Route::post('enrolments/{enrolment}/decide', [Lms\RegistrationController::class, 'decide']);

        Route::get('grades', [Lms\GradebookController::class, 'mine'])->name('grades');
        Route::get('announcements', [Lms\CommunityController::class, 'announcements'])->name('announcements');
        Route::post('announcements', [Lms\CommunityController::class, 'announce'])->middleware('throttle:20,1,web285');
    });
    Route::get('notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications');
    Route::post('notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::delete('notifications', [\App\Http\Controllers\NotificationController::class, 'clear'])->name('notifications.clear');
    Route::post('notifications/{id}/read', [\App\Http\Controllers\NotificationController::class, 'read'])->whereUuid('id')->name('notifications.read');
    Route::delete('notifications/{id}', [\App\Http\Controllers\NotificationController::class, 'destroy'])->whereUuid('id')->name('notifications.destroy');
});

require __DIR__.'/settings.php';
