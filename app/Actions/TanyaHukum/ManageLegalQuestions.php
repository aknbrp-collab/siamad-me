<?php

namespace App\Actions\TanyaHukum;

use App\Enums\DiscussionKind;
use App\Enums\TeacherStatus;
use App\Jobs\Cms\SendLegalAnswer;
use App\Models\Discussion;
use App\Models\LegalAnswer;
use App\Models\LegalAnswerApproval;
use App\Models\LegalQuestion;
use App\Models\User;
use App\Support\Notify;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tanya Hukum (v5 D30). The CMS forwards a question (spec §4.1); it gets an
 * LPPPK discussion that every mahasantri and muhadhir can join. Any muhadhir
 * drafts the answer and asks for approval; when two other muhadhir approve
 * it, it is sent to the CMS (§3.4). A correction is a new version that needs
 * two new approvals.
 */
class ManageLegalQuestions
{
    public const APPROVALS_NEEDED = 2;

    /**
     * Store a forwarded question, or return the one already received with the
     * same cms_question_id (the CMS retries after timeouts). The second value
     * says whether it is new.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: LegalQuestion, 1: bool}
     */
    public function receive(array $input): array
    {
        $existing = LegalQuestion::query()->where('cms_question_id', (string) ($input['cms_question_id'] ?? ''))->first();

        if ($existing !== null) {
            return [$existing, false];
        }

        $data = Validator::make($input, [
            'cms_question_id' => ['required', 'string', 'max:191'],
            'tracking_code' => ['nullable', 'string', 'max:50'],
            'category' => ['required', Rule::in(LegalQuestion::CATEGORIES)],
            'question' => ['required', 'string', 'max:20000'],
            'asker' => ['nullable', 'array'],
            'asker.name' => ['nullable', 'string', 'max:255'],
            'asker.city' => ['nullable', 'string', 'max:255'],
            'asker.anonymous' => ['nullable', 'boolean'],
            'submitted_at' => ['nullable', 'date'],
            'moderator_note' => ['nullable', 'string', 'max:5000'],
            'callback_url' => ['required', 'url:https,http', 'max:2048', function (string $attribute, mixed $value, \Closure $fail) {
                // Answers only ever go back to the CMS itself.
                if (parse_url((string) $value, PHP_URL_HOST) !== parse_url((string) config('services.cms.url'), PHP_URL_HOST)) {
                    $fail('The callback_url must point to the CMS.');
                }
            }],
        ])->validate();

        $anonymous = (bool) ($data['asker']['anonymous'] ?? false);

        try {
            $question = DB::transaction(function () use ($data, $anonymous) {
                $question = LegalQuestion::create([
                    'cms_question_id' => $data['cms_question_id'],
                    'tracking_code' => $data['tracking_code'] ?? null,
                    'category' => $data['category'],
                    'question' => trim($data['question']),
                    'asker_name' => $anonymous ? null : ($data['asker']['name'] ?? null),
                    'asker_city' => $data['asker']['city'] ?? null,
                    'anonymous' => $anonymous,
                    'submitted_at' => $data['submitted_at'] ?? null,
                    'moderator_note' => $data['moderator_note'] ?? null,
                    'callback_url' => $data['callback_url'],
                ]);

                $discussion = Discussion::create([
                    'kind' => DiscussionKind::TanyaHukum,
                    'name' => __('Tanya Hukum :code', ['code' => $question->tracking_code ?? $question->publicId()]),
                    'description' => Str::limit($question->question, 150),
                ]);
                $question->update(['discussion_id' => $discussion->id]);

                return $question;
            });
        } catch (UniqueConstraintViolationException) {
            // The same question arrived twice at the same moment.
            return [LegalQuestion::query()->where('cms_question_id', $data['cms_question_id'])->firstOrFail(), false];
        }

        Notify::send(
            $this->muhadhir(),
            'tanya-hukum',
            __('New Tanya Hukum questions'),
            Str::limit($question->question, 120),
            route('tanya-hukum.show', $question),
            'scale',
            null,
            'tanya-hukum:new',
            __(':count questions wait for an answer', ['count' => ':count']),
        );

        return [$question, true];
    }

    /**
     * Write or change the draft of the next answer version (any muhadhir).
     */
    public function saveDraft(LegalQuestion $question, User $by, string $answer, ?string $references): LegalAnswer
    {
        $this->ensureMuhadhir($by);

        $data = Validator::make(['answer' => trim($answer), 'references' => trim((string) $references)], [
            'answer' => ['required', 'string', 'min:20', 'max:100000'],
            'references' => ['nullable', 'string', 'max:5000'],
        ], [], ['answer' => __('answer'), 'references' => __('references')])->validate();

        return DB::transaction(function () use ($question, $by, $data) {
            $draft = $this->open($question);

            if ($draft !== null && $draft->status !== 'draft') {
                $this->fail('answer', __('This answer waits for approval; send it back to draft to change it.'));
            }

            $fields = ['answer_md' => $data['answer'], 'references' => $data['references'] !== '' ? $data['references'] : null];

            if ($draft === null) {
                return LegalAnswer::create([
                    ...$fields,
                    'legal_question_id' => $question->id,
                    'version' => (int) $question->answers()->max('version') + 1,
                    'drafted_by' => $by->id,
                ]);
            }

            // Whoever saves it last is the drafter, and cannot approve it.
            $draft->update([...$fields, 'drafted_by' => $by->id]);

            return $draft;
        });
    }

    public function submit(LegalAnswer $answer, User $by): void
    {
        $this->ensureMuhadhir($by);

        if ($answer->status !== 'draft') {
            $this->fail('answer', __('Only a draft can be sent for approval.'));
        }

        $answer->update(['status' => 'awaiting', 'submitted_at' => now()]);

        Notify::send(
            $this->muhadhir()->reject(fn (User $user) => $user->id === $answer->drafted_by),
            'tanya-hukum',
            __('A Tanya Hukum answer waits for approval'),
            Str::limit($answer->question->question, 120),
            route('tanya-hukum.show', $answer->legal_question_id),
            'scale',
            $by,
            'tanya-hukum:approve',
            __(':count answers wait for approval', ['count' => ':count']),
        );
    }

    /**
     * Back to draft for changes; the approvals given so far are dropped.
     */
    public function reopen(LegalAnswer $answer, User $by): void
    {
        $this->ensureMuhadhir($by);

        if ($answer->status !== 'awaiting') {
            $this->fail('answer', __('Only an answer waiting for approval can go back to draft.'));
        }

        DB::transaction(function () use ($answer) {
            $answer->approvals()->delete();
            $answer->update(['status' => 'draft', 'submitted_at' => null]);
        });
    }

    /**
     * Approve (a muhadhir other than the drafter). The second approval sends it.
     */
    public function approve(LegalAnswer $answer, User $by): void
    {
        $this->ensureMuhadhir($by);

        if ($by->id === $answer->drafted_by) {
            $this->fail('answer', __('The drafter cannot approve their own answer.'));
        }

        $send = DB::transaction(function () use ($answer, $by) {
            $answer = LegalAnswer::query()->whereKey($answer->id)->lockForUpdate()->firstOrFail();

            if ($answer->status !== 'awaiting') {
                $this->fail('answer', __('This answer is not waiting for approval.'));
            }

            if ($answer->approvals()->where('approver_id', $by->id)->exists()) {
                $this->fail('answer', __('You already approved this answer.'));
            }

            LegalAnswerApproval::create(['legal_answer_id' => $answer->id, 'approver_id' => $by->id]);

            if ($answer->approvals()->count() < self::APPROVALS_NEEDED) {
                return false;
            }

            $answer->update(['status' => 'approved']);

            return true;
        });

        if ($send) {
            SendLegalAnswer::dispatch($answer->id)->afterCommit();
        }
    }

    /**
     * Start a correction of the answer that was sent: a new draft from its text.
     */
    public function startCorrection(LegalQuestion $question, User $by): LegalAnswer
    {
        $this->ensureMuhadhir($by);

        if ($this->open($question) !== null) {
            $this->fail('answer', __('A new version is already being prepared.'));
        }

        $sent = $question->answers()->where('status', 'sent')->orderByDesc('version')->first();

        if ($sent === null) {
            $this->fail('answer', __('Nothing was sent yet; change the draft instead.'));
        }

        return LegalAnswer::create([
            'legal_question_id' => $question->id,
            'version' => (int) $question->answers()->max('version') + 1,
            'answer_md' => $sent->answer_md,
            'references' => $sent->references,
            'drafted_by' => $by->id,
        ]);
    }

    /**
     * The version being prepared (draft, awaiting or approved but not sent), if any.
     */
    public function open(LegalQuestion $question): ?LegalAnswer
    {
        return $question->answers()->whereIn('status', ['draft', 'awaiting', 'approved'])->orderByDesc('version')->first();
    }

    /**
     * @return Collection<int, User>
     */
    private function muhadhir(): Collection
    {
        return User::query()->where('is_active', true)->whereHas('teacher', fn ($query) => $query->where('status', TeacherStatus::Active))->get();
    }

    private function ensureMuhadhir(User $user): void
    {
        $user->loadMissing('teacher');

        if ($user->teacher === null || $user->teacher->status !== TeacherStatus::Active) {
            $this->fail('answer', __('Only muhadhir write and approve Tanya Hukum answers.'));
        }
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
