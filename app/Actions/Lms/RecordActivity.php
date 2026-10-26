<?php

namespace App\Actions\Lms;

use App\Enums\ActivityCategory;
use App\Models\ActivityLog;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RecordActivity
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(Student $student, User $recorder, array $input): ActivityLog
    {
        $data = Validator::make($input, [
            'category' => ['required', Rule::enum(ActivityCategory::class)],
            'happened_on' => ['required', 'date', 'before_or_equal:today'],
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return $student->activityLogs()->create([...$data, 'recorded_by' => $recorder->id]);
    }
}
