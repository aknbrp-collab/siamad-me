<?php

namespace App\Actions\People;

use App\Models\Teacher;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class UpdateTeacher
{
    use StoresProfilePhoto, TeacherRules;

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(Teacher $teacher, array $input): void
    {
        $data = Validator::make($input, $this->teacherRules($teacher))->validate();

        DB::transaction(function () use ($teacher, $data) {
            $teacher->user->update([
                'username' => $data['nidm'],
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
            ]);

            $teacher->fill(Arr::except($data, ['name', 'email', 'photo']));

            if ($path = $this->storePhoto($data['photo'] ?? null, 'teachers')) {
                $teacher->photo_path = $path;
            }

            $teacher->save();
        });
    }
}
