<?php

use App\Actions\Lms\ManageQuizzes;
use App\Enums\QuestionType;
use App\Models\CourseOffering;
use App\Models\GradeComponent;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Support\OfferingAccess;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Quiz')] class extends Component {
    public CourseOffering $offering;

    public Quiz $quiz;

    #[Url]
    public string $tab = 'questions';

    // Settings
    public string $title = '';
    public ?string $description = null;
    public ?string $opensAt = null;
    public ?string $closesAt = null;
    public ?int $durationMinutes = null;
    public ?int $maxAttempts = 1;
    public bool $shuffle = false;
    public ?int $gradeComponentId = null;

    // Question form
    public ?int $questionId = null;
    public string $type = 'single_choice';
    public string $body = '';
    public ?int $points = 1;

    /** @var list<array{body: string, is_correct: bool}> */
    public array $options = [];

    /** @var array<int|string, string|null> essay answer id => points being typed */
    public array $essayPoints = [];

    public function mount(CourseOffering $offering, Quiz $quiz): void
    {
        abort_unless(OfferingAccess::teaches(auth()->user(), $offering), 403);
        abort_unless($quiz->course_offering_id === $offering->id, 404);

        $this->offering = $offering->load(['classGroup', 'semester.academicYear']);
        $this->quiz = $quiz;
        $this->fillSettings();
    }

    private function fillSettings(): void
    {
        $this->title = $this->quiz->title;
        $this->description = $this->quiz->description;
        $this->opensAt = $this->quiz->opens_at->format('Y-m-d\TH:i');
        $this->closesAt = $this->quiz->closes_at->format('Y-m-d\TH:i');
        $this->durationMinutes = $this->quiz->duration_minutes;
        $this->maxAttempts = $this->quiz->max_attempts;
        $this->shuffle = $this->quiz->shuffle_questions;
        $this->gradeComponentId = $this->quiz->grade_component_id;
    }

    #[Computed]
    public function locked(): bool
    {
        return $this->offering->semester->isLocked() || $this->quiz->attempts()->exists();
    }

    /**
     * @return Collection<int, QuizQuestion>
     */
    #[Computed]
    public function questions(): Collection
    {
        return $this->quiz->questions()->with('options')->get();
    }

    /**
     * @return Collection<int, GradeComponent>
     */
    #[Computed]
    public function components(): Collection
    {
        return $this->offering->components()->get();
    }

    /**
     * @return Collection<int, QuizAttempt>
     */
    #[Computed]
    public function attempts(): Collection
    {
        return $this->quiz->attempts()
            ->with(['courseEnrollment.student.user', 'answers.question'])
            ->whereNotNull('submitted_at')
            ->orderByDesc('submitted_at')
            ->get();
    }

    public function saveSettings(ManageQuizzes $quizzes): void
    {
        $this->resetErrorBag();

        $this->quiz = $quizzes->save($this->offering, $this->quiz, [
            'title' => $this->title,
            'description' => $this->description ?: null,
            'opens_at' => $this->opensAt,
            'closes_at' => $this->closesAt,
            'duration_minutes' => $this->durationMinutes ?: null,
            'max_attempts' => $this->maxAttempts,
            'shuffle_questions' => $this->shuffle,
            'grade_component_id' => $this->gradeComponentId ?: null,
        ]);

        Flux::toast(variant: 'success', text: __('Quiz settings saved.'));
    }

    public function newQuestion(): void
    {
        $this->reset('questionId', 'body');
        $this->type = QuestionType::SingleChoice->value;
        $this->points = 1;
        $this->options = [['body' => '', 'is_correct' => true], ['body' => '', 'is_correct' => false]];
        $this->resetErrorBag();
        Flux::modal('question-form')->show();
    }

    public function editQuestion(int $id): void
    {
        $question = $this->quiz->questions()->with('options')->findOrFail($id);

        $this->questionId = $question->id;
        $this->type = $question->type->value;
        $this->body = $question->body;
        $this->points = $question->points;
        $this->options = $question->options->map(fn ($option) => ['body' => $option->body, 'is_correct' => $option->is_correct])->all();
        $this->resetErrorBag();
        Flux::modal('question-form')->show();
    }

    public function updatedType(string $type): void
    {
        if ($type === QuestionType::TrueFalse->value) {
            $this->options = [['body' => __('True'), 'is_correct' => true], ['body' => __('False'), 'is_correct' => false]];
        } elseif ($type !== QuestionType::Essay->value && count($this->options) < 2) {
            $this->options = [['body' => '', 'is_correct' => true], ['body' => '', 'is_correct' => false]];
        }
    }

    public function addOption(): void
    {
        $this->options[] = ['body' => '', 'is_correct' => false];
    }

    public function removeOption(int $index): void
    {
        unset($this->options[$index]);
        $this->options = array_values($this->options);
    }

    /**
     * Single-answer questions: picking one correct option clears the others.
     */
    public function markCorrect(int $index): void
    {
        if ($this->type === QuestionType::MultipleChoice->value) {
            $this->options[$index]['is_correct'] = ! $this->options[$index]['is_correct'];

            return;
        }

        foreach ($this->options as $i => $option) {
            $this->options[$i]['is_correct'] = $i === $index;
        }
    }

    public function saveQuestion(ManageQuizzes $quizzes): void
    {
        $this->resetErrorBag();

        $quizzes->saveQuestion($this->quiz, $this->questionId ? $this->quiz->questions()->findOrFail($this->questionId) : null, [
            'type' => $this->type,
            'body' => $this->body,
            'points' => $this->points,
            'options' => $this->options,
        ]);

        Flux::modal('question-form')->close();
        unset($this->questions);
        Flux::toast(variant: 'success', text: __('Question saved.'));
    }

    public function deleteQuestion(int $id, ManageQuizzes $quizzes): void
    {
        $this->resetErrorBag();

        $quizzes->deleteQuestion($this->quiz->questions()->findOrFail($id));

        unset($this->questions);
    }

    public function togglePublish(ManageQuizzes $quizzes): void
    {
        $this->resetErrorBag();

        $quizzes->publish($this->quiz, ! $this->quiz->isPublished());

        $this->quiz->refresh();
        Flux::toast(variant: 'success', text: $this->quiz->isPublished() ? __('Published. Mahasantri can take it while it is open.') : __('Back to draft.'));
    }

    public function gradeEssay(int $answerId, ManageQuizzes $quizzes): void
    {
        $this->resetErrorBag();

        $answer = QuizAnswer::query()->whereIn('quiz_attempt_id', $this->quiz->attempts()->select('id'))->findOrFail($answerId);

        try {
            $quizzes->gradeEssay($answer, $this->essayPoints[$answerId] ?? null);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->addError("essay.{$answerId}", $e->getMessage());

            return;
        }

        unset($this->attempts);
        Flux::toast(variant: 'success', text: __('Points saved.'));
    }

    public function copyToGradeSheet(ManageQuizzes $quizzes): void
    {
        $this->resetErrorBag();

        $count = $quizzes->copyToGradeSheet($this->quiz);

        Flux::toast(variant: 'success', text: trans_choice('Copied :count score to the grade sheet.|Copied :count scores to the grade sheet.', $count));
    }
}; ?>

<section class="w-full">
    <x-offering-header :offering="$offering" current="quizzes" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="lg">{{ $quiz->title }}</flux:heading>
            <flux:text class="text-sm">{{ trans_choice(':count question|:count questions', $this->questions->count()) }} · {{ __(':points points', ['points' => $this->questions->sum('points')]) }}</flux:text>
        </div>
        <div class="flex items-center gap-2">
            <flux:badge :color="$quiz->isPublished() ? 'green' : 'zinc'">{{ $quiz->isPublished() ? __('Published') : __('Draft') }}</flux:badge>
            @unless ($offering->semester->isLocked())
                <flux:button size="sm" wire:click="togglePublish">{{ $quiz->isPublished() ? __('Unpublish') : __('Publish') }}</flux:button>
            @endunless
        </div>
    </div>

    @foreach (['semester', 'quiz', 'grades', 'scores'] as $key)
        @error($key)
            <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6" :text="$message" />
        @enderror
    @endforeach

    <flux:radio.group wire:model.live="tab" variant="segmented" class="mb-6">
        <flux:radio value="questions" :label="__('Questions')" />
        <flux:radio value="settings" :label="__('Settings')" />
        <flux:radio value="results" :label="__('Results (:count)', ['count' => $this->attempts->count()])" />
    </flux:radio.group>

    @if ($tab === 'questions')
        @if ($this->locked)
            <flux:callout icon="lock-closed" class="mb-4" :text="__('Questions are locked because mahasantri have started this quiz.')" />
        @else
            <flux:button variant="primary" icon="plus" class="mb-4" wire:click="newQuestion">{{ __('Add question') }}</flux:button>
        @endif

        <div class="flex flex-col gap-3">
            @forelse ($this->questions as $question)
                <flux:card wire:key="question-{{ $question->id }}" class="space-y-2">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <flux:text class="text-xs">{{ $loop->iteration }}. {{ $question->type->label() }} · {{ trans_choice(':count point|:count points', $question->points) }}</flux:text>
                            <div class="whitespace-pre-line font-medium">{{ $question->body }}</div>
                        </div>
                        @unless ($this->locked)
                            <div class="flex gap-1">
                                <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editQuestion({{ $question->id }})" :aria-label="__('Edit')" />
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteQuestion({{ $question->id }})" wire:confirm="{{ __('Remove this question?') }}" :aria-label="__('Remove')" />
                            </div>
                        @endunless
                    </div>
                    @foreach ($question->options as $option)
                        <div class="flex items-center gap-2 text-sm">
                            <flux:icon :icon="$option->is_correct ? 'check-circle' : 'minus-circle'" variant="mini" @class(['size-4', 'text-green-600' => $option->is_correct, 'text-zinc-400' => ! $option->is_correct]) />
                            {{ $option->body }}
                        </div>
                    @endforeach
                </flux:card>
            @empty
                <flux:text>{{ __('No questions yet.') }}</flux:text>
            @endforelse
        </div>
    @elseif ($tab === 'settings')
        <form wire:submit="saveSettings" class="max-w-2xl space-y-6">
            <flux:input wire:model="title" :label="__('Title')" required />
            <flux:textarea wire:model="description" :label="__('Instructions for mahasantri')" rows="3" />
            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="opensAt" :label="__('Opens')" type="datetime-local" required />
                <flux:input wire:model="closesAt" :label="__('Closes')" type="datetime-local" required />
                <flux:input wire:model="durationMinutes" :label="__('Time limit (minutes)')" type="number" min="1" :description:trailing="__('Empty: until the quiz closes.')" />
                <flux:input wire:model="maxAttempts" :label="__('Attempts allowed')" type="number" min="1" max="10" required />
            </div>
            <flux:switch wire:model="shuffle" :label="__('Shuffle question order for each mahasantri')" />
            <flux:select wire:model="gradeComponentId" :label="__('Counts toward grade component')">
                <flux:select.option value="">{{ __('— none —') }}</flux:select.option>
                @foreach ($this->components as $component)
                    <flux:select.option :value="$component->id">{{ $component->name }} ({{ $component->weight }}%)</flux:select.option>
                @endforeach
            </flux:select>
            @foreach (['closes_at', 'duration_minutes', 'max_attempts'] as $field)
                <flux:error :name="$field" />
            @endforeach
            @unless ($offering->semester->isLocked())
                <flux:button type="submit" variant="primary">{{ __('Save settings') }}</flux:button>
            @endunless
        </form>
    @else
        @if ($quiz->grade_component_id && ! $offering->semester->isLocked())
            <flux:button class="mb-4" icon="arrow-right-circle" wire:click="copyToGradeSheet" wire:confirm="{{ __('Copy each mahasantri\'s best graded attempt (as %) into the grade sheet?') }}">{{ __('Copy best scores to the grade sheet') }}</flux:button>
        @endif

        <div class="flex flex-col gap-3">
            @forelse ($this->attempts as $attempt)
                <flux:card wire:key="attempt-{{ $attempt->id }}" class="space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-medium">{{ $attempt->courseEnrollment->student->user->name }}</span>
                        <span class="flex items-center gap-2">
                            <flux:text class="text-xs">{{ $attempt->submitted_at->translatedFormat('j M H:i') }}</flux:text>
                            @if ($attempt->needs_manual_grading)
                                <flux:badge size="sm" color="amber">{{ __('Essay to grade') }}</flux:badge>
                            @endif
                            <flux:badge size="sm">{{ $attempt->score }} / {{ $attempt->max_score }}</flux:badge>
                        </span>
                    </div>
                    @foreach ($attempt->answers->filter(fn ($answer) => $answer->question->type === QuestionType::Essay) as $answer)
                        <div wire:key="essay-{{ $answer->id }}" class="space-y-2 rounded-md bg-zinc-50 p-3 dark:bg-zinc-800">
                            <flux:text class="text-xs">{{ $answer->question->body }}</flux:text>
                            <div class="whitespace-pre-line text-sm">{{ $answer->text ?: '—' }}</div>
                            @unless ($offering->semester->isLocked())
                                <div class="flex items-center gap-2">
                                    <flux:input wire:model="essayPoints.{{ $answer->id }}" size="sm" class="w-24" :placeholder="$answer->points_awarded ?? '0'" />
                                    <flux:text class="text-xs">/ {{ $answer->question->points }}</flux:text>
                                    <flux:button size="sm" wire:click="gradeEssay({{ $answer->id }})">{{ __('Save') }}</flux:button>
                                    @if ($answer->points_awarded !== null)
                                        <flux:badge size="sm" color="green">{{ $answer->points_awarded }}</flux:badge>
                                    @endif
                                </div>
                                <flux:error name="essay.{{ $answer->id }}" />
                            @endunless
                        </div>
                    @endforeach
                </flux:card>
            @empty
                <flux:text>{{ __('No submitted attempts yet.') }}</flux:text>
            @endforelse
        </div>
    @endif

    <flux:modal name="question-form" class="w-full max-w-xl">
        <form wire:submit="saveQuestion" class="space-y-6">
            <flux:heading size="lg">{{ $questionId ? __('Edit question') : __('Add question') }}</flux:heading>
            <div class="grid gap-6 sm:grid-cols-[1fr_7rem]">
                <flux:select wire:model.live="type" :label="__('Type')">
                    @foreach (QuestionType::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="points" :label="__('Points')" type="number" min="1" max="100" required />
            </div>
            <flux:textarea wire:model="body" :label="__('Question')" rows="3" required />

            @if ($type !== QuestionType::Essay->value)
                <div class="space-y-2">
                    <flux:label>{{ $type === QuestionType::MultipleChoice->value ? __('Options (tick every correct one)') : __('Options (tick the correct one)') }}</flux:label>
                    @foreach ($options as $index => $option)
                        <div wire:key="option-{{ $index }}" class="flex items-center gap-2">
                            <button type="button" wire:click="markCorrect({{ $index }})" aria-label="{{ __('Correct') }}"
                                @class(['rounded-full', 'text-green-600' => $option['is_correct'], 'text-zinc-300 dark:text-zinc-600' => ! $option['is_correct']])>
                                <flux:icon icon="check-circle" />
                            </button>
                            <flux:input wire:model="options.{{ $index }}.body" size="sm" class="flex-1" :readonly="$type === QuestionType::TrueFalse->value" />
                            @if ($type !== QuestionType::TrueFalse->value)
                                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeOption({{ $index }})" :aria-label="__('Remove')" />
                            @endif
                        </div>
                    @endforeach
                    @if ($type !== QuestionType::TrueFalse->value)
                        <flux:button size="sm" icon="plus" wire:click="addOption">{{ __('Add option') }}</flux:button>
                    @endif
                    <flux:error name="options" />
                    @if ($errors->has('options.*'))
                        <flux:text class="text-sm text-red-600">{{ $errors->first('options.*') }}</flux:text>
                    @endif
                </div>
            @endif

            <flux:error name="quiz" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save question') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
