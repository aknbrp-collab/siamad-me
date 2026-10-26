<?php

use App\Actions\Lms\ManageAssignments;
use App\Models\Assignment;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\Submission;
use App\Support\OfferingAccess;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Submissions')] class extends Component {
    public CourseOffering $offering;

    public Assignment $assignment;

    /** @var array<int|string, string|null> submission id => score being typed */
    public array $scores = [];

    /** @var array<int|string, string|null> */
    public array $feedback = [];

    public function mount(CourseOffering $offering, Assignment $assignment): void
    {
        abort_unless(OfferingAccess::teaches(auth()->user(), $offering), 403);
        abort_unless($assignment->course_offering_id === $offering->id, 404);

        $this->offering = $offering->load(['classGroup', 'semester.academicYear']);
        $this->assignment = $assignment->load('gradeComponent');

        foreach ($this->assignment->submissions as $submission) {
            $this->scores[$submission->id] = $submission->score;
            $this->feedback[$submission->id] = $submission->feedback;
        }
    }

    /**
     * Every enrolled mahasantri with their submission (if any).
     *
     * @return Collection<int, CourseEnrollment>
     */
    #[Computed]
    public function enrollments(): Collection
    {
        return $this->offering->enrollments()
            ->with(['student.user', 'submissions' => fn ($query) => $query->where('assignment_id', $this->assignment->id)])
            ->get()
            ->sortBy('student.user.name')
            ->values();
    }

    public function grade(int $submissionId, ManageAssignments $assignments): void
    {
        $this->resetErrorBag();

        $submission = Submission::query()->where('assignment_id', $this->assignment->id)->findOrFail($submissionId);

        try {
            $assignments->grade($submission, $this->scores[$submissionId] ?? null, $this->feedback[$submissionId] ?? null, auth()->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->addError("scores.{$submissionId}", $e->getMessage());

            return;
        }

        unset($this->enrollments);
        Flux::toast(variant: 'success', text: __('Graded.'));
    }

    public function copyToGradeSheet(ManageAssignments $assignments): void
    {
        $this->resetErrorBag();

        $count = $assignments->copyToGradeSheet($this->assignment);

        Flux::toast(variant: 'success', text: trans_choice('Copied :count score to the grade sheet.|Copied :count scores to the grade sheet.', $count));
    }
}; ?>

<section class="w-full">
    <x-offering-header :offering="$offering" current="assignments" />

    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="lg">{{ $assignment->title }}</flux:heading>
            <flux:text class="text-sm">{{ __('Due :date', ['date' => $assignment->due_at->translatedFormat('l, j M Y H:i')]) }} · {{ __('max :max', ['max' => $assignment->max_score]) }}</flux:text>
            @if ($assignment->instructions)
                <flux:text class="mt-2 whitespace-pre-line">{{ $assignment->instructions }}</flux:text>
            @endif
        </div>
        @if ($assignment->gradeComponent && ! $offering->semester->isLocked())
            <flux:button icon="arrow-right-circle" wire:click="copyToGradeSheet" wire:confirm="{{ __('Copy graded scores (as %) into :component? Existing values there are replaced.', ['component' => $assignment->gradeComponent->name]) }}">
                {{ __('Copy to “:component”', ['component' => $assignment->gradeComponent->name]) }}
            </flux:button>
        @endif
    </div>

    @foreach (['semester', 'assignment', 'grades', 'scores'] as $key)
        @error($key)
            <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6" :text="$message" />
        @enderror
    @endforeach

    <div class="flex flex-col gap-3">
        @foreach ($this->enrollments as $enrollment)
            @php($submission = $enrollment->submissions->first())
            <flux:card wire:key="enrollment-{{ $enrollment->id }}" class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium">{{ $enrollment->student->user->name }} <span class="font-mono text-xs text-zinc-500">{{ $enrollment->student->nim }}</span></span>
                    @if ($submission)
                        <span class="flex items-center gap-2">
                            <flux:text class="text-xs">{{ $submission->submitted_at->translatedFormat('j M H:i') }}</flux:text>
                            @if ($submission->is_late)<flux:badge size="sm" color="amber">{{ __('Late') }}</flux:badge>@endif
                            @if ($submission->graded_at)<flux:badge size="sm" color="green">{{ __('Graded') }}</flux:badge>@endif
                        </span>
                    @else
                        <flux:badge size="sm" :color="$assignment->isPastDue() ? 'red' : 'zinc'">{{ $assignment->isPastDue() ? __('Missing') : __('Not yet submitted') }}</flux:badge>
                    @endif
                </div>

                @if ($submission)
                    @if ($submission->text)
                        <flux:text class="whitespace-pre-line rounded-md bg-zinc-50 p-3 text-sm dark:bg-zinc-800">{{ $submission->text }}</flux:text>
                    @endif
                    @if ($submission->file_path)
                        <flux:link :href="route('files.show', ['submission', $submission->id])" target="_blank" class="text-sm">{{ $submission->original_name }}</flux:link>
                    @endif

                    @unless ($offering->semester->isLocked())
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start">
                            <flux:input wire:model="scores.{{ $submission->id }}" size="sm" :placeholder="__('Score')" class="sm:w-28" />
                            <flux:input wire:model="feedback.{{ $submission->id }}" size="sm" :placeholder="__('Feedback (optional)')" class="flex-1" />
                            <flux:button size="sm" variant="primary" wire:click="grade({{ $submission->id }})">{{ __('Save grade') }}</flux:button>
                        </div>
                        <flux:error name="scores.{{ $submission->id }}" />
                    @else
                        <flux:text class="text-sm">{{ __('Score') }}: {{ $submission->score ?? '—' }} @if ($submission->feedback) · {{ $submission->feedback }} @endif</flux:text>
                    @endunless
                @endif
            </flux:card>
        @endforeach
    </div>
</section>
