<?php

namespace App\Imports;

use App\Models\StudyProgram;
use App\Models\Subject;
use App\Support\CreatedAccount;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Subjects (mata kuliah) of the curriculum, validated like the subjects page.
 * Existing codes are refused rather than overwritten.
 */
class SubjectImporter implements Importer
{
    /**
     * @var array<string, int>|null
     */
    private ?array $programIds = null;

    public function label(): string
    {
        return __('Subjects');
    }

    public function createsAccounts(): bool
    {
        return false;
    }

    public function columns(): array
    {
        return [
            'code' => __('Required. Unique subject code, e.g. FQH101.'),
            'name' => __('Required.'),
            'study_program_code' => __('Required. Code from the study programs page.'),
            'credits' => __('Required. SKS, 1 to 12.'),
            'kitab' => __('Optional. The kitab studied.'),
        ];
    }

    public function example(): array
    {
        return [
            'code' => 'FQH101',
            'name' => 'Fiqh Ibadah',
            'study_program_code' => StudyProgram::query()->value('code') ?? 'MA',
            'credits' => '3',
            'kitab' => 'Fathul Qarib',
        ];
    }

    public function keyColumn(): string
    {
        return 'code';
    }

    public function toInput(array $row): array
    {
        $this->programIds ??= StudyProgram::query()->pluck('id', 'code')
            ->mapWithKeys(fn (int $id, string $code) => [Str::lower($code) => $id])
            ->all();

        $programCode = Str::lower($row['study_program_code'] ?? '');

        return Importers::nullBlanks([
            'code' => trim($row['code'] ?? ''),
            'name' => trim($row['name'] ?? ''),
            // Unknown code: 0 fails the "exists" rule with a clear message.
            'study_program_id' => $this->programIds[$programCode] ?? ($programCode === '' ? '' : 0),
            'credits' => $row['credits'] ?? '',
            'kitab' => trim($row['kitab'] ?? ''),
        ]);
    }

    public function validate(array $input): array
    {
        return Validator::make($input, Subject::rules(), [], [
            'study_program_id' => 'study_program_code',
        ])->validate();
    }

    public function create(array $input): ?CreatedAccount
    {
        Subject::create([...$this->validate($input), 'is_active' => true]);

        return null;
    }
}
