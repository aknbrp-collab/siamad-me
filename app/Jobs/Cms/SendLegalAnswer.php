<?php

namespace App\Jobs\Cms;

use App\Models\LegalAnswer;
use App\Support\Cms\CmsClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Send an approved Tanya Hukum answer to the CMS callback (spec §3.4). Sending
 * again is how a correction reaches the CMS; after publication the CMS holds
 * it for its moderator. Retries as for articles: 429 after Retry-After, 5xx
 * and connection errors 1, 5, 15, 60 minutes; other 4xx are recorded.
 */
class SendLegalAnswer implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $answerId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(CmsClient $client): void
    {
        $answer = LegalAnswer::query()->with('question')->find($this->answerId);

        if ($answer === null || $answer->status !== 'approved' || ! CmsClient::enabled()) {
            return;
        }

        $response = $client->send('POST', $answer->question->callback_url, self::payload($answer), $answer->question->cms_question_id);

        if ($response->status() === 429) {
            $wait = (int) $response->header('Retry-After');
            $this->release($wait > 0 ? $wait : 60);

            return;
        }

        if ($response->serverError()) {
            throw new RuntimeException("CMS answered {$response->status()} for question {$answer->question->cms_question_id}.");
        }

        if (! $response->successful()) {
            $answer->update(['error' => trim(__('The website refused it (:status): :message', [
                'status' => $response->status(),
                'message' => (string) ($response->json('message') ?? $response->reason()),
            ]).' '.implode(' ', Arr::flatten((array) $response->json('errors', []))))]);

            return;
        }

        DB::transaction(function () use ($answer, $response) {
            $answer->question->answers()->where('status', 'sent')->update(['status' => 'superseded']);
            $answer->update([
                'status' => 'sent',
                'sent_at' => now(),
                'cms_status' => $response->json('question_status'),
                'correction_pending' => (bool) $response->json('correction_pending', false),
                'error' => null,
            ]);
        });
    }

    public function failed(?Throwable $exception): void
    {
        LegalAnswer::query()->whereKey($this->answerId)->update([
            'error' => __('The website could not be reached after several attempts: :reason', ['reason' => $exception?->getMessage() ?? '—']),
        ]);
    }

    /**
     * The §3.4 body.
     *
     * @return array<string, mixed>
     */
    public static function payload(LegalAnswer $answer): array
    {
        return array_filter([
            'question_id' => $answer->question->cms_question_id,
            'siakad_question_id' => $answer->question->publicId(),
            'answer_html' => $answer->html(),
            'answered_by' => (string) config('services.cms.answered_by'),
            'references' => $answer->references,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
