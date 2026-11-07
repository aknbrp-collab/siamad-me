<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Upload;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Every field a profile may have. Which ones apply depends on the role
 * (`editable` of GET /profile); UpdateOwnProfile validates them in full and
 * ignores the rest.
 */
class ProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'birth_place' => ['sometimes', 'nullable', 'string', 'max:100'],
            'birth_date' => ['sometimes', 'nullable', 'date'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            /** JPG or PNG, sent as multipart. */
            'photo' => ['sometimes', 'nullable', 'image', Upload::max()],
            'father_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'father_is_alive' => ['sometimes', 'nullable', 'boolean'],
            'mother_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mother_is_alive' => ['sometimes', 'nullable', 'boolean'],
            'guardian_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'nik' => ['sometimes', 'nullable', 'string', 'max:20'],
            'title' => ['sometimes', 'nullable', 'string', 'max:100'],
            'gender' => ['sometimes', 'nullable', 'string'],
            'expertise' => ['sometimes', 'nullable', 'string', 'max:255'],
            'scopus_id' => ['sometimes', 'nullable', 'string', 'max:50'],
            'google_scholar_url' => ['sometimes', 'nullable', 'string', 'max:255'],
            'relationship' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}
