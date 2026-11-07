<?php

use App\Actions\Evaluations\ManageEvaluations;
use App\Models\CourseOffering;
use App\Models\EvaluationQuestion;
use App\Models\Student;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Teaching evaluation')] class extends Component {
    /** The class kuliah being evaluated. */
    #[Url(as: 'class')]
    public ?int $offeringId = null;

    /** @var array<int|string, int|string> question id => score */
    public array $scores = [];

    public string $comment = '';

    public function mount(): void
    {
        abort_if($this->student === null, 403);
    }

    #[Computed]
    public function student(): ?Student
    {
        return auth()->user()->student;
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{offering: CourseOffering, period: \App\Models\EvaluationPeriod, done: bool}>
     */
    #[Computed]
    public function classes(): \Illuminate\Support\Collection
    {
        return app(ManageEvaluations::class)->forStudent($this->student);
    }

    /**
     * @return array{offering: CourseOffering, period: \App\Models\EvaluationPeriod, done: bool}|null
     */
    #[Computed]
    public function current(): ?array
    {
        $row = $this->classes->first(fn (array $row) => $row['offering']->id === $this->offeringId);

        return $row !== null && ! $row['done'] ? $row : null;
    }

    /**
     * @return Collection<int, EvaluationQuestion>
     */
    #[Computed]
    public function questions(): Collection
    {
        return $this->current === null
            ? new Collection
            : EvaluationQuestion::query()->where('semester_id', $this->current['offering']->semester_id)->where('is_active', true)->orderBy('position')->orderBy('id')->get();
    }

    public function choose(?int $id): void
    {
        $this->offeringId = $id;
        $this->reset('scores', 'comment');
        $this->resetErrorBag();
        unset($this->current, $this->questions);
    }

    public function submit(ManageEvaluations $evaluations): void
    {
        $current = $this->current;
        abort_if($current === null, 404);

        $evaluations->submit($this->student, $current['offering'], $this->scores, $this->comment);

        $this->choose(null);
        unset($this->classes);
        Flux::toast(variant: 'success', text: __('Thank you. Your evaluation was saved anonymously.'));
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-page-header :title="__('Teaching evaluation')" :subtitle="__('Rate each class kuliah you followed this semester. Your answers are anonymous: only totals are kept, and nobody (not the muhadhir, not the Admin) can see what you answered.')" />

    @if ($current = $this->current)
        @php($offering = $current['offering'])
        <form wire:submit="submit" class="mb-8 space-y-5 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-test="evaluation-form">
            <div class="flex items-start justify-between gap-2">
                <div>
                    <flux:heading size="lg">{{ $offering->subject_name }}</flux:heading>
                    <flux:text size="sm">{{ $offering->teacher->user->name }} · {{ $offering->class_name }}</flux:text>
                </div>
                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="choose(null)" :aria-label="__('Close')" />
            </div>

            <flux:text size="sm">{{ __('1 = strongly disagree, 5 = strongly agree.') }}</flux:text>
            @error('scores') <flux:callout color="red" icon="exclamation-triangle" :text="$message" /> @enderror

            @php($group = null)
            @foreach ($this->questions as $question)
                @if ($question->group !== $group)
                    @php($group = $question->group)
                    <flux:heading size="sm" class="pt-2 text-zinc-500 uppercase">{{ $group }}</flux:heading>
                @endif
                <div wire:key="q-{{ $question->id }}" class="space-y-2">
                    <p class="text-sm">{{ $question->question }}</p>
                    <flux:radio.group wire:model="scores.{{ $question->id }}" variant="segmented" size="sm" class="w-full sm:w-auto">
                        @foreach ([1, 2, 3, 4, 5] as $score)
                            <flux:radio :value="(string) $score" :label="(string) $score" />
                        @endforeach
                    </flux:radio.group>
                </div>
            @endforeach

            <flux:textarea wire:model="comment" :label="$current['period']->comment_prompt" :description="__('Optional. Write without your name.')" rows="3" maxlength="2000" />

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" icon="paper-airplane">{{ __('Send evaluation') }}</flux:button>
            </div>
        </form>
    @endif

    @if ($this->classes->isEmpty())
        <flux:callout icon="information-circle" :text="__('No evaluation is open now.')" />
    @else
        @php($period = $this->classes->first()['period'])
        <flux:text class="mb-3">{{ __('Open until :date.', ['date' => $period->closes_on->translatedFormat('j F Y')]) }}</flux:text>
        <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @foreach ($this->classes as $row)
                @php($offering = $row['offering'])
                <div wire:key="class-{{ $offering->id }}" class="flex items-center gap-3 px-4 py-3" data-test="class-{{ $offering->id }}">
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-medium">{{ $offering->subject_name }}</span>
                        <span class="block text-xs text-zinc-500">{{ $offering->teacher->user->name }}</span>
                    </span>
                    @if ($row['done'])
                        <flux:badge size="sm" color="green" icon="check">{{ __('Done') }}</flux:badge>
                    @else
                        <flux:button size="sm" variant="primary" wire:click="choose({{ $offering->id }})">{{ __('Evaluate') }}</flux:button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</section>
