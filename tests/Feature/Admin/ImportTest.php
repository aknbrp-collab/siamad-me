<?php

use App\Enums\Gender;
use App\Enums\Role;
use App\Models\Student;
use App\Models\StudyProgram;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * @param  list<array<string, string>>  $rows
 */
function spreadsheet(array $rows, string $extension = 'xlsx'): File
{
    $path = tempnam(sys_get_temp_dir(), 'import').'.'.$extension;
    $writer = SimpleExcelWriter::create($path);
    $writer->addRows($rows);
    $writer->close();

    return UploadedFile::fake()->createWithContent("data.{$extension}", file_get_contents($path));
}

beforeEach(function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    StudyProgram::factory()->create(['code' => 'MA']);
});

test('the template downloads with the expected headers', function () {
    $response = $this->get(route('admin.imports.template', 'students'))->assertOk();

    $headers = SimpleExcelReader::create($response->getFile()->getPathname(), 'xlsx')->getHeaders();

    expect($headers)->toContain('nim*', 'name*', 'study_program_code*', 'father_is_alive');
});

test('students are checked row by row and only valid rows are imported', function () {
    User::factory()->create(['username' => '2026110003']);

    $file = spreadsheet([
        ['nim*' => '2026110001', 'name*' => 'Ahmad', 'study_program_code*' => 'ma', 'entry_year*' => '2026', 'gender*' => 'L', 'father_is_alive' => 'ya', 'birth_date' => '2007-01-31'],
        ['nim*' => '2026110002', 'name*' => 'Aisyah', 'study_program_code*' => 'MA', 'entry_year*' => '2026', 'gender*' => 'P', 'father_is_alive' => 'tidak', 'birth_date' => ''],
        ['nim*' => '2026110003', 'name*' => 'Taken', 'study_program_code*' => 'MA', 'entry_year*' => '2026', 'gender*' => 'L', 'father_is_alive' => '', 'birth_date' => ''],
        ['nim*' => '2026110001', 'name*' => 'Duplicate', 'study_program_code*' => 'MA', 'entry_year*' => '2026', 'gender*' => 'L', 'father_is_alive' => '', 'birth_date' => ''],
        ['nim*' => '2026110005', 'name*' => 'Bad program', 'study_program_code*' => 'XX', 'entry_year*' => '2026', 'gender*' => 'X', 'father_is_alive' => '', 'birth_date' => ''],
    ]);

    $this->get(route('admin.imports'))->assertOk();

    $component = Livewire::test('pages::admin.imports')
        ->set('type', 'students')
        ->set('file', $file)
        ->call('check')
        ->assertHasNoErrors()
        ->assertSet('validCount', 2);

    expect(collect($component->get('rowErrors'))->pluck('row')->all())->toBe([4, 5, 6]);

    $component->call('import')->assertFileDownloaded();

    expect(Student::query()->pluck('nim')->sort()->values()->all())->toBe(['2026110001', '2026110002'])
        ->and(Student::query()->where('nim', '2026110001')->value('father_is_alive'))->toBeTrue()
        ->and(Student::query()->where('nim', '2026110002')->value('gender'))->toBe(Gender::Female)
        ->and($component->get('importedCount'))->toBe(2);
});

test('the credentials sheet holds working temporary passwords', function () {
    $component = Livewire::test('pages::admin.imports')
        ->set('type', 'teachers')
        ->set('file', spreadsheet([['nidm*' => '2026010001', 'name*' => 'Ustadz Ahmad', 'gender*' => 'L']], 'csv'))
        ->call('check')
        ->assertSet('validCount', 1);

    $response = $component->call('import');

    $download = $response->effects['download'] ?? null;
    expect($download)->not->toBeNull();

    $path = tempnam(sys_get_temp_dir(), 'cred').'.xlsx';
    file_put_contents($path, base64_decode($download['content']));
    $row = SimpleExcelReader::create($path)->getRows()->first();

    $teacher = Teacher::query()->with('user')->sole();
    expect(array_values($row)[1])->toBe('2026010001')
        ->and(Hash::check(array_values($row)[2], $teacher->user->password))->toBeTrue()
        ->and($teacher->user->must_change_password)->toBeTrue();
});

test('an empty file is refused', function () {
    Livewire::test('pages::admin.imports')
        ->set('file', spreadsheet([]))
        ->call('check')
        ->assertHasErrors('file');
});
