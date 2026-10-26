<?php

use App\Actions\Lms\ManageQuizzes;
use App\Enums\QuestionType;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Quiz')] class extends Component {
    public QuizAttempt $attempt;

    /** @var array<int|string, mixed> question id => option id, option ids or text */
    public array $answers = [];

    public function mount(QuizAttempt $attempt): void
    {
        $attempt->load(['quiz.courseOffering', 'courseEnrollment.student', 'answers']);
        abort_unless($attempt->courseEnrollment->student->user_id === auth()->id(), 403);

        $this->attempt = $attempt;
        $this->expireIfDue();

        foreach ($attempt->answers as $answer) {
            $this->answers[$answer->quiz_question_id] = $answer->text ?? ($answer->selected_option_ids ?? []);
        }

        // Single-choice radios bind to one id.
        foreach ($this->questions as $question) {
            if (in_array($question->type, [QuestionType::SingleChoice, QuestionType::TrueFalse], true) && is_array($this->answers[$question->id] ?? null)) {
                $this->answers[$question->id] = $this->answers[$question->id][0] ?? null;
            }
        }
    }

    /**
     * Questions in this attempt's own order.
     *
     * @return Collection<int, QuizQuestion>
     */
    #[Computed]
    public function questions(): Collection
    {
        $questions = QuizQuestion::query()->with('options')->whereIn('id', $this->attempt->question_order)->get()->keyBy('id');

        return collect($this->attempt->question_order)->map(fn (int $id) => $questions->get($id))->filter()->values();
    }

    /**
     * Save answers as they change. Livewire passes the changed key
     * ("12" or "12.0"), or null when a checkbox group replaces the whole array.
     */
    public function updatedAnswers(mixed $value, ?string $key, ManageQuizzes $quizzes): void
    {
        $this->resetErrorBag();

        if ($this->expireIfDue()) {
            return;
        }

        $questionIds = $key === null
            ? array_map('intval', array_keys($this->answers))
            : [(int) explode('.', $key)[0]];

        foreach ($questionIds as $questionId) {
            $question = $this->questions->firstWhere('id', $questionId);
            abort_if($question === null, 404);

            $quizzes->answer($this->attempt, $question, $this->answers[$questionId] ?? null);
        }
    }

    public function submit(ManageQuizzes $quizzes): void
    {
        $quizzes->submit($this->attempt);

        $this->attempt->refresh();
        Flux::toast(variant: 'success', text: __('Quiz submitted.'));
    }

    /**
     * Time is up: hand in what was saved (the server decides, not the timer).
     */
    private function expireIfDue(): bool
    {
        if ($this->attempt->submitted_at === null && now()->greaterThanOrEqualTo($this->attempt->deadline_at)) {
            app(ManageQuizzes::class)->submit($this->attempt);
            $this->attempt->refresh();
        }

        return $this->attempt->submitted_at !== null;
    }
}; ?>

<section class="w-full max-w-3xl">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ $attempt->quiz->title }}</flux:heading>
            <flux:subheading>{{ $attempt->quiz->courseOffering->subject_name }}</flux:subheading>
        </div>
        <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('mahasantri.class', ['offering' => $attempt->quiz->course_offering_id, 'tab' => 'quizzes'])" wire:navigate>{{ __('Back to class') }}</flux:button>
    </div>

    @if ($attempt->submitted_at)
        <flux:card class="space-y-2 text-center">
            <flux:heading size="lg">{{ __('Submitted') }}</flux:heading>
            @if ($attempt->needs_manual_grading)
                <flux:text>{{ __('Your essay answers are waiting for the ustadz. Score so far: :score / :max.', ['score' => $attempt->score, 'max' => $attempt->max_score]) }}</flux:text>
            @else
                <div class="text-4xl font-semibold tabular-nums">{{ $attempt->score }} / {{ $attempt->max_score }}</div>
            @endif
        </flux:card>
    @else
        <div
            x-data="{
                deadline: {{ $attempt->deadline_at->getTimestampMs() }},
                left: 0,
                tick() {
                    this.left = Math.max(0, Math.floor((this.deadline - Date.now()) / 1000));
                    if (this.left === 0) { $wire.submit(); clearInterval(this.timer); }
                },
                init() { this.tick(); this.timer = setInterval(() => this.tick(), 1000); },
            }"
            class="sticky top-0 z-10 mb-6 flex items-center justify-between rounded-lg border border-zinc-200 bg-white px-4 py-2 dark:border-zinc-700 dark:bg-zinc-800"
        >
            <flux:text class="text-sm">{{ __('Answers are saved as you go.') }}</flux:text>
            <span class="font-mono text-lg tabular-nums" :class="left < 60 ? 'text-red-600' : ''"
                x-text="`${String(Math.floor(left / 3600)).padStart(2, '0')}:${String(Math.floor(left % 3600 / 60)).padStart(2, '0')}:${String(left % 60).padStart(2, '0')}`"></span>
        </div>

        @error('answer')
            <flux:callout variant="danger" icon="exclamation-triangle" class="mb-4" :text="$message" />
        @enderror

        <div class="flex flex-col gap-4">
            @foreach ($this->questions as $question)
                <flux:card wire:key="q-{{ $question->id }}" class="space-y-3">
                    <flux:text class="text-xs">{{ __('Question :no', ['no' => $loop->iteration]) }} · {{ trans_choice(':count point|:count points', $question->points) }}</flux:text>
                    <div class="whitespace-pre-line font-medium">{{ $question->body }}</div>

                    @if ($question->type === QuestionType::Essay)
                        <flux:textarea wire:model.blur="answers.{{ $question->id }}" rows="4" />
                    @elseif ($question->type === QuestionType::MultipleChoice)
                        <flux:checkbox.group wire:model.live="answers.{{ $question->id }}">
                            @foreach ($question->options as $option)
                                <flux:checkbox :value="$option->id" :label="$option->body" />
                            @endforeach
                        </flux:checkbox.group>
                    @else
                        <flux:radio.group wire:model.live="answers.{{ $question->id }}">
                            @foreach ($question->options as $option)
                                <flux:radio :value="$option->id" :label="$option->body" />
                            @endforeach
                        </flux:radio.group>
                    @endif
                </flux:card>
            @endforeach
        </div>

        <div class="mt-6">
            <flux:button variant="primary" wire:click="submit" wire:confirm="{{ __('Hand in your answers now?') }}" data-test="submit-quiz">{{ __('Submit') }}</flux:button>
        </div>
    @endif
</section>
