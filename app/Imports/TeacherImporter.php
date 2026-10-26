<?php

namespace App\Imports;

use App\Actions\People\CreateTeacher;
use App\Enums\Gender;
use App\Support\CreatedAccount;

class TeacherImporter implements Importer
{
    public function __construct(private readonly CreateTeacher $createTeacher) {}

    public function label(): string
    {
        return __('Ustadz');
    }

    public function columns(): array
    {
        return [
            'nidm' => __('Required. Becomes the login ID.'),
            'name' => __('Required.'),
            'gender' => __('Required: L or P.'),
            'title' => __('Optional, e.g. Lc., M.A.'),
            'nik' => __('Optional, 16 digits.'),
            'email' => __('Optional.'),
            'birth_place' => __('Optional.'),
            'birth_date' => __('Optional, YYYY-MM-DD.'),
            'phone' => __('Optional.'),
            'address' => __('Optional.'),
            'expertise' => __('Optional (keahlian).'),
            'scopus_id' => __('Optional.'),
            'google_scholar_url' => __('Optional, full URL.'),
        ];
    }

    public function example(): array
    {
        return [
            'nidm' => '2026010001',
            'name' => 'Ahmad Zaini',
            'gender' => 'L',
            'title' => 'Lc., M.A.',
            'nik' => '',
            'email' => '',
            'birth_place' => 'Jombang',
            'birth_date' => '1985-08-17',
            'phone' => '081298765432',
            'address' => '',
            'expertise' => 'Fiqh',
            'scopus_id' => '',
            'google_scholar_url' => '',
        ];
    }

    public function keyColumn(): string
    {
        return 'nidm';
    }

    public function toInput(array $row): array
    {
        $input = [];

        foreach (array_keys($this->columns()) as $column) {
            $input[$column] = $row[$column] ?? '';
        }

        $input['gender'] = Gender::fromImport($input['gender'])->value ?? $input['gender'];

        return Importers::nullBlanks($input);
    }

    public function validate(array $input): array
    {
        return $this->createTeacher->validate($input);
    }

    public function create(array $input): CreatedAccount
    {
        return $this->createTeacher->handle($input);
    }
}
