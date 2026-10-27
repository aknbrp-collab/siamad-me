<?php

use App\Actions\Teaching\AcademicRecord;
use App\Actions\Teaching\CorrectGrade;
use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Models\AuditLog;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Support\MaintenanceMode;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * @param  list<array<string, string>>  $rows
 */
function historySheet(array $rows): File
{
    $path = tempnam(sys_get_temp_dir(), 'history').'.xlsx';
    $writer = SimpleExcelWriter::create($path);
    $writer->addRows($rows);
    $writer->close();

    return UploadedFile::fake()->createWithContent('history.xlsx', file_get_contents($path));
}

beforeEach(function () {
    $this->superadmin = User::factory()->withRole(Role::Superadmin)->create();
    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->past = Semester::factory()->closed()->create();
    $this->putra = ClassGroup::factory()->create(['name' => 'Semester I Putra', 'level' => 1]);
    $this->putri = ClassGroup::factory()->putri()->create(['name' => 'Semester I Putri', 'level' => 1]);
    $this->ali = Student::factory()->create(['nim' => '2025110001', 'status' => StudentStatus::Graduated]);
    $this->umar = Student::factory()->create(['nim' => '2025110002']);
    $this->aisyah = Student::factory()->female()->create(['nim' => '2025110003']);
    $this->fiqh = Subject::factory()->create(['code' => 'FQH101', 'name' => 'Fiqh Ibadah', 'credits' => 3]);
    $this->nahwu = Subject::factory()->create(['code' => 'NHW101', 'name' => 'Nahwu', 'credits' => 2]);
    $this->teacher = Teacher::factory()->create(['nidm' => '2026010001']);

    $this->actingAs($this->admin)->get(route('admin.history'));
});

function placeFromSheet(object $test): void
{
    Livewire::test('pages::admin.history', ['semester' => $test->past->id])
        ->set('semesterId', $test->past->id)
        ->set('type', 'placements')
        ->set('file', historySheet([
            ['nim*' => '2025110001', 'class*' => 'semester i putra'],
            ['nim*' => '2025110002', 'class*' => 'Semester I Putra'],
            ['nim*' => '2025110003', 'class*' => 'Semester I Putri'],
        ]))
        ->call('import');
}

test('previous-year data can only be imported while maintenance mode is on', function () {
    $this->get(route('admin.history'))->assertOk()->assertSee('data-test="history-locked"', false);

    Livewire::test('pages::admin.history')
        ->set('file', historySheet([['nim*' => '2025110001', 'class*' => 'Semester I Putra']]))
        ->call('check')
        ->assertForbidden();

    expect(ClassEnrollment::query()->count())->toBe(0);
});

test('class placements of a past semester are checked and imported', function () {
    MaintenanceMode::turnOn($this->superadmin, 'Input data 2025/2026');
    ClassEnrollment::create(['semester_id' => $this->past->id, 'class_group_id' => $this->putri->id, 'student_id' => Student::factory()->female()->create(['nim' => '2025110009'])->id, 'class_name' => 'Semester I Putri']);

    $component = Livewire::test('pages::admin.history')
        ->set('semesterId', $this->past->id)
        ->set('file', historySheet([
            ['nim*' => '2025110001', 'class*' => 'semester i putra'],
            ['nim*' => '2025110003', 'class*' => 'Semester I Putra'],
            ['nim*' => '2025119999', 'class*' => 'Semester I Putra'],
            ['nim*' => '2025110002', 'class*' => 'Semester IX'],
            ['nim*' => '2025110009', 'class*' => 'Semester I Putri'],
            ['nim*' => '2025110001', 'class*' => 'Semester I Putra'],
        ]))
        ->call('check')
        ->assertSet('validCount', 1);

    expect(collect($component->get('rowErrors'))->pluck('row')->all())->toBe([3, 4, 5, 6, 7]);

    $component->call('import')->assertSet('importedCount', 1);

    // A graduated mahasantri can still be placed in a past semester.
    expect(ClassEnrollment::query()->where('student_id', $this->ali->id)->sole())
        ->semester_id->toBe($this->past->id)
        ->class_name->toBe('Semester I Putra');
});

test('final grades are imported as final, creating the offering, and count toward IPK', function () {
    MaintenanceMode::turnOn($this->superadmin, 'Input data 2025/2026');
    placeFromSheet($this);

    $component = Livewire::test('pages::admin.history')
        ->set('semesterId', $this->past->id)
        ->set('type', 'grades')
        ->set('file', historySheet([
            ['nim*' => '2025110001', 'subject_code*' => 'FQH101', 'score' => '88.5', 'letter' => '', 'teacher_nidm' => '2026010001'],
            // Same subject and class: the offering from the row above, no NIDM needed.
            ['nim*' => '2025110002', 'subject_code*' => 'FQH101', 'score' => '', 'letter' => 'b+', 'teacher_nidm' => ''],
            ['nim*' => '2025110001', 'subject_code*' => 'NHW101', 'score' => '70', 'letter' => '', 'teacher_nidm' => ''],
            ['nim*' => '2025110003', 'subject_code*' => 'FQH101', 'score' => '101', 'letter' => 'Z', 'teacher_nidm' => '9999'],
            ['nim*' => '2025110001', 'subject_code*' => 'FQH101', 'score' => '90', 'letter' => '', 'teacher_nidm' => ''],
        ]))
        ->call('check')
        ->assertSet('validCount', 2);

    expect(collect($component->get('rowErrors'))->pluck('row')->all())->toBe([4, 5, 6]);

    $component->call('import')->assertSet('importedCount', 2);

    $offering = CourseOffering::query()->sole();
    expect($offering)
        ->semester_id->toBe($this->past->id)
        ->class_group_id->toBe($this->putra->id)
        ->teacher_id->toBe($this->teacher->id)
        ->subject_name->toBe('Fiqh Ibadah')
        ->credits->toBe(3)
        ->and($offering->isFinalized())->toBeTrue();

    $ali = CourseEnrollment::query()->where('student_id', $this->ali->id)->sole();
    expect($ali->final_score)->toBe('88.50')
        ->and($ali->final_letter)->toBe('A')
        ->and($ali->finalized_at)->not->toBeNull()
        ->and($ali->grade_note)->toContain($this->past->label());

    $umar = CourseEnrollment::query()->where('student_id', $this->umar->id)->sole();
    expect($umar->final_score)->toBeNull()->and($umar->final_letter)->toBe('B+');

    $transcript = app(AcademicRecord::class)->transcript($this->ali);
    expect($transcript['credits'])->toBe(3)->and($transcript['ipk'])->toBe(4.0);

    // Grades already final are not overwritten by a second import.
    Livewire::test('pages::admin.history')
        ->set('semesterId', $this->past->id)
        ->set('type', 'grades')
        ->set('file', historySheet([['nim*' => '2025110001', 'subject_code*' => 'FQH101', 'score' => '50', 'letter' => '', 'teacher_nidm' => '']]))
        ->call('check')
        ->assertSet('validCount', 0);
});

test('a single grade of a closed semester is corrected with a reason, in maintenance mode only', function () {
    MaintenanceMode::turnOn($this->superadmin, 'Input data 2025/2026');
    placeFromSheet($this);
    Livewire::test('pages::admin.history')
        ->set('semesterId', $this->past->id)
        ->set('type', 'grades')
        ->set('file', historySheet([['nim*' => '2025110001', 'subject_code*' => 'FQH101', 'score' => '60', 'letter' => '', 'teacher_nidm' => '2026010001']]))
        ->call('import');
    $enrollment = CourseEnrollment::query()->sole();

    $this->get(route('admin.students.record', ['student' => $this->ali, 'semester' => $this->past->id]))
        ->assertOk()
        ->assertSee('data-test="correct-'.$enrollment->id.'"', false);

    $page = Livewire::test('pages::admin.students.record', ['student' => $this->ali])
        ->set('semesterId', $this->past->id)
        ->call('correctGrade', $enrollment->id)
        ->assertSet('correctLetter', '');
    expect((float) $page->get('correctScore'))->toBe(60.0);

    $page->set('correctScore', '82')
        ->set('correctReason', '')
        ->call('saveCorrection')
        ->assertHasErrors('reason')
        ->set('correctReason', 'Salah ketik di arsip; nilai asli 82')
        ->call('saveCorrection')
        ->assertHasNoErrors();

    expect($enrollment->fresh())
        ->final_score->toBe('82.00')
        ->final_letter->toBe('B+')
        ->grade_note->toBe('Salah ketik di arsip; nilai asli 82');

    $log = AuditLog::query()->where('auditable_type', $enrollment->getMorphClass())->where('event', 'updated')->latest('id')->first();
    expect((float) $log->old_values['final_score'])->toBe(60.0)
        ->and($log->maintenance_session_id)->toBe(MaintenanceMode::currentId());

    MaintenanceMode::turnOff($this->superadmin);

    expect(fn () => app(CorrectGrade::class)->handle($enrollment->fresh(), ['score' => 90, 'reason' => 'Coba lagi']))
        ->toThrow(ValidationException::class, 'maintenance mode');
    $this->get(route('admin.students.record', ['student' => $this->ali, 'semester' => $this->past->id]))
        ->assertDontSee('data-test="correct-', false);
});

test('the templates download', function (string $type) {
    MaintenanceMode::turnOn($this->superadmin, 'Input data 2025/2026');

    $response = Livewire::test('pages::admin.history')->set('type', $type)->call('template');
    $response->assertFileDownloaded("template-{$type}.xlsx");
})->with(['placements', 'grades']);
