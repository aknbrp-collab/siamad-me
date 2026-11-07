<?php

namespace App\Http\Resources\V1;

use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * An attempt with its questions in the mahasantri's order and the answers
 * saved so far. Correct answers are never sent; after submitting, only the
 * score (as on the web).
 *
 * @mixin QuizAttempt
 */
class QuizAttemptResource extends JsonResource
{
    /**
     * @param  Collection<int, QuizQuestion>  $questions  in the attempt's order, with options
     */
    public function __construct(QuizAttempt $attempt, private readonly Collection $questions)
    {
        parent::__construct($attempt);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var QuizAttempt $attempt */
        $attempt = $this->resource;
        $answers = $attempt->answers->keyBy('quiz_question_id');

        return [
            'id' => $attempt->id,
            'quiz' => ['id' => $attempt->quiz->id, 'title' => $attempt->quiz->title],
            'started_at' => $attempt->started_at->toIso8601String(),
            /** Answers are submitted automatically at this time. */
            'deadline_at' => $attempt->deadline_at->toIso8601String(),
            'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            'score' => $attempt->submitted_at === null ? null : $attempt->score,
            'max_score' => $attempt->max_score,
            'needs_manual_grading' => $attempt->needs_manual_grading,
            'questions' => $this->questions->map(function (QuizQuestion $question) use ($answers) {
                /** @var QuizAnswer|null $answer */
                $answer = $answers->get($question->id);

                return [
                    'id' => $question->id,
                    /** single_choice | multiple_choice | true_false | essay */
                    'type' => $question->type->value,
                    'body' => $question->body,
                    'points' => $question->points,
                    'options' => $question->options->map(fn (QuizOption $option) => ['id' => $option->id, 'body' => $option->body])->values()->all(),
                    /** Chosen option ids (choice questions) or the text (essay). */
                    'answer' => $question->type->hasOptions() ? ($answer->selected_option_ids ?? []) : $answer?->text,
                ];
            })->values()->all(),
        ];
    }
}
