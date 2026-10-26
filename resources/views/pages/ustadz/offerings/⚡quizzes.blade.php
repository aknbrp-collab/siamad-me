<?php

use App\Actions\Lms\ManageQuizzes;
use App\Models\CourseOffering;
use App\Models\Quiz;
use App\Support\OfferingAccess;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Quizzes')] class extends Component {
    public CourseOffering $offering;

    public string $title = '';
    public ?string $opensAt = null;
    public ?string $closesAt = null;

    public function mount(CourseOffering $offering): void
    {
        abort_unless(OfferingAccess::teaches(auth()->user(), $offering), 403);

        $this->offering = $offering->load(['classGroup', 'semester.academicYear']);
    }

    /**
     * @return Collection<int, Quiz>
     */
    #[Computed]
    public function quizzes(): Collection
    {
        return $this->offering->quizzes()->withCount(['questions', 'attempts'])->get();
    }

    public function create(): void
    {
        $this->title = '';
        $this->opensAt = now()->addDay()->setTime(8, 0)->format('Y-m-d\TH:i');
        $this->closesAt = now()->addDays(2)->setTime(22, 0)->format('Y-m-d\TH:i');
        $this->resetErrorBag();
        Flux::modal('quiz-form')->show();
    }

    public function save(ManageQuizzes $quizzes): void
    {
        $this->resetErrorBag();

        $quiz = $quizzes->save($this->offering, null, [
            'title' => $this->title,
            'opens_at' => $this->opensAt,
            'closes_at' => $this->closesAt,
            'max_attempts' => 1,
            'duration_minutes' => 30,
        ]);

        $this->redirectRoute('ustadz.offerings.quiz', [$this->offering, $quiz], navigate: true);
    }
}; ?>

<section class="w-full">
    <x-offering-header :offering="$offering" current="quizzes" />

    @error('semester')
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6" :text="$message" />
    @enderror

    @unless ($offering->semester->isLocked())
        <flux:button variant="primary" icon="plus" class="mb-4" wire:click="create">{{ __('New quiz') }}</flux:button>
    @endunless

    <div class="flex flex-col gap-3">
        @forelse ($this->quizzes as $quiz)
            <flux:card wire:key="quiz-{{ $quiz->id }}" class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <div class="flex-1">
                    <flux:link :href="route('ustadz.offerings.quiz', [$offering, $quiz])" wire:navigate class="font-medium">{{ $quiz->title }}</flux:link>
                    <flux:text class="text-sm">
                        {{ $quiz->opens_at->translatedFormat('j M H:i') }} – {{ $quiz->closes_at->translatedFormat('j M H:i') }}
                        @if ($quiz->duration_minutes) · {{ __(':minutes min', ['minutes' => $quiz->duration_minutes]) }} @endif
                    </flux:text>
                </div>
                <div class="flex flex-wrap gap-2">
                    <flux:badge size="sm">{{ trans_choice(':count question|:count questions', $quiz->questions_count) }}</flux:badge>
                    <flux:badge size="sm">{{ trans_choice(':count attempt|:count attempts', $quiz->attempts_count) }}</flux:badge>
                    <flux:badge size="sm" :color="$quiz->isPublished() ? 'green' : 'zinc'">{{ $quiz->isPublished() ? __('Published') : __('Draft') }}</flux:badge>
                </div>
            </flux:card>
        @empty
            <flux:text>{{ __('No quizzes yet.') }}</flux:text>
        @endforelse
    </div>

    <flux:modal name="quiz-form" class="w-full max-w-md">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ __('New quiz') }}</flux:heading>
            <flux:input wire:model="title" :label="__('Title')" required />
            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="opensAt" :label="__('Opens')" type="datetime-local" required />
                <flux:input wire:model="closesAt" :label="__('Closes')" type="datetime-local" required />
            </div>
            <flux:error name="closes_at" />
            <flux:text class="text-sm">{{ __('You add questions and settings on the next page.') }}</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
