<?php

namespace App\Http\Requests\Api\V1;

use App\Actions\Api\IssueApiToken;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The login body, documented from the rules IssueApiToken applies.
 */
class LoginRequest extends FormRequest
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
        return IssueApiToken::rules();
    }
}
