<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\FeedbackCategory;
use App\Enums\FeedbackStatus;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A review or suggestion (Kritik & saran, v6 D34). The sender is always
 * recorded; when anonymous, only Superadmin sees who sent it.
 *
 * @property int $id
 * @property int $user_id
 * @property Role $role
 * @property FeedbackCategory $category
 * @property string $subject
 * @property string $message
 * @property bool $anonymous
 * @property FeedbackStatus $status
 * @property string|null $reply
 * @property int|null $replied_by
 * @property Carbon|null $replied_at
 * @property Carbon $created_at
 * @property-read User $user
 * @property-read User|null $replier
 */
#[Fillable(['user_id', 'role', 'category', 'subject', 'message', 'anonymous', 'status', 'reply', 'replied_by', 'replied_at'])]
class Feedback extends Model
{
    use Auditable;

    protected $table = 'feedback';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'new',
        'anonymous' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'category' => FeedbackCategory::class,
            'status' => FeedbackStatus::class,
            'anonymous' => 'boolean',
            'replied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function replier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by');
    }

    /**
     * Whether the reader may see who sent it: always, unless anonymous; then Superadmin only.
     */
    public function senderVisibleTo(?User $reader): bool
    {
        return ! $this->anonymous || ($reader?->hasRole(Role::Superadmin) ?? false);
    }

    /**
     * The sender as the reader may see them.
     */
    public function senderLabel(?User $reader): string
    {
        if (! $this->senderVisibleTo($reader)) {
            return __('Anonymous');
        }

        return $this->anonymous ? $this->user->name.' ('.__('anonymous').')' : $this->user->name;
    }
}
