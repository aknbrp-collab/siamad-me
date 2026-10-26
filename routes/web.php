<?php

use App\Enums\Role;
use App\Http\Controllers\AcademicRecordPdfController;
use App\Http\Controllers\ImportTemplateController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PrivateFileController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ScoreTemplateController;
use App\Imports\Importers;
use App\Support\ActiveRole;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

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

    Route::prefix('superadmin')->name('superadmin.')->middleware('role:superadmin')->group(function () {
        Route::view('/', 'dashboard', ['role' => Role::Superadmin])->name('dashboard');
        Route::livewire('settings', 'pages::superadmin.settings')->name('settings');
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
        Route::livewire('bills', 'pages::finance.bills.index')->name('bills.index');
        Route::livewire('bills/{bill}', 'pages::finance.bills.show')->name('bills.show');
        Route::livewire('handovers', 'pages::finance.handovers')->name('handovers');
        Route::livewire('transfers', 'pages::finance.transfers')->name('transfers');
    });

    Route::prefix('monitor')->name('monitor.')->middleware('role:academic_monitor,admin,superadmin')->group(function () {
        Route::livewire('/', 'pages::monitor.dashboard')->name('dashboard');
        Route::livewire('activities', 'pages::monitor.activities')->name('activities');
    });

    // Shown to users as "muhadhir"; route names keep the internal "ustadz." prefix.
    Route::prefix('muhadhir')->name('ustadz.')->middleware('role:teacher')->group(function () {
        Route::livewire('/', 'pages::ustadz.dashboard')->name('dashboard');
        Route::livewire('offerings/{offering}/meetings', 'pages::ustadz.offerings.meetings')->name('offerings.meetings');
        Route::livewire('offerings/{offering}/grades', 'pages::ustadz.offerings.grades')->name('offerings.grades');
        Route::get('offerings/{offering}/grades/template', ScoreTemplateController::class)->name('offerings.grades.template');
        Route::livewire('offerings/{offering}/materials', 'pages::ustadz.offerings.materials')->name('offerings.materials');
        Route::livewire('offerings/{offering}/assignments', 'pages::ustadz.offerings.assignments')->name('offerings.assignments');
        Route::livewire('offerings/{offering}/assignments/{assignment}', 'pages::ustadz.offerings.assignment')->name('offerings.assignment');
        Route::livewire('offerings/{offering}/quizzes', 'pages::ustadz.offerings.quizzes')->name('offerings.quizzes');
        Route::livewire('offerings/{offering}/quizzes/{quiz}', 'pages::ustadz.offerings.quiz')->name('offerings.quiz');
        Route::livewire('offerings/{offering}/forum', 'pages::ustadz.offerings.forum')->name('offerings.forum');

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
    });

    Route::prefix('wali')->name('wali.')->middleware('role:guardian')->group(function () {
        Route::view('/', 'dashboard', ['role' => Role::Guardian])->name('dashboard');
        Route::livewire('bills', 'pages::wali.bills')->name('bills');
        Route::livewire('academic', 'pages::wali.academic')->name('academic');
    });

    // KHS and transcript PDFs: the mahasantri, their wali and academic staff.
    Route::get('records/{student}/khs/{semester}', [AcademicRecordPdfController::class, 'khs'])->name('records.khs');
    Route::get('records/{student}/transcript', [AcademicRecordPdfController::class, 'transcript'])->name('records.transcript');

    // Official receipt once verified; a provisional slip before that.
    Route::get('receipts/{payment}', ReceiptController::class)->name('receipts.show');
});

require __DIR__.'/settings.php';
