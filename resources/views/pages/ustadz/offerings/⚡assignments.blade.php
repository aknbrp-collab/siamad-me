<?php

use App\Actions\Lms\ManageAssignments;
use App\Models\Assignment;
use App\Models\CourseOffering;
use App\Models\GradeComponent;
use App\Support\OfferingAccess;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Assignments')] class extends Component {
    public CourseOffering $offering;

    public ?int $assignmentId = null;
    public string $title = '';
    public ?string $instructions = null;
    public ?string $dueAt = null;
    public bool $allowLate = false;
    public ?int $maxScore = 100;
    public ?int $gradeComponentId = null;

    public function mount(CourseOffering $offering): void
    {
        abort_unless(OfferingAccess::teaches(auth()->user(), $offering), 403);

        $this->offering = $offering->load(['classGroup', 'semester.academicYear']);
    }

    /**
     * @return Collection<int, Assignment>
     */
    #[Computed]
    public function assignments(): Collection
    {
        return $this->offering->assignments()
            ->with('gradeComponent')
            ->withCount(['submissions', 'submissions as graded_count' => fn ($query) => $query->whereNotNull('graded_at')])
            ->get();
    }

    /**
     * @return Collection<int, GradeComponent>
     */
    #[Computed]
    public function components(): Collection
    {
        return $this->offering->components()->get();
    }

    #[Computed]
    public function studentCount(): int
    {
        return $this->offering->enrollments()->count();
    }

    public function create(): void
    {
        $this->reset('assignmentId', 'title', 'instructions', 'gradeComponentId');
        $this->dueAt = now()->addWeek()->setTime(23, 59)->format('Y-m-d\TH:i');
        $this->allowLate = false;
        $this->maxScore = 100;
        $this->resetErrorBag();
        Flux::modal('assignment-form')->show();
    }

    public function edit(int $id): void
    {
        $assignment = $this->offering->assignments()->findOrFail($id);

        $this->assignmentId = $assignment->id;
        $this->title = $assignment->title;
        $this->instructions = $assignment->instructions;
        $this->dueAt = $assignment->due_at->format('Y-m-d\TH:i');
        $this->allowLate = $assignment->allow_late;
        $this->maxScore = $assignment->max_score;
        $this->gradeComponentId = $assignment->grade_component_id;
        $this->resetErrorBag();
        Flux::modal('assignment-form')->show();
    }

    public function save(ManageAssignments $assignments): void
    {
        $this->resetErrorBag();

        $assignments->save($this->offering, $this->assignmentId ? $this->offering->assignments()->findOrFail($this->assignmentId) : null, [
            'title' => $this->title,
            'instructions' => $this->instructions ?: null,
            'due_at' => $this->dueAt,
            'allow_late' => $this->allowLate,
            'max_score' => $this->maxScore,
            'grade_component_id' => $this->gradeComponentId ?: null,
        ]);

        Flux::modal('assignment-form')->close();
        unset($this->assignments);
        Flux::toast(variant: 'success', text: __('Assignment saved.'));
    }

    public function delete(int $id, ManageAssignments $assignments): void
    {
        $this->resetErrorBag();

        $assignments->delete($this->offering->assignments()->findOrFail($id));

        unset($this->assignments);
        Flux::toast(variant: 'success', text: __('Assignment removed.'));
    }
}; ?>

<section class="w-full">
    <x-offering-header :offering="$offering" current="assignments" />

    @foreach (['semester', 'assignment'] as $key)
        @error($key)
            <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6" :text="$message" />
        @enderror
    @endforeach

    @unless ($offering->semester->isLocked())
        <flux:button variant="primary" icon="plus" class="mb-4" wire:click="create">{{ __('Add assignment') }}</flux:button>
    @endunless

    <div class="flex flex-col gap-3">
        @forelse ($this->assignments as $assignment)
            <flux:card wire:key="assignment-{{ $assignment->id }}" class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <div class="flex-1">
                    <flux:link :href="route('ustadz.offerings.assignment', [$offering, $assignment])" wire:navigate class="font-medium">{{ $assignment->title }}</flux:link>
                    <flux:text class="text-sm">
                        {{ __('Due :date', ['date' => $assignment->due_at->translatedFormat('j M Y H:i')]) }}
                        @if ($assignment->allow_late) · {{ __('late allowed') }} @endif
                        · {{ __('max :max', ['max' => $assignment->max_score]) }}
                        @if ($assignment->gradeComponent) · {{ __('→ :component', ['component' => $assignment->gradeComponent->name]) }} @endif
                    </flux:text>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:badge size="sm">{{ __(':submitted/:total submitted', ['submitted' => $assignment->submissions_count, 'total' => $this->studentCount]) }}</flux:badge>
                    <flux:badge size="sm" :color="$assignment->graded_count === $assignment->submissions_count && $assignment->submissions_count > 0 ? 'green' : 'zinc'">{{ __(':graded graded', ['graded' => $assignment->graded_count]) }}</flux:badge>
                    @unless ($offering->semester->isLocked())
                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $assignment->id }})" :aria-label="__('Edit')" />
                        @if ($assignment->submissions_count === 0)
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $assignment->id }})" wire:confirm="{{ __('Remove this assignment?') }}" :aria-label="__('Remove')" />
                        @endif
                    @endunless
                </div>
            </flux:card>
        @empty
            <flux:text>{{ __('No assignments yet.') }}</flux:text>
        @endforelse
    </div>

    <flux:modal name="assignment-form" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $assignmentId ? __('Edit assignment') : __('Add assignment') }}</flux:heading>
            <flux:input wire:model="title" :label="__('Title')" required />
            <flux:textarea wire:model="instructions" :label="__('Instructions')" rows="5" />
            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="dueAt" :label="__('Due')" type="datetime-local" required />
                <flux:input wire:model="maxScore" :label="__('Maximum score')" type="number" min="1" max="1000" required />
            </div>
            <flux:switch wire:model="allowLate" :label="__('Accept late submissions (marked late)')" />
            <flux:select wire:model="gradeComponentId" :label="__('Counts toward grade component')" :description:trailing="__('Optional. You can copy the scores into the grade sheet later.')">
                <flux:select.option value="">{{ __('— none —') }}</flux:select.option>
                @foreach ($this->components as $component)
                    <flux:select.option :value="$component->id">{{ $component->name }} ({{ $component->weight }}%)</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
