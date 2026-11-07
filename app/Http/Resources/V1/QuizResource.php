<?php

namespace App\Http\Resources\V1;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A published quiz with the mahasantri's own attempts (loaded for them).
 *
 * @mixin Quiz
 */
class QuizResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Quiz $quiz */
        $quiz = $this->resource;
        $attempts = $quiz->relationLoaded('attempts') ? $quiz->attempts : collect();

        return [
            'id' => $quiz->id,
            'title' => $quiz->title,
            'description' => $quiz->description,
            'opens_at' => $quiz->opens_at->toIso8601String(),
            'closes_at' => $quiz->closes_at->toIso8601String(),
            'duration_minutes' => $quiz->duration_minutes,
            'max_attempts' => $quiz->max_attempts,
            'is_open' => $quiz->isOpen(),
            'my_attempts' => $attempts->map(fn (QuizAttempt $attempt) => [
                'id' => $attempt->id,
                'started_at' => $attempt->started_at->toIso8601String(),
                'deadline_at' => $attempt->deadline_at->toIso8601String(),
                'submitted_at' => $attempt->submitted_at?->toIso8601String(),
                'score' => $attempt->submitted_at === null ? null : $attempt->score,
                'max_score' => $attempt->max_score,
                'needs_manual_grading' => $attempt->needs_manual_grading,
            ])->values()->all(),
        ];
    }
}
