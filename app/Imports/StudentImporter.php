<?php

namespace App\Imports;

use App\Actions\People\CreateStudent;
use App\Enums\Gender;
use App\Models\StudyProgram;
use App\Support\CreatedAccount;
use Illuminate\Support\Str;

class StudentImporter implements Importer
{
    /**
     * @var array<string, int>|null
     */
    private ?array $programIds = null;

    public function __construct(private readonly CreateStudent $createStudent) {}

    public function label(): string
    {
        return __('Mahasantri');
    }

    public function createsAccounts(): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [
            'nim' => __('Required. Becomes the login ID.'),
            'name' => __('Required.'),
            'study_program_code' => __('Required. Code from the study programs page.'),
            'entry_year' => __('Required, e.g. 2026.'),
            'gender' => __('Required: L or P.'),
            'email' => __('Optional.'),
            'birth_place' => __('Optional.'),
            'birth_date' => __('Optional, YYYY-MM-DD.'),
            'phone' => __('Optional.'),
            'address' => __('Optional.'),
            'father_name' => __('Optional.'),
            'father_is_alive' => __('Optional: ya / tidak.'),
            'mother_name' => __('Optional.'),
            'mother_is_alive' => __('Optional: ya / tidak.'),
            'guardian_name' => __('Optional, if the wali is not a parent.'),
        ];
    }

    public function example(): array
    {
        return [
            'nim' => '2026110001',
            'name' => 'Muhammad Faqih',
            'study_program_code' => StudyProgram::query()->value('code') ?? 'MA',
            'entry_year' => (string) now()->year,
            'gender' => 'L',
            'email' => '',
            'birth_place' => 'Kediri',
            'birth_date' => '2007-03-14',
            'phone' => '081234567890',
            'address' => 'Jl. Pesantren No. 1',
            'father_name' => 'Abdullah',
            'father_is_alive' => 'ya',
            'mother_name' => 'Aminah',
            'mother_is_alive' => 'ya',
            'guardian_name' => '',
        ];
    }

    public function keyColumn(): string
    {
        return 'nim';
    }

    public function toInput(array $row): array
    {
        $this->programIds ??= StudyProgram::query()->pluck('id', 'code')
            ->mapWithKeys(fn (int $id, string $code) => [Str::lower($code) => $id])
            ->all();

        $programCode = Str::lower($row['study_program_code'] ?? '');

        return Importers::nullBlanks([
            'nim' => $row['nim'] ?? '',
            'name' => $row['name'] ?? '',
            'email' => $row['email'] ?? '',
            'study_program_id' => $this->programIds[$programCode] ?? ($programCode === '' ? '' : 0),
            'entry_year' => $row['entry_year'] ?? '',
            'gender' => Gender::fromImport($row['gender'] ?? '')->value ?? ($row['gender'] ?? ''),
            'birth_place' => $row['birth_place'] ?? '',
            'birth_date' => $row['birth_date'] ?? '',
            'phone' => $row['phone'] ?? '',
            'address' => $row['address'] ?? '',
            'father_name' => $row['father_name'] ?? '',
            'father_is_alive' => Importers::yesNo($row['father_is_alive'] ?? null),
            'mother_name' => $row['mother_name'] ?? '',
            'mother_is_alive' => Importers::yesNo($row['mother_is_alive'] ?? null),
            'guardian_name' => $row['guardian_name'] ?? '',
        ]);
    }

    public function validate(array $input): array
    {
        return $this->createStudent->validate($input);
    }

    public function create(array $input): CreatedAccount
    {
        return $this->createStudent->handle($input);
    }
}
