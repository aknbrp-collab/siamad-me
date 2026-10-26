<?php

namespace App\Actions\People;

use App\Models\Student;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class UpdateStudent
{
    use StoresProfilePhoto, StudentRules;

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(Student $student, array $input): void
    {
        $data = Validator::make($input, $this->studentRules($student))->validate();

        DB::transaction(function () use ($student, $data) {
            $student->user->update([
                'username' => $data['nim'],
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
            ]);

            $student->fill(Arr::except($data, ['name', 'email', 'photo']));

            if ($path = $this->storePhoto($data['photo'] ?? null, 'students')) {
                $student->photo_path = $path;
            }

            $student->save();
        });
    }
}
