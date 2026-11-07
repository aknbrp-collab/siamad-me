<?php

namespace App\Actions\Finance;

use App\Enums\FeeFrequency;
use App\Enums\ScholarshipType;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Scholarship;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Fee types, their rates per academic year (optionally per class or entry
 * year) and scholarship programmes. Bills already made keep their amounts.
 * Used by the finance pages and the API (v6 D37).
 */
class ManageFees
{
    /**
     * @param  array<string, mixed>  $input  name, frequency, is_active
     */
    public function saveFeeType(?FeeType $feeType, array $input): FeeType
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:100', Rule::unique('fee_types', 'name')->ignore($feeType?->id)],
            'frequency' => ['required', Rule::enum(FeeFrequency::class)],
            'is_active' => ['boolean'],
        ])->validate();

        return FeeType::query()->updateOrCreate(['id' => $feeType?->id], [
            'name' => $data['name'],
            'frequency' => $data['frequency'],
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
    }

    /**
     * One rate per academic year, fee, class and entry year.
     *
     * @param  array<string, mixed>  $input  academic_year_id, fee_type_id, class_group_id, entry_year, amount
     */
    public function saveRate(?FeeRate $rate, array $input): FeeRate
    {
        $data = Validator::make($input, [
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')],
            'fee_type_id' => ['required', 'integer', Rule::exists('fee_types', 'id')],
            'class_group_id' => ['nullable', 'integer', Rule::exists('class_groups', 'id')],
            'entry_year' => ['nullable', 'integer', 'between:1990,2100'],
            'amount' => ['required', 'integer', 'min:0', 'max:1000000000'],
        ])->validate();

        $duplicate = FeeRate::query()
            ->where('academic_year_id', $data['academic_year_id'])
            ->where('fee_type_id', $data['fee_type_id'])
            ->where('class_group_id', $data['class_group_id'] ?? null)
            ->where('entry_year', $data['entry_year'] ?? null)
            ->when($rate !== null, fn ($query) => $query->whereKeyNot($rate?->id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['amount' => __('A rate for this fee, class and entry year already exists in this academic year.')]);
        }

        return FeeRate::query()->updateOrCreate(['id' => $rate?->id], [
            'academic_year_id' => $data['academic_year_id'],
            'fee_type_id' => $data['fee_type_id'],
            'class_group_id' => $data['class_group_id'] ?? null,
            'entry_year' => $data['entry_year'] ?? null,
            'amount' => $data['amount'],
        ]);
    }

    public function deleteRate(FeeRate $rate): void
    {
        $rate->delete();
    }

    /**
     * @param  array<string, mixed>  $input  academic_year_id, name, funding_source, type, value, quota, is_active
     */
    public function saveScholarship(?Scholarship $scholarship, array $input): Scholarship
    {
        $type = (string) ($input['type'] ?? '');

        $data = Validator::make($input, [
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'funding_source' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(ScholarshipType::class)],
            'value' => [
                Rule::requiredIf($type !== ScholarshipType::Full->value), 'nullable', 'integer', 'min:1',
                $type === ScholarshipType::Percent->value ? 'max:100' : 'max:1000000000',
            ],
            'quota' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
        ])->validate();

        return Scholarship::query()->updateOrCreate(['id' => $scholarship?->id], [
            'academic_year_id' => $data['academic_year_id'],
            'name' => $data['name'],
            'funding_source' => $data['funding_source'],
            'type' => $data['type'],
            'value' => $data['type'] === ScholarshipType::Full->value ? 0 : $data['value'],
            'quota' => $data['quota'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
    }
}
