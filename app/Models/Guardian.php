<?php

namespace App\Models;

use App\Concerns\Auditable;
use Database\Factories\GuardianFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $code
 * @property string|null $relationship
 * @property string|null $phone
 * @property-read User $user
 * @property-read Collection<int, Student> $students
 */
#[Fillable(['code', 'relationship', 'phone'])]
class Guardian extends Model
{
    /** @use HasFactory<GuardianFactory> */
    use Auditable, HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Student, $this>
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class)->withTimestamps();
    }
}
