<?php

use App\Enums\Role;
use App\Http\Controllers\AcademicRecordPdfController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CashReportController;
use App\Http\Controllers\ExamCardController;
use App\Http\Controllers\ImportTemplateController;
use App\Http\Controllers\LetterPdfController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PrivateFileController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ScoreTemplateController;
use App\Http\Controllers\TeachingJournalController;
use App\Imports\Importers;
use App\Support\ActiveRole;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

// Logo and app icons are public: the login page and installed-app icons need them.
Route::get('brand/{asset}', [BrandController::class, 'asset'])
    ->whereIn('asset', ['app-logo', 'icon-192', 'icon-512', 'apple-touch-icon'])
    ->name('brand.asset');
Route::get('manifest.webmanifest', [BrandController::class, 'manifest'])->name('brand.manifest');

// The muhadhir area was /ustadz before it was renamed; keep old links working.
Route::get('ustadz/{path?}', fn (?string $path = null) => redirect('/muhadhir'.($path ? '/'.$path : ''), 301))
    ->where('path', '.*');

Route::middleware(['auth', 'active'])->group(function () {
    Route::livewire('password/change', 'pages::auth.change-password')->name('password.change');

    Route::livewire('role', 'pages::auth.select-role')
        ->middleware('password.changed')
        ->name('role.select');
});

Route::middleware('portal')->group(function () {
    Route::get('dashboard', fn () => redirect()->route(ActiveRole::get()->homeRoute()))->name('dashboard');

    Route::livewire('notifications', 'pages::notifications')->name('notifications');
    Route::get('notifications/{id}', NotificationController::class)->whereUuid('id')->name('notifications.open');
    Route::post('push-subscriptions', [PushSubscriptionController::class, 'store'])->name('push.subscribe');
    Route::delete('push-subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');

    Route::prefix('superadmin')->name('superadmin.')->middleware('role:superadmin')->group(function () {
        Route::view('/', 'dashboard', ['role' => Role::Superadmin])->name('dashboard');
        Route::livewire('settings', 'pages::superadmin.settings')->name('settings');
        Route::livewire('identity', 'pages::superadmin.identity')->name('identity');
        Route::livewire('signatures', 'pages::superadmin.signatures')->name('signatures');
        Route::livewire('maintenance', 'pages::superadmin.maintenance')->name('maintenance');
        Route::livewire('letter-types', 'pages::superadmin.letter-types')->name('letter-types');
        Route::get('identity/preview', [AcademicRecordPdfController::class, 'preview'])->name('identity.preview');
        Route::livewire('audit-logs', 'pages::superadmin.audit-logs')->name('audit-logs');
        Route::livewire('users', 'pages::superadmin.users.index')->name('users.index');
        Route::livewire('users/create', 'pages::superadmin.users.form')->name('users.create');
        Route::livewire('users/{user}', 'pages::superadmin.users.form')->name('users.edit');
    });

    Route::prefix('admin')->name('admin.')->middleware('role:admin,superadmin')->group(function () {
        Route::view('/', 'dashboard', ['role' => Role::Admin])->name('dashboard');

        Route::livewire('academic-years', 'pages::admin.academic-years')->name('academic-years');
        Route::livewire('semesters/{semester}', 'pages::admin.semesters.show')->name('semesters.show');
        Route::livewire('study-programs', 'pages::admin.study-programs')->name('study-programs');
        Route::livewire('classes', 'pages::admin.class-groups')->name('class-groups');
        Route::livewire('subjects', 'pages::admin.subjects')->name('subjects');
        Route::livewire('grade-scale', 'pages::admin.grade-scale')->name('grade-scale');
        Route::livewire('timetable', 'pages::admin.timetable')->name('timetable');
        Route::livewire('rooms', 'pages::admin.rooms')->name('rooms');
        Route::livewire('exams', 'pages::admin.exams.index')->name('exams.index');
        Route::livewire('exams/{period}', 'pages::admin.exams.show')->name('exams.show');

        Route::livewire('students', 'pages::admin.students.index')->name('students.index');
        Route::livewire('students/create', 'pages::admin.students.form')->name('students.create');
        Route::livewire('students/{student}', 'pages::admin.students.form')->name('students.edit');
        Route::livewire('students/{student}/record', 'pages::admin.students.record')->name('students.record');

        Route::livewire('teachers', 'pages::admin.teachers.index')->name('teachers.index');
        Route::livewire('teachers/create', 'pages::admin.teachers.form')->name('teachers.create');
        Route::livewire('teachers/{teacher}', 'pages::admin.teachers.form')->name('teachers.edit');

        Route::livewire('guardians', 'pages::admin.guardians.index')->name('guardians.index');
        Route::livewire('guardians/create', 'pages::admin.guardians.form')->name('guardians.create');
        Route::livewire('guardians/{guardian}', 'pages::admin.guardians.form')->name('guardians.edit');

        Route::livewire('supervision', 'pages::admin.supervision')->name('supervision');

        Route::livewire('payments', 'pages::admin.payments')->name('payments');

        Route::livewire('documents', 'pages::admin.documents')->name('documents');
        Route::livewire('imports', 'pages::admin.imports')->name('imports');
        Route::livewire('history', 'pages::admin.history')->name('history');
        Route::livewire('letters', 'pages::admin.letters')->name('letters');
        Route::livewire('incoming-letters', 'pages::admin.incoming-letters')->name('incoming-letters');
        Route::get('imports/template/{type}', ImportTemplateController::class)
            ->whereIn('type', array_keys(Importers::TYPES))
            ->name('imports.template');
    });

    Route::livewire('my-profile', 'pages::profile.biodata')
        ->middleware('role:student,teacher,guardian')
        ->name('profile.mine');

    Route::livewire('my-documents', 'pages::profile.documents')
        ->middleware('role:student,teacher')
        ->name('documents.mine');

    // Personal files (photos, KTP/KK/Ijazah) live on the private disk.
    Route::get('files/{kind}/{id}', PrivateFileController::class)
        ->whereIn('kind', PrivateFileController::KINDS)
        ->whereNumber('id')
        ->name('files.show');

    Route::prefix('finance')->name('finance.')->middleware('role:admin_payment,superadmin')->group(function () {
        Route::livewire('/', 'pages::finance.dashboard')->name('dashboard');
        Route::livewire('fees', 'pages::finance.fees')->name('fees');
        Route::livewire('scholarships', 'pages::finance.scholarships')->name('scholarships');
        Route::livewire('transfers', 'pages::finance.transfers')->name('transfers');
        Route::livewire('cashbook', 'pages::finance.cashbook')->name('cashbook');
        Route::livewire('cash-accounts', 'pages::finance.cash-accounts')->name('cash-accounts');
        Route::get('cash-report', CashReportController::class)->name('cash-report');
        // Previous-year bills, payments, income and expenses (maintenance mode); same page as Admin's.
        Route::livewire('history', 'pages::admin.history')->name('history');
    });

    // Also open to the Petugas Pembayaran; which bills they see is limited by App\Support\BillAccess.
    Route::prefix('finance')->name('finance.')->middleware('role:admin_payment,superadmin,payment_collector')->group(function () {
        Route::livewire('bills', 'pages::finance.bills.index')->name('bills.index');
        Route::livewire('bills/{bill}', 'pages::finance.bills.show')->name('bills.show');
        Route::livewire('handovers', 'pages::finance.handovers')->name('handovers');
    });

    // Where unverified payments are waiting (v4 Step 9): finance and the Admins who verify.
    Route::prefix('finance')->name('finance.')->middleware('role:admin_payment,admin,superadmin')->group(function () {
        Route::livewire('pending', 'pages::finance.pending')->name('pending');
    });

    Route::prefix('petugas')->name('petugas.')->middleware('role:payment_collector')->group(function () {
        Route::view('/', 'dashboard', ['role' => Role::PaymentCollector])->name('dashboard');
    });

    Route::prefix('monitor')->name('monitor.')->middleware('role:academic_monitor,admin,superadmin')->group(function () {
        Route::livewire('/', 'pages::monitor.dashboard')->name('dashboard');
        Route::livewire('activities', 'pages::monitor.activities')->name('activities');
    });

    // Shown to users as "muhadhir"; route names keep the internal "ustadz." prefix.
    Route::prefix('muhadhir')->name('ustadz.')->middleware('role:teacher')->group(function () {
        Route::livewire('/', 'pages::ustadz.dashboard')->name('dashboard');
        Route::livewire('timetable', 'pages::ustadz.timetable')->name('timetable');
        Route::livewire('offerings/{offering}/meetings', 'pages::ustadz.offerings.meetings')->name('offerings.meetings');
        Route::livewire('offerings/{offering}/grades', 'pages::ustadz.offerings.grades')->name('offerings.grades');
        Route::get('offerings/{offering}/grades/template', ScoreTemplateController::class)->name('offerings.grades.template');
        Route::livewire('offerings/{offering}/materials', 'pages::ustadz.offerings.materials')->name('offerings.materials');
        Route::livewire('offerings/{offering}/assignments', 'pages::ustadz.offerings.assignments')->name('offerings.assignments');
        Route::livewire('offerings/{offering}/assignments/{assignment}', 'pages::ustadz.offerings.assignment')->name('offerings.assignment');
        Route::livewire('offerings/{offering}/quizzes', 'pages::ustadz.offerings.quizzes')->name('offerings.quizzes');
        Route::livewire('offerings/{offering}/quizzes/{quiz}', 'pages::ustadz.offerings.quiz')->name('offerings.quiz');
        Route::livewire('offerings/{offering}/forum', 'pages::ustadz.offerings.forum')->name('offerings.forum');
        Route::livewire('offerings/{offering}/documents', 'pages::ustadz.offerings.documents')->name('offerings.documents');

        // Pembimbing akademik: the muhadhir's supervisees and their group forum.
        Route::livewire('supervision', 'pages::ustadz.supervision.index')->name('supervision');
        Route::livewire('supervision/forum', 'pages::ustadz.supervision.forum')->name('supervision.forum');
        Route::livewire('supervision/{student}', 'pages::ustadz.supervision.student')->name('supervision.student');
    });

    Route::prefix('mahasantri')->name('mahasantri.')->middleware('role:student')->group(function () {
        Route::view('/', 'dashboard', ['role' => Role::Student])->name('dashboard');
        Route::livewire('bills', 'pages::mahasantri.bills')->name('bills');
        Route::livewire('khs', 'pages::mahasantri.khs')->name('khs');
        Route::livewire('classes', 'pages::mahasantri.classes')->name('classes');
        Route::livewire('classes/{offering}', 'pages::mahasantri.class')->name('class');
        Route::livewire('quiz-attempts/{attempt}', 'pages::mahasantri.quiz-attempt')->name('quiz-attempt');
        Route::livewire('transcript', 'pages::mahasantri.transcript')->name('transcript');
        Route::livewire('supervision', 'pages::mahasantri.supervision')->name('supervision');
        Route::livewire('exams', 'pages::mahasantri.exams')->name('exams');
        Route::livewire('letters', 'pages::mahasantri.letters')->name('letters');
    });

    Route::prefix('wali')->name('wali.')->middleware('role:guardian')->group(function () {
        Route::view('/', 'dashboard', ['role' => Role::Guardian])->name('dashboard');
        Route::livewire('bills', 'pages::wali.bills')->name('bills');
        Route::livewire('academic', 'pages::wali.academic')->name('academic');
    });

    // KHS and transcript PDFs: the mahasantri, their wali and academic staff.
    Route::get('records/{student}/khs/{semester}', [AcademicRecordPdfController::class, 'khs'])->name('records.khs');
    Route::get('records/{student}/transcript', [AcademicRecordPdfController::class, 'transcript'])->name('records.transcript');

    // Exam card PDF: the mahasantri once the period is published, or academic staff.
    Route::get('exams/{period}/card/{student}', ExamCardController::class)->name('exams.card');

    // Teaching documents (RPS, journal): overview for Admin (review) and the academic monitor (read only).
    Route::livewire('teaching-documents', 'pages::teaching-documents')
        ->middleware('role:admin,superadmin,academic_monitor')
        ->name('teaching-documents');

    // Jurnal mengajar PDF: the muhadhir of the class, Admin, Superadmin, academic monitor.
    Route::get('teaching/{offering}/journal', TeachingJournalController::class)->name('teaching.journal');

    // Outgoing letter PDF: Admin and Superadmin, or the mahasantri it is about.
    Route::get('letters/{letter}/pdf', LetterPdfController::class)->name('letters.pdf');

    // Official receipt once verified; a provisional slip before that.
    Route::get('receipts/{payment}', ReceiptController::class)->name('receipts.show');
});

require __DIR__.'/settings.php';
