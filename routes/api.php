<?php

use App\Http\Controllers\Api\LegalQuestionController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Admin\AcademicController;
use App\Http\Controllers\Api\V1\Admin\CommunityController;
use App\Http\Controllers\Api\V1\Admin\ExamController as AdminExamController;
use App\Http\Controllers\Api\V1\Admin\LetterController as AdminLetterController;
use App\Http\Controllers\Api\V1\Admin\MonitorController;
use App\Http\Controllers\Api\V1\Admin\PeopleController;
use App\Http\Controllers\Api\V1\Admin\RecordsController;
use App\Http\Controllers\Api\V1\Admin\SemesterController;
use App\Http\Controllers\Api\V1\AppInfoController;
use App\Http\Controllers\Api\V1\ArticleController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DiscussionController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\DownloadController;
use App\Http\Controllers\Api\V1\FeedbackController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\ForumController;
use App\Http\Controllers\Api\V1\GuardianController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\Student\AssignmentController;
use App\Http\Controllers\Api\V1\Student\ClassController;
use App\Http\Controllers\Api\V1\Student\EvaluationController;
use App\Http\Controllers\Api\V1\Student\ExamController;
use App\Http\Controllers\Api\V1\Student\HomeController;
use App\Http\Controllers\Api\V1\Student\LetterController;
use App\Http\Controllers\Api\V1\Student\QuizController;
use App\Http\Controllers\Api\V1\StudentRecordController;
use App\Http\Controllers\Api\V1\TanyaHukumController;
use App\Http\Controllers\Api\V1\Teacher\AssignmentController as TeacherAssignmentController;
use App\Http\Controllers\Api\V1\Teacher\GradeController;
use App\Http\Controllers\Api\V1\Teacher\HomeController as TeacherHomeController;
use App\Http\Controllers\Api\V1\Teacher\MaterialController;
use App\Http\Controllers\Api\V1\Teacher\MeetingController;
use App\Http\Controllers\Api\V1\Teacher\QuizController as TeacherQuizController;
use App\Http\Controllers\Api\V1\Teacher\TeachingDocumentController;
use App\Http\Middleware\VerifyCmsRequest;
use Illuminate\Support\Facades\Route;

/*
| Calls from the website CMS (v5, docs/req/siakad-api-spec.md §4). The CMS sets
| SIAKAD_BASE_URL to {APP_URL}/api/cms. Bearer API key + signature, no session.
*/
Route::prefix('cms')->middleware(['throttle:60,1', VerifyCmsRequest::class])->group(function () {
    Route::post('legal-questions', [LegalQuestionController::class, 'store'])->name('api.cms.legal-questions');
});

/*
| The mobile app API (v6 D37–D41), documented at /docs/api and in docs/API.md.
| Bearer token (Sanctum, one per device) and the X-Role header; the same Action
| classes and rules as the web pages. Breaking changes go to /api/v2 (D40).
*/
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('app', AppInfoController::class)->middleware('throttle:api')->name('app');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:api-login')->name('login');

    // Signed links from other responses (photos, documents); they carry who asked and expire.
    Route::get('files/{kind}/{id}', FileController::class)->whereNumber('id')->middleware(['signed', 'throttle:api'])->name('files');
    Route::get('downloads/{document}', DownloadController::class)->middleware(['signed', 'throttle:api'])->name('downloads');

    Route::middleware(['auth:sanctum', 'api.account', 'api.role', 'throttle:api'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('auth/devices', [AuthController::class, 'devices'])->name('devices');
        Route::delete('auth/devices/{device}', [AuthController::class, 'destroyDevice'])->whereNumber('device')->name('devices.destroy');
        Route::put('auth/push-token', [AuthController::class, 'pushToken'])->name('push-token');

        Route::get('me', [AccountController::class, 'me'])->name('me');
        Route::get('profile', [AccountController::class, 'profile'])->name('profile');
        Route::patch('profile', [AccountController::class, 'updateProfile'])->name('profile.update');
        Route::put('password', [AccountController::class, 'password'])->name('password');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification')->name('notifications.read');

        // Kritik & saran (v6 D34), every role.
        Route::get('feedback', [FeedbackController::class, 'index'])->name('feedback');
        Route::post('feedback', [FeedbackController::class, 'store'])->name('feedback.store');

        // One mahasantri's records: their own, a wali's children, academic staff (StudentAccess).
        Route::prefix('students/{student}')->name('students.')->group(function () {
            Route::get('khs', [StudentRecordController::class, 'khs'])->name('khs');
            Route::get('transcript', [StudentRecordController::class, 'transcript'])->name('transcript');
            Route::get('bills', [StudentRecordController::class, 'bills'])->name('bills');
            Route::get('activities', [StudentRecordController::class, 'activities'])->name('activities');
        });
        Route::post('bills/{bill}/transfers', [StudentRecordController::class, 'confirmTransfer'])->middleware('role:student,guardian')->name('bills.transfers');

        // Dokumen saya: mahasantri and muhadhir.
        Route::middleware('role:student,teacher')->group(function () {
            Route::get('documents', [DocumentController::class, 'index'])->name('documents');
            Route::post('documents/{document}', [DocumentController::class, 'upload'])->whereNumber('document')->name('documents.upload');
        });

        // Mahasantri (Step 6).
        Route::prefix('student')->name('student.')->middleware('role:student')->group(function () {
            Route::get('dashboard', [HomeController::class, 'dashboard'])->name('dashboard');
            Route::get('supervision', [HomeController::class, 'supervision'])->name('supervision');
            Route::get('classes', [ClassController::class, 'index'])->name('classes');
            Route::get('classes/{offering}', [ClassController::class, 'show'])->name('classes.show');
            Route::post('assignments/{assignment}/submission', [AssignmentController::class, 'submit'])->name('assignments.submit');
            Route::post('quizzes/{quiz}/attempts', [QuizController::class, 'start'])->name('quizzes.start');
            Route::get('quiz-attempts/{attempt}', [QuizController::class, 'show'])->name('quiz-attempts.show');
            Route::put('quiz-attempts/{attempt}/answers/{question}', [QuizController::class, 'answer'])->name('quiz-attempts.answer');
            Route::post('quiz-attempts/{attempt}/submit', [QuizController::class, 'submit'])->name('quiz-attempts.submit');
            Route::get('exams', [ExamController::class, 'index'])->name('exams');
            Route::get('letters', [LetterController::class, 'index'])->name('letters');
            Route::post('letter-requests', [LetterController::class, 'store'])->name('letter-requests.store');
            Route::get('evaluations', [EvaluationController::class, 'index'])->name('evaluations');
            Route::post('evaluations/{offering}', [EvaluationController::class, 'store'])->name('evaluations.store');
        });

        // Wali santri (Step 6); the children's records are under students/{student}.
        Route::get('guardian/children', [GuardianController::class, 'children'])->middleware('role:guardian')->name('guardian.children');

        // Forums of a class kuliah (scope "class") and of a pembimbing akademik (scope "supervision").
        Route::prefix('forums/{scope}/{id}/threads')->whereIn('scope', ['class', 'supervision'])->whereNumber('id')
            ->middleware('role:student,teacher')->name('forums.')->group(function () {
                Route::get('/', [ForumController::class, 'threads'])->name('threads');
                Route::post('/', [ForumController::class, 'store'])->name('store');
                Route::get('{thread}', [ForumController::class, 'show'])->whereNumber('thread')->name('show');
                Route::post('{thread}/posts', [ForumController::class, 'reply'])->whereNumber('thread')->name('reply');
                Route::post('{thread}/pin', [ForumController::class, 'pin'])->whereNumber('thread')->name('pin');
                Route::post('{thread}/lock', [ForumController::class, 'lock'])->whereNumber('thread')->name('lock');
                Route::delete('{thread}', [ForumController::class, 'destroy'])->whereNumber('thread')->name('destroy');
                Route::delete('posts/{post}', [ForumController::class, 'destroyPost'])->whereNumber('post')->name('posts.destroy');
            });

        // Discussions (Step 8): bimbingan groups, LPPPK channels, Tanya Hukum rooms; access per room (DiscussionAccess).
        Route::get('discussions', [DiscussionController::class, 'index'])->name('discussions');
        Route::prefix('discussions/{discussion}')->name('discussions.')->group(function () {
            Route::get('/', [DiscussionController::class, 'show'])->name('show');
            Route::get('messages', [DiscussionController::class, 'messages'])->name('messages');
            Route::post('messages', [DiscussionController::class, 'send'])->name('send');
            Route::delete('messages/{message}', [DiscussionController::class, 'hide'])->name('messages.hide');
            Route::get('files', [DiscussionController::class, 'files'])->name('files');
            Route::get('notes', [DiscussionController::class, 'notes'])->name('notes');
            Route::post('notes', [DiscussionController::class, 'storeNote'])->name('notes.store');
            Route::put('notes/{note}', [DiscussionController::class, 'updateNote'])->name('notes.update');
            Route::delete('notes/{note}', [DiscussionController::class, 'hideNote'])->name('notes.hide');
            Route::post('members/{member}/mute', [DiscussionController::class, 'mute'])->name('members.mute');
            Route::delete('members/{member}/mute', [DiscussionController::class, 'unmute'])->name('members.unmute');
            Route::put('lock', [DiscussionController::class, 'lock'])->name('lock');
        });

        // Articles (Step 8): mahasantri and muhadhir write; staff who oversee read and withdraw.
        Route::get('articles/mine', [ArticleController::class, 'mine'])->name('articles.mine');
        Route::get('articles/review', [ArticleController::class, 'reviewQueue'])->name('articles.review');
        Route::post('articles', [ArticleController::class, 'store'])->name('articles.store');
        Route::prefix('articles/{article}')->name('articles.')->group(function () {
            Route::get('/', [ArticleController::class, 'show'])->name('show');
            Route::put('/', [ArticleController::class, 'update'])->name('update');
            Route::delete('/', [ArticleController::class, 'discard'])->name('discard');
            Route::post('submit', [ArticleController::class, 'submit'])->name('submit');
            Route::post('publish', [ArticleController::class, 'publish'])->name('publish');
            Route::post('review', [ArticleController::class, 'review'])->name('review');
            Route::post('withdraw', [ArticleController::class, 'withdraw'])->name('withdraw');
            Route::post('website/resend', [ArticleController::class, 'resend'])->name('website.resend');
            Route::post('website/check', [ArticleController::class, 'check'])->name('website.check');
        });

        // Tanya Hukum (Step 8): everyone who uses LPPPK reads; muhadhir draft and approve.
        Route::get('tanya-hukum', [TanyaHukumController::class, 'index'])->name('tanya-hukum');
        Route::prefix('tanya-hukum/{question}')->name('tanya-hukum.')->group(function () {
            Route::get('/', [TanyaHukumController::class, 'show'])->name('show');
            Route::put('draft', [TanyaHukumController::class, 'draft'])->name('draft');
            Route::post('submit', [TanyaHukumController::class, 'submit'])->name('submit');
            Route::post('approve', [TanyaHukumController::class, 'approve'])->name('approve');
            Route::post('reopen', [TanyaHukumController::class, 'reopen'])->name('reopen');
            Route::post('correction', [TanyaHukumController::class, 'correction'])->name('correction');
            Route::post('resend', [TanyaHukumController::class, 'resend'])->name('resend');
        });

        // Academic staff (Step 9): Admin and Superadmin; the academic monitor where the web lets them.
        Route::prefix('admin')->name('admin.')->group(function () {
            Route::middleware('role:admin,superadmin')->group(function () {
                Route::get('academic-years', [AcademicController::class, 'years'])->name('years');
                Route::post('academic-years', [AcademicController::class, 'storeYear'])->name('years.store');
                Route::put('academic-years/{year}', [AcademicController::class, 'updateYear'])->name('years.update');
                Route::post('semesters/{semester}/activate', [AcademicController::class, 'activate'])->name('semesters.activate');
                Route::post('semesters/{semester}/close', [AcademicController::class, 'close'])->name('semesters.close');
                Route::post('semesters/{semester}/reopen', [AcademicController::class, 'reopen'])->name('semesters.reopen');
                Route::get('study-programs', [AcademicController::class, 'programs'])->name('programs');
                Route::post('study-programs', [AcademicController::class, 'saveProgram'])->name('programs.save');
                Route::delete('study-programs/{program}', [AcademicController::class, 'deleteProgram'])->name('programs.delete');
                Route::get('subjects', [AcademicController::class, 'subjects'])->name('subjects');
                Route::post('subjects', [AcademicController::class, 'saveSubject'])->name('subjects.save');
                Route::get('rooms', [AcademicController::class, 'rooms'])->name('rooms');
                Route::post('rooms', [AcademicController::class, 'saveRoom'])->name('rooms.save');
                Route::get('classes', [AcademicController::class, 'classes'])->name('classes');
                Route::post('classes', [AcademicController::class, 'saveClass'])->name('classes.save');
                Route::delete('classes/{class}', [AcademicController::class, 'deleteClass'])->name('classes.delete');
                Route::get('grade-scale', [AcademicController::class, 'gradeScale'])->name('grade-scale');

                Route::prefix('semesters/{semester}')->name('semesters.')->group(function () {
                    Route::get('placements', [SemesterController::class, 'placements'])->name('placements');
                    Route::get('placements/candidates', [SemesterController::class, 'candidates'])->name('placements.candidates');
                    Route::post('placements', [SemesterController::class, 'enroll'])->name('placements.enroll');
                    Route::delete('placements/{placement}', [SemesterController::class, 'removePlacement'])->name('placements.remove');
                    Route::post('placements/copy', [SemesterController::class, 'copyPlacements'])->name('placements.copy');
                    Route::get('classes', [SemesterController::class, 'offerings'])->name('offerings');
                    Route::post('classes', [SemesterController::class, 'saveOffering'])->name('offerings.save');
                    Route::delete('classes/{offering}', [SemesterController::class, 'deleteOffering'])->name('offerings.delete');
                    Route::post('classes/copy', [SemesterController::class, 'copyOfferings'])->name('offerings.copy');
                    Route::get('exam-periods', [AdminExamController::class, 'periods'])->name('exams');
                    Route::post('exam-periods', [AdminExamController::class, 'storePeriod'])->name('exams.store');
                });

                Route::prefix('exam-periods/{period}')->name('exams.')->group(function () {
                    Route::get('/', [AdminExamController::class, 'show'])->name('show');
                    Route::post('sessions', [AdminExamController::class, 'saveSession'])->name('sessions.save');
                    Route::delete('sessions/{session}', [AdminExamController::class, 'deleteSession'])->name('sessions.delete');
                    Route::put('rooms', [AdminExamController::class, 'rooms'])->name('rooms');
                    Route::post('seats', [AdminExamController::class, 'assignSeats'])->name('seats');
                    Route::put('publish', [AdminExamController::class, 'publish'])->name('publish');
                    Route::get('participants', [AdminExamController::class, 'participants'])->name('participants');
                    Route::post('dispensations/{student}', [AdminExamController::class, 'grantDispensation'])->name('dispensations.grant');
                    Route::delete('dispensations/{student}', [AdminExamController::class, 'revokeDispensation'])->name('dispensations.revoke');
                });

                Route::get('students', [PeopleController::class, 'students'])->name('students');
                Route::post('students', [PeopleController::class, 'storeStudent'])->name('students.store');
                Route::get('students/{student}', [PeopleController::class, 'student'])->name('students.show');
                Route::post('students/{student}', [PeopleController::class, 'updateStudent'])->name('students.update');
                Route::get('teachers', [PeopleController::class, 'teachers'])->name('teachers');
                Route::post('teachers', [PeopleController::class, 'storeTeacher'])->name('teachers.store');
                Route::get('teachers/{teacher}', [PeopleController::class, 'teacher'])->name('teachers.show');
                Route::post('teachers/{teacher}', [PeopleController::class, 'updateTeacher'])->name('teachers.update');
                Route::get('guardians', [PeopleController::class, 'guardians'])->name('guardians');
                Route::post('guardians', [PeopleController::class, 'saveGuardian'])->name('guardians.save');
                Route::post('users/{user}/reset-password', [PeopleController::class, 'resetPassword'])->name('users.reset-password');
                Route::put('users/{user}/active', [PeopleController::class, 'setActive'])->name('users.active');

                Route::get('documents', [RecordsController::class, 'documents'])->name('documents');
                Route::post('documents/{document}/review', [RecordsController::class, 'reviewDocument'])->whereNumber('document')->name('documents.review');
                Route::get('supervision', [RecordsController::class, 'supervision'])->name('supervision');
                Route::post('supervision', [RecordsController::class, 'assignSupervision'])->name('supervision.assign');
                Route::delete('supervision/{supervision}', [RecordsController::class, 'endSupervision'])->name('supervision.end');

                Route::get('letters', [AdminLetterController::class, 'index'])->name('letters');
                Route::get('letters/overview', [AdminLetterController::class, 'overview'])->name('letters.overview');
                Route::post('letters/draft', [AdminLetterController::class, 'draft'])->name('letters.draft');
                Route::post('letters', [AdminLetterController::class, 'issue'])->name('letters.issue');
                Route::post('letters/{letter}/cancel', [AdminLetterController::class, 'cancel'])->name('letters.cancel');
                Route::post('letter-requests/{letterRequest}/reject', [AdminLetterController::class, 'reject'])->name('letter-requests.reject');
                Route::get('incoming-letters', [AdminLetterController::class, 'incoming'])->name('incoming');
                Route::post('incoming-letters', [AdminLetterController::class, 'saveIncoming'])->name('incoming.save');
            });

            Route::middleware('role:admin,superadmin,academic_monitor')->group(function () {
                Route::get('activities', [RecordsController::class, 'activities'])->name('activities');
                Route::post('activities', [RecordsController::class, 'storeActivity'])->name('activities.store');
                Route::delete('activities/{log}', [RecordsController::class, 'deleteActivity'])->name('activities.delete');

                Route::get('monitor', [MonitorController::class, 'dashboard'])->name('monitor');
                Route::post('classes/{offering}/unlock-grades', [MonitorController::class, 'unlock'])->name('grades.unlock');
                Route::get('teaching-documents', [MonitorController::class, 'teachingDocuments'])->name('teaching-documents');
                Route::post('teaching-documents/{document}/review', [MonitorController::class, 'review'])->name('teaching-documents.review');
                Route::post('teaching-documents/join', [MonitorController::class, 'join'])->name('teaching-documents.join');
                Route::delete('teaching-documents/join/{offering}', [MonitorController::class, 'split'])->name('teaching-documents.split');

                Route::get('bimbingan-groups', [CommunityController::class, 'groups'])->name('groups');
                Route::post('bimbingan-groups', [CommunityController::class, 'saveGroup'])->name('groups.save');
                Route::post('bimbingan-groups/{group}/members', [CommunityController::class, 'addMembers'])->name('groups.members.add');
                Route::delete('bimbingan-groups/{group}/members/{member}', [CommunityController::class, 'removeMember'])->name('groups.members.remove');
                Route::put('discussions/{discussion}/closed', [CommunityController::class, 'setClosed'])->name('discussions.closed');
                Route::post('lpppk-channels', [CommunityController::class, 'saveChannel'])->name('channels.save');
                Route::get('lpppk-sanctions', [CommunityController::class, 'sanctions'])->name('sanctions');
                Route::post('lpppk-sanctions/{person}', [CommunityController::class, 'sanction'])->name('sanctions.store');
                Route::delete('lpppk-sanctions/{person}', [CommunityController::class, 'lift'])->name('sanctions.lift');

                Route::get('feedback', [CommunityController::class, 'feedback'])->name('feedback');
                Route::put('feedback/{feedback}', [CommunityController::class, 'answerFeedback'])->name('feedback.answer');
                Route::get('evaluations', [CommunityController::class, 'evaluationResults'])->name('evaluations');
                Route::get('semesters/{semester}/evaluation', [CommunityController::class, 'evaluationSetup'])->name('evaluations.setup');
                Route::put('semesters/{semester}/evaluation', [CommunityController::class, 'saveEvaluationPeriod'])->name('evaluations.period');
                Route::post('semesters/{semester}/evaluation/prepare', [CommunityController::class, 'prepareStatements'])->name('evaluations.prepare');
                Route::post('semesters/{semester}/evaluation/statements', [CommunityController::class, 'saveStatement'])->name('evaluations.statements');
            });
        });

        // Muhadhir (Step 7). Each class route checks that the muhadhir teaches it.
        Route::prefix('teacher')->name('teacher.')->middleware('role:teacher')->group(function () {
            Route::get('classes', [TeacherHomeController::class, 'classes'])->name('classes');
            Route::get('supervisees', [TeacherHomeController::class, 'supervisees'])->name('supervisees');

            Route::prefix('classes/{offering}')->name('classes.')->group(function () {
                Route::get('meetings', [MeetingController::class, 'index'])->name('meetings');
                Route::post('meetings', [MeetingController::class, 'store'])->name('meetings.store');
                Route::put('meetings/{meeting}', [MeetingController::class, 'update'])->name('meetings.update');
                Route::delete('meetings/{meeting}', [MeetingController::class, 'destroy'])->name('meetings.destroy');
                Route::get('meetings/{meeting}/attendance', [MeetingController::class, 'attendance'])->name('meetings.attendance');
                Route::put('meetings/{meeting}/attendance', [MeetingController::class, 'saveAttendance'])->name('meetings.attendance.save');

                Route::get('materials', [MaterialController::class, 'index'])->name('materials');
                Route::post('materials', [MaterialController::class, 'store'])->name('materials.store');
                Route::post('materials/{material}', [MaterialController::class, 'update'])->name('materials.update');
                Route::delete('materials/{material}', [MaterialController::class, 'destroy'])->name('materials.destroy');

                Route::get('assignments', [TeacherAssignmentController::class, 'index'])->name('assignments');
                Route::post('assignments', [TeacherAssignmentController::class, 'store'])->name('assignments.store');
                Route::put('assignments/{assignment}', [TeacherAssignmentController::class, 'update'])->name('assignments.update');
                Route::delete('assignments/{assignment}', [TeacherAssignmentController::class, 'destroy'])->name('assignments.destroy');
                Route::get('assignments/{assignment}/submissions', [TeacherAssignmentController::class, 'submissions'])->name('assignments.submissions');
                Route::put('assignments/{assignment}/submissions/{submission}/grade', [TeacherAssignmentController::class, 'grade'])->name('assignments.grade');
                Route::post('assignments/{assignment}/copy-to-grades', [TeacherAssignmentController::class, 'copyToGrades'])->name('assignments.copy');

                Route::get('quizzes', [TeacherQuizController::class, 'index'])->name('quizzes');
                Route::post('quizzes', [TeacherQuizController::class, 'store'])->name('quizzes.store');
                Route::get('quizzes/{quiz}', [TeacherQuizController::class, 'show'])->name('quizzes.show');
                Route::put('quizzes/{quiz}', [TeacherQuizController::class, 'update'])->name('quizzes.update');
                Route::delete('quizzes/{quiz}', [TeacherQuizController::class, 'destroy'])->name('quizzes.destroy');
                Route::post('quizzes/{quiz}/questions', [TeacherQuizController::class, 'storeQuestion'])->name('quizzes.questions.store');
                Route::put('quizzes/{quiz}/questions/{question}', [TeacherQuizController::class, 'updateQuestion'])->name('quizzes.questions.update');
                Route::delete('quizzes/{quiz}/questions/{question}', [TeacherQuizController::class, 'destroyQuestion'])->name('quizzes.questions.destroy');
                Route::put('quizzes/{quiz}/publish', [TeacherQuizController::class, 'publish'])->name('quizzes.publish');
                Route::put('quizzes/{quiz}/answers/{answer}/points', [TeacherQuizController::class, 'gradeEssay'])->name('quizzes.essay');
                Route::post('quizzes/{quiz}/copy-to-grades', [TeacherQuizController::class, 'copyToGrades'])->name('quizzes.copy');

                Route::get('grades', [GradeController::class, 'show'])->name('grades');
                Route::put('grades/components', [GradeController::class, 'components'])->name('grades.components');
                Route::put('grades/scores', [GradeController::class, 'scores'])->name('grades.scores');
                Route::post('grades/import', [GradeController::class, 'import'])->name('grades.import');
                Route::post('grades/finalize', [GradeController::class, 'finalize'])->name('grades.finalize');

                Route::get('teaching-documents', [TeachingDocumentController::class, 'show'])->name('teaching-documents');
                Route::post('teaching-documents', [TeachingDocumentController::class, 'upload'])->name('teaching-documents.upload');
            });
        });
    });
});
