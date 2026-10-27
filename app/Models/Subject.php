<?php

namespace App\Models;

use App\Concerns\Auditable;
use Database\Factories\SubjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\Rule;

/**
 * @property int $id
 * @property int $study_program_id
 * @property string $code
 * @property string $name
 * @property string|null $kitab
 * @property int $credits
 * @property bool $is_active
 * @property-read StudyProgram $studyProgram
 */
#[Fillable(['study_program_id', 'code', 'name', 'kitab', 'credits', 'is_active'])]
class Subject extends Model
{
    /** @use HasFactory<SubjectFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * Matches the column default, so new instances are active before saving.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Validation rules shared by the subjects page and the subject import.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(?int $ignoreId = null): array
    {
        return [
            'study_program_id' => ['required', 'integer', Rule::exists('study_programs', 'id')->whereNull('deleted_at')],
            'code' => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('subjects', 'code')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:255'],
            'kitab' => ['nullable', 'string', 'max:255'],
            'credits' => ['required', 'integer', 'between:1,12'],
        ];
    }

    /**
     * @return BelongsTo<StudyProgram, $this>
     */
    public function studyProgram(): BelongsTo
    {
        return $this->belongsTo(StudyProgram::class)->withTrashed();
    }
}
