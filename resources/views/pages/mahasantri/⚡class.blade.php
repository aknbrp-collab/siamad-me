<?php

use App\Actions\Lms\ManageAssignments;
use App\Actions\Lms\ManageQuizzes;
use App\Models\Assignment;
use App\Models\ClassSession;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\Material;
use App\Models\Quiz;
use App\Support\OfferingAccess;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Class')] class extends Component {
    use WithFileUploads;

    public CourseOffering $offering;

    #[Locked]
    public int $enrollmentId;

    #[Url]
    public string $tab = 'overview';

    public ?int $submittingId = null;
    public ?string $text = null;
    public $file = null;

    public function mount(CourseOffering $offering): void
    {
        $enrollment = OfferingAccess::enrollment(auth()->user(), $offering);
        abort_if($enrollment === null, 403);

        $this->offering = $offering->load(['teacher.user', 'classGroup', 'semester.academicYear']);
        $this->enrollmentId = $enrollment->id;
    }

    #[Computed]
    public function enrollment(): CourseEnrollment
    {
        return CourseEnrollment::query()->findOrFail($this->enrollmentId);
    }

    /**
     * @return Collection<int, ClassSession>
     */
    #[Computed]
    public function sessions(): Collection
    {
        return $this->offering->sessions()->get();
    }

    /**
     * @return Collection<int, Material>
     */
    #[Computed]
    public function materials(): Collection
    {
        return $this->offering->materials()->get();
    }

    /**
     * @return Collection<int, Assignment>
     */
    #[Computed]
    public function assignments(): Collection
    {
        return $this->offering->assignments()
            ->with(['submissions' => fn ($query) => $query->where('course_enrollment_id', $this->enrollmentId)])
            ->get();
    }

    /**
     * @return Collection<int, Quiz>
     */
    #[Computed]
    public function quizzes(): Collection
    {
        return $this->offering->quizzes()
            ->whereNotNull('published_at')
            ->with(['attempts' => fn ($query) => $query->where('course_enrollment_id', $this->enrollmentId)->orderBy('id')])
            ->get();
    }

    public function openSubmit(int $assignmentId): void
    {
        $assignment = $this->assignments->firstWhere('id', $assignmentId);
        abort_if($assignment === null, 404);

        $this->submittingId = $assignmentId;
        $this->text = $assignment->submissions->first()?->text;
        $this->file = null;
        $this->resetErrorBag();
        Flux::modal('submit-assignment')->show();
    }

    public function submit(ManageAssignments $assignments): void
    {
        $this->resetErrorBag();

        $assignments->submit($this->offering->assignments()->findOrFail($this->submittingId), $this->enrollment, [
            'text' => $this->text ?: null,
            'file' => $this->file,
        ]);

        Flux::modal('submit-assignment')->close();
        $this->reset('submittingId', 'text', 'file');
        unset($this->assignments);
        Flux::toast(variant: 'success', text: __('Submitted. You can still replace it until it is graded.'));
    }

    public function startQuiz(int $quizId, ManageQuizzes $quizzes): void
    {
        $this->resetErrorBag();

        $attempt = $quizzes->start($this->offering->quizzes()->findOrFail($quizId), $this->enrollment);

        $this->redirectRoute('mahasantri.quiz-attempt', $attempt, navigate: true);
    }
}; ?>

<section class="w-full max-w-4xl">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ $offering->subject_name }}</flux:heading>
            <flux:subheading>{{ $offering->subject_code }} · {{ $offering->teacher->displayName() }} · {{ $offering->scheduleLabel() }}</flux:subheading>
        </div>
        <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('mahasantri.classes')" wire:navigate>{{ __('My classes') }}</flux:button>
    </div>

    @foreach (['submission', 'quiz', 'semester'] as $key)
        @error($key)
            <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6" :text="$message" />
        @enderror
    @endforeach

    <flux:radio.group wire:model.live="tab" variant="segmented" class="mb-6 w-full overflow-x-auto">
        <flux:radio value="overview" :label="__('Meetings')" />
        <flux:radio value="materials" :label="__('Materials')" />
        <flux:radio value="assignments" :label="__('Assignments')" />
        <flux:radio value="quizzes" :label="__('Quizzes')" />
        <flux:radio value="forum" :label="__('Forum')" />
    </flux:radio.group>

    @if ($tab === 'overview')
        <div class="flex flex-col gap-2">
            @forelse ($this->sessions as $session)
                <div wire:key="session-{{ $session->id }}" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                    <span class="text-sm">
                        <span class="font-medium">{{ __('Meeting :no', ['no' => $session->meeting_no]) }}</span>
                        · {{ $session->held_on->translatedFormat('l, j M Y') }}@if ($session->topic) · {{ $session->topic }}@endif
                    </span>
                    @if ($session->meeting_url)
                        <flux:button size="sm" variant="primary" icon="video-camera" :href="$session->meeting_url" target="_blank">{{ __('Join on :provider', ['provider' => $session->meeting_provider?->label() ?? __('link')]) }}</flux:button>
                    @endif
                </div>
            @empty
                <flux:text>{{ __('No meetings yet.') }}</flux:text>
            @endforelse
        </div>
    @elseif ($tab === 'materials')
        <div class="flex flex-col gap-3">
            @forelse ($this->materials as $material)
                <flux:card wire:key="material-{{ $material->id }}" class="space-y-1">
                    <flux:heading>{{ $material->title }}</flux:heading>
                    @if ($material->body)
                        <flux:text class="whitespace-pre-line text-sm">{{ $material->body }}</flux:text>
                    @endif
                    <div class="flex flex-wrap gap-3 text-sm">
                        @if ($material->file_path)
                            <flux:link :href="route('files.show', ['material', $material->id])" target="_blank">{{ $material->original_name }}</flux:link>
                        @endif
                        @if ($material->url)
                            <flux:link :href="$material->url" target="_blank">{{ $material->url }}</flux:link>
                        @endif
                    </div>
                </flux:card>
            @empty
                <flux:text>{{ __('No materials yet.') }}</flux:text>
            @endforelse
        </div>
    @elseif ($tab === 'assignments')
        <div class="flex flex-col gap-3">
            @forelse ($this->assignments as $assignment)
                @php($submission = $assignment->submissions->first())
                <flux:card wire:key="assignment-{{ $assignment->id }}" class="space-y-2">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <flux:heading>{{ $assignment->title }}</flux:heading>
                            <flux:text class="text-sm">{{ __('Due :date', ['date' => $assignment->due_at->translatedFormat('l, j M Y H:i')]) }}</flux:text>
                        </div>
                        @if ($submission?->graded_at)
                            <flux:badge color="green">{{ __('Score :score / :max', ['score' => $submission->score, 'max' => $assignment->max_score]) }}</flux:badge>
                        @elseif ($submission)
                            <flux:badge color="blue">{{ $submission->is_late ? __('Submitted late') : __('Submitted') }}</flux:badge>
                        @elseif ($assignment->isPastDue())
                            <flux:badge color="red">{{ __('Missing') }}</flux:badge>
                        @endif
                    </div>
                    @if ($assignment->instructions)
                        <flux:text class="whitespace-pre-line text-sm">{{ $assignment->instructions }}</flux:text>
                    @endif
                    @if ($submission?->feedback)
                        <flux:callout icon="chat-bubble-left" :heading="__('Feedback')" :text="$submission->feedback" />
                    @endif
                    @if ($submission?->file_path)
                        <flux:link :href="route('files.show', ['submission', $submission->id])" target="_blank" class="text-sm">{{ $submission->original_name }}</flux:link>
                    @endif
                    @if (! $offering->semester->isLocked() && ! $submission?->graded_at && (! $assignment->isPastDue() || $assignment->allow_late))
                        <flux:button size="sm" :variant="$submission ? 'ghost' : 'primary'" icon="arrow-up-tray" wire:click="openSubmit({{ $assignment->id }})">
                            {{ $submission ? __('Replace submission') : __('Submit') }}
                        </flux:button>
                    @endif
                </flux:card>
            @empty
                <flux:text>{{ __('No assignments yet.') }}</flux:text>
            @endforelse
        </div>
    @elseif ($tab === 'quizzes')
        <div class="flex flex-col gap-3">
            @forelse ($this->quizzes as $quiz)
                @php($open = $quiz->attempts->first(fn ($attempt) => $attempt->submitted_at === null))
                @php($used = $quiz->attempts->count())
                <flux:card wire:key="quiz-{{ $quiz->id }}" class="space-y-2">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <flux:heading>{{ $quiz->title }}</flux:heading>
                            <flux:text class="text-sm">
                                {{ $quiz->opens_at->translatedFormat('j M H:i') }} – {{ $quiz->closes_at->translatedFormat('j M H:i') }}
                                @if ($quiz->duration_minutes) · {{ __(':minutes min', ['minutes' => $quiz->duration_minutes]) }} @endif
                                · {{ __('attempts :used/:max', ['used' => $used, 'max' => $quiz->max_attempts]) }}
                            </flux:text>
                        </div>
                        @foreach ($quiz->attempts->whereNotNull('submitted_at') as $attempt)
                            <flux:badge size="sm" :color="$attempt->needs_manual_grading ? 'amber' : 'green'">
                                {{ $attempt->needs_manual_grading ? __('Waiting for essay grading') : __(':score / :max', ['score' => $attempt->score, 'max' => $attempt->max_score]) }}
                            </flux:badge>
                        @endforeach
                    </div>
                    @if ($quiz->description)
                        <flux:text class="whitespace-pre-line text-sm">{{ $quiz->description }}</flux:text>
                    @endif
                    @if ($open && $open->isInProgress())
                        <flux:button size="sm" variant="primary" :href="route('mahasantri.quiz-attempt', $open)" wire:navigate>{{ __('Continue') }}</flux:button>
                    @elseif ($quiz->isOpen() && $used < $quiz->max_attempts && ! $offering->semester->isLocked())
                        <flux:button size="sm" variant="primary" icon="play" wire:click="startQuiz({{ $quiz->id }})"
                            wire:confirm="{{ $quiz->duration_minutes ? __('The timer starts now (:minutes minutes). Start?', ['minutes' => $quiz->duration_minutes]) : __('Start the quiz?') }}">
                            {{ $used > 0 ? __('Try again') : __('Start') }}
                        </flux:button>
                    @elseif (now()->lessThan($quiz->opens_at))
                        <flux:text class="text-sm">{{ __('Opens :date', ['date' => $quiz->opens_at->translatedFormat('l, j M H:i')]) }}</flux:text>
                    @endif
                </flux:card>
            @empty
                <flux:text>{{ __('No quizzes yet.') }}</flux:text>
            @endforelse
        </div>
    @else
        <livewire:pages::shared.forum :offering="$offering" />
    @endif

    <flux:modal name="submit-assignment" class="w-full max-w-lg">
        <form wire:submit="submit" class="space-y-6">
            <flux:heading size="lg">{{ __('Submit assignment') }}</flux:heading>
            <flux:textarea wire:model="text" :label="__('Answer')" rows="6" />
            <div>
                <flux:label>{{ __('File (optional)') }}</flux:label>
                <input type="file" wire:model="file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.zip"
                    class="mt-2 block text-sm file:me-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 dark:file:bg-zinc-700">
                <flux:text class="mt-1 text-xs">{{ __('PDF, Word, image or zip, up to 10 MB.') }}</flux:text>
                <flux:error name="file" />
            </div>
            <flux:error name="submission" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="file">{{ __('Submit') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
