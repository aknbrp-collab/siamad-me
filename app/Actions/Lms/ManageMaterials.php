<?php

namespace App\Actions\Lms;

use App\Models\CourseOffering;
use App\Models\Material;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ManageMaterials
{
    /**
     * @return list<string>
     */
    public static function fileRules(): array
    {
        return ['nullable', 'file', 'mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,jpg,jpeg,png,zip', 'max:10240'];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function save(CourseOffering $offering, ?Material $material, array $input): Material
    {
        $offering->loadMissing('semester')->semester->assertOpen();

        $data = Validator::make($input, [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:20000'],
            'url' => ['nullable', 'url', 'max:255'],
            'file' => self::fileRules(),
        ])->validate();

        $hasFile = ($data['file'] ?? null) instanceof UploadedFile || $material?->file_path !== null;

        if (blank($data['body'] ?? null) && blank($data['url'] ?? null) && ! $hasFile) {
            throw ValidationException::withMessages(['title' => __('Add some text, a link or a file.')]);
        }

        $attributes = [
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
            'url' => $data['url'] ?? null,
        ];

        if (($data['file'] ?? null) instanceof UploadedFile) {
            $attributes['file_path'] = $this->store($data['file'], "materials/{$offering->id}");
            $attributes['original_name'] = mb_substr($data['file']->getClientOriginalName(), 0, 255);
        }

        if ($material !== null) {
            $material->update($attributes);

            return $material;
        }

        return $offering->materials()->create($attributes);
    }

    public function delete(Material $material): void
    {
        $material->loadMissing('courseOffering.semester')->courseOffering->semester->assertOpen();

        $material->delete();
    }

    private function store(UploadedFile $file, string $folder): string
    {
        $path = $file->store($folder, 'local');

        if ($path === false) {
            throw new RuntimeException('The file could not be stored.');
        }

        return $path;
    }
}
