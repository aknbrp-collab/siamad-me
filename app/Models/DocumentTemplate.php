<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a managed template (v4 D24). The latest version of a slot
 * is the one in use; without a file it means the built-in default.
 *
 * @property int $id
 * @property string $key
 * @property int $version
 * @property string|null $file_path
 * @property string|null $original_name
 * @property int $uploaded_by
 * @property-read User $uploader
 */
#[Fillable(['key', 'version', 'file_path', 'original_name', 'uploaded_by'])]
class DocumentTemplate extends Model
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
