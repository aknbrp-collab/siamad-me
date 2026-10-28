<?php

namespace App\Concerns;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\EducationLevel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Shared behaviour of student_documents and teacher_documents.
 *
 * @property DocumentType $type
 * @property EducationLevel|null $education_level Ijazah only, like the three below.
 * @property string|null $institution
 * @property int|null $graduation_year
 * @property string|null $degree_title The gelar (muhadhir).
 * @property string|null $file_path
 * @property string|null $original_name
 * @property DocumentStatus $status
 * @property Carbon|null $uploaded_at
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property string|null $rejection_note
 */
trait IsPersonalDocument
{
    use Auditable;

    public function initializeIsPersonalDocument(): void
    {
        $this->mergeFillable(['type', 'education_level', 'institution', 'graduation_year', 'degree_title', 'file_path', 'original_name', 'status', 'uploaded_at', 'verified_by', 'verified_at', 'rejection_note']);

        $this->mergeCasts([
            'type' => DocumentType::class,
            'education_level' => EducationLevel::class,
            'graduation_year' => 'integer',
            'status' => DocumentStatus::class,
            'uploaded_at' => 'datetime',
            'verified_at' => 'datetime',
        ]);
    }

    /**
     * "Ijazah S1", or the type's label for KTP and KK.
     */
    public function title(): string
    {
        return $this->type === DocumentType::Ijazah && $this->education_level !== null
            ? __('Ijazah :level', ['level' => $this->education_level->label()])
            : $this->type->label();
    }

    /**
     * "UIN Sunan Ampel · 2015 · S.Ag.", empty for KTP and KK or before the
     * details are filled.
     */
    public function details(): string
    {
        return implode(' · ', array_filter([$this->institution, (string) $this->graduation_year, $this->degree_title], fn (?string $part) => filled($part)));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
