<?php

namespace App\Actions\Feedback;

use App\Enums\FeedbackCategory;
use App\Enums\FeedbackStatus;
use App\Enums\Role;
use App\Models\Feedback;
use App\Models\User;
use App\Support\Notify;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Kritik & saran (v6 D34): any user sends a review or suggestion; Admin and
 * Superadmin read it, reply (the sender is notified) and move it through
 * Baru → Diproses → Selesai. The sender is always recorded; an anonymous
 * message hides the name from the Admin, and only Superadmin sees it.
 */
class ManageFeedback
{
    /** Messages one person may send in 24 hours. */
    public const DAILY_LIMIT = 10;

    /**
     * @param  array<string, mixed>  $input
     */
    public function send(User $sender, Role $role, array $input): Feedback
    {
        $data = Validator::make([
            ...$input,
            'subject' => trim((string) ($input['subject'] ?? '')),
            'message' => trim((string) ($input['message'] ?? '')),
        ], [
            'category' => ['required', Rule::enum(FeedbackCategory::class)],
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'anonymous' => ['boolean'],
        ], [], [
            'category' => __('category'), 'subject' => __('subject'), 'message' => __('message'),
        ])->validate();

        if (Feedback::query()->where('user_id', $sender->id)->where('created_at', '>=', now()->subDay())->count() >= self::DAILY_LIMIT) {
            throw ValidationException::withMessages(['message' => __('You have sent many messages today. Please try again tomorrow.')]);
        }

        $feedback = Feedback::create([
            'user_id' => $sender->id,
            'role' => $role,
            'category' => $data['category'],
            'subject' => $data['subject'],
            'message' => $data['message'],
            'anonymous' => (bool) ($data['anonymous'] ?? false),
        ]);

        Notify::send(
            self::handlers(),
            'feedback',
            __('New feedback'),
            $feedback->category->label().': '.Str::limit($feedback->subject, 100),
            route('feedback.inbox', ['open' => $feedback->id]),
            'chat-bubble-bottom-center-text',
            $sender,
            'feedback:new',
            __(':count new feedback messages', ['count' => ':count']),
        );

        return $feedback;
    }

    /**
     * Reply to the sender (or change the reply); the status moves with it.
     */
    public function reply(Feedback $feedback, User $by, string $reply, FeedbackStatus $status = FeedbackStatus::Done): void
    {
        $this->ensureHandler($by);

        $reply = trim($reply);
        Validator::make(['reply' => $reply], ['reply' => ['required', 'string', 'max:5000']], [], ['reply' => __('reply')])->validate();

        $feedback->update([
            'reply' => $reply,
            'replied_by' => $by->id,
            'replied_at' => now(),
            'status' => $status,
        ]);

        Notify::send(
            [$feedback->user],
            'feedback',
            __('Your feedback was answered'),
            Str::limit($feedback->subject, 100),
            route('feedback'),
            'chat-bubble-bottom-center-text',
            $by,
        );
    }

    public function setStatus(Feedback $feedback, User $by, FeedbackStatus $status): void
    {
        $this->ensureHandler($by);

        if ($feedback->status === $status) {
            return;
        }

        $feedback->update(['status' => $status]);

        if ($status !== FeedbackStatus::New) {
            Notify::send(
                [$feedback->user],
                'feedback',
                __('Your feedback: :status', ['status' => $status->label()]),
                Str::limit($feedback->subject, 100),
                route('feedback'),
                'chat-bubble-bottom-center-text',
                $by,
            );
        }
    }

    public static function handles(?User $user): bool
    {
        return $user?->hasRole(Role::Admin, Role::Superadmin) ?? false;
    }

    /**
     * Messages nobody has picked up yet, for the sidebar badge.
     */
    public static function newCount(): int
    {
        return Feedback::query()->where('status', FeedbackStatus::New)->count();
    }

    /**
     * @return Collection<int, User>
     */
    private static function handlers(): Collection
    {
        return User::query()->whereHas('roles', fn ($query) => $query->whereIn('role', [Role::Admin, Role::Superadmin]))->get();
    }

    private function ensureHandler(User $user): void
    {
        if (! self::handles($user)) {
            throw ValidationException::withMessages(['reply' => __('Only Admin and Superadmin handle feedback.')]);
        }
    }
}
