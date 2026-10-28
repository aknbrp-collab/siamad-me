<?php

use App\Enums\PrintDocument;
use App\Actions\Exams\ExamEligibility;
use App\Actions\Exams\ManageExams;
use App\Enums\Gender;
use App\Enums\TeacherStatus;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\ExamDispensation;
use App\Models\ExamPeriod;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\Student;
use App\Models\Teacher;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Exam period')] class extends Component {
    use WithPagination;

    public ExamPeriod $period;

    #[Url]
    public string $tab = 'schedule';

    public string $search = '';

    // Exam session form.
    public ?int $sessionId = null;
    public ?int $course_offering_id = null;
    public ?string $held_on = null;
    public ?string $starts_at = null;
    public ?string $ends_at = null;
    public ?int $invigilator_id = null;

    /**
     * The period's rooms (one seat per mahasantri for the whole period).
     *
     * @var list<int|string>
     */
    public array $periodRoomIds = [];

    // Dispensation form.
    public ?int $dispensationStudentId = null;
    public string $letterNumber = '';
    public ?string $letterDate = null;
    public ?string $letterNote = null;

    public function mount(ExamPeriod $period): void
    {
        $this->period = $period->load(['semester.academicYear', 'rooms']);
        $this->periodRoomIds = $this->period->rooms->modelKeys();
    }

    #[Computed]
    public function editable(): bool
    {
        return ! $this->period->isPublished() && ! $this->period->semester->isReadOnly();
    }

    /**
     * @return Collection<int, ExamSession>
     */
    #[Computed]
    public function sessions(): Collection
    {
        return $this->period->sessions()
            ->with(['courseOffering.classGroup', 'invigilator.user'])
            ->orderBy('held_on')->orderBy('starts_at')
            ->get();
    }

    /**
     * Rooms of each exam, from where its mahasantri sit: session id => room names.
     *
     * @return array<int, list<string>>
     */
    #[Computed]
    public function sessionRooms(): array
    {
        $roomsByStudent = $this->period->seats()->with('room')->get()->mapWithKeys(fn ($seat) => [$seat->student_id => $seat->room->name]);
        $students = CourseEnrollment::query()
            ->whereIn('course_offering_id', $this->sessions->pluck('course_offering_id'))
            ->get(['course_offering_id', 'student_id'])
            ->groupBy('course_offering_id');

        return $this->sessions->mapWithKeys(fn (ExamSession $session) => [$session->id => collect($students->get($session->course_offering_id, []))
            ->map(fn ($enrollment) => $roomsByStudent->get($enrollment->student_id))
            ->filter()->unique()->sort()->values()->all()])->all();
    }

    /**
     * Participants and period seats per gender, to compare with room capacity.
     *
     * @return list<array{gender: string, students: int, seats: int}>
     */
    #[Computed]
    public function capacity(): array
    {
        $students = app(ManageExams::class)->participants($this->period)->get()->countBy(fn (Student $student) => $student->gender->value);
        $rooms = $this->rooms->whereIn('id', array_map('intval', $this->periodRoomIds));

        return collect(Gender::cases())->map(fn (Gender $gender) => [
            'gender' => $gender->classLabel(),
            'students' => (int) $students->get($gender->value, 0),
            'seats' => (int) $rooms->where('gender', $gender)->sum('capacity'),
        ])->all();
    }

    /**
     * Offerings of the semester without an exam in this period yet.
     *
     * @return Collection<int, CourseOffering>
     */
    #[Computed]
    public function unscheduled(): Collection
    {
        return CourseOffering::query()
            ->with('classGroup')
            ->where('semester_id', $this->period->semester_id)
            ->whereNotIn('id', $this->sessions->pluck('course_offering_id'))
            ->orderBy('subject_name')
            ->get();
    }

    /**
     * @return Collection<int, CourseOffering>
     */
    #[Computed]
    public function offeringChoices(): Collection
    {
        return CourseOffering::query()
            ->with('classGroup')
            ->where('semester_id', $this->period->semester_id)
            ->whereNotIn('id', $this->sessions->where('id', '!=', $this->sessionId)->pluck('course_offering_id'))
            ->orderBy('subject_name')
            ->get();
    }

    /**
     * @return Collection<int, Room>
     */
    #[Computed]
    public function rooms(): Collection
    {
        return Room::query()->where('is_active', true)->orderBy('gender')->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Teacher>
     */
    #[Computed]
    public function teachers(): Collection
    {
        return Teacher::query()->with('user')->where('status', TeacherStatus::Active)->get()->sortBy('user.name')->values();
    }

    /**
     * Mahasantri taking at least one exam in this period.
     *
     * @return LengthAwarePaginator<int, Student>
     */
    #[Computed]
    public function participants(): LengthAwarePaginator
    {
        $term = '%'.mb_strtolower(trim($this->search)).'%';

        return Student::query()
            ->with('user')
            ->whereIn('id', CourseEnrollment::query()
                ->whereIn('course_offering_id', $this->sessions->pluck('course_offering_id'))
                ->select('student_id'))
            ->when(trim($this->search) !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereRaw('lower(nim) like ?', [$term])
                ->orWhereHas('user', fn ($query) => $query->whereRaw('lower(name) like ?', [$term]))))
            ->orderBy('nim')
            ->paginate(25);
    }

    /**
     * Card status per participant on this page, by student id.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function statuses(): array
    {
        $eligibility = app(ExamEligibility::class);

        return collect($this->participants->items())
            ->mapWithKeys(fn (Student $student) => [$student->id => $eligibility->check($this->period, $student)])
            ->all();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function createSession(): void
    {
        $this->reset('sessionId', 'course_offering_id', 'starts_at', 'ends_at', 'invigilator_id');
        $this->held_on = $this->period->starts_on->toDateString();
        $this->resetErrorBag();
        Flux::modal('session-form')->show();
    }

    public function editSession(int $id): void
    {
        $session = $this->period->sessions()->findOrFail($id);

        $this->sessionId = $session->id;
        $this->course_offering_id = $session->course_offering_id;
        $this->held_on = $session->held_on->toDateString();
        $this->starts_at = substr($session->starts_at, 0, 5);
        $this->ends_at = substr($session->ends_at, 0, 5);
        $this->invigilator_id = $session->invigilator_id;
        $this->resetErrorBag();
        Flux::modal('session-form')->show();
    }

    public function saveSession(ManageExams $exams): void
    {
        $this->resetErrorBag();

        $exams->saveSession($this->period, $this->sessionId ? $this->period->sessions()->findOrFail($this->sessionId) : null, [
            'course_offering_id' => $this->course_offering_id,
            'held_on' => $this->held_on,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'invigilator_id' => $this->invigilator_id ?: null,
        ]);

        Flux::modal('session-form')->close();
        $this->period->refresh();
        unset($this->sessions, $this->unscheduled, $this->sessionRooms, $this->capacity);
        Flux::toast(variant: 'success', text: __('Exam saved.'));
    }

    public function deleteSession(int $id, ManageExams $exams): void
    {
        $this->resetErrorBag();
        $exams->deleteSession($this->period->sessions()->findOrFail($id));
        $this->period->refresh();
        unset($this->sessions, $this->unscheduled, $this->sessionRooms, $this->capacity);
    }

    public function saveRooms(ManageExams $exams): void
    {
        $this->resetErrorBag();
        $exams->setRooms($this->period, array_values(array_map('intval', $this->periodRoomIds)));
        $this->period->refresh();
        unset($this->sessionRooms, $this->capacity);
        Flux::toast(variant: 'success', text: __('Rooms saved.'));
    }

    public function assignSeats(ManageExams $exams): void
    {
        $this->resetErrorBag();
        $count = $exams->assignSeats($this->period);
        $this->period->refresh();
        unset($this->sessionRooms, $this->statuses);
        Flux::toast(variant: 'success', text: trans_choice(':count seat assigned.|:count seats assigned.', $count));
    }

    public function togglePublish(ManageExams $exams): void
    {
        $this->resetErrorBag();
        $exams->publish($this->period, ! $this->period->isPublished());
        $this->period->refresh();
        unset($this->editable);
        Flux::toast(variant: 'success', text: $this->period->isPublished()
            ? __('Published. Mahasantri can print their exam card once they meet the requirements.')
            : __('Back to draft. The schedule and seats can be changed again.'));
    }

    public function openDispensation(int $studentId): void
    {
        $this->reset('letterNumber', 'letterNote');
        $this->dispensationStudentId = $studentId;
        $this->letterDate = now()->toDateString();
        $this->resetErrorBag();
        Flux::modal('dispensation-form')->show();
    }

    public function saveDispensation(ManageExams $exams): void
    {
        $this->resetErrorBag();

        $exams->grantDispensation($this->period, Student::query()->findOrFail($this->dispensationStudentId), [
            'letter_number' => trim($this->letterNumber),
            'letter_date' => $this->letterDate,
            'note' => $this->letterNote ?: null,
        ], auth()->user());

        Flux::modal('dispensation-form')->close();
        unset($this->statuses);
        Flux::toast(variant: 'success', text: __('Dispensation recorded. The exam card can be printed.'));
    }

    public function revokeDispensation(int $studentId, ManageExams $exams): void
    {
        $exams->revokeDispensation(ExamDispensation::query()->where('exam_period_id', $this->period->id)->where('student_id', $studentId)->firstOrFail());
        unset($this->statuses);
    }
}; ?>

<section class="w-full">
    <x-page-header :title="$period->type->label()" :subtitle="$period->semester->label().' · '.$period->starts_on->translatedFormat('j M').' – '.$period->ends_on->translatedFormat('j M Y')">
        <x-slot:actions>
            <flux:badge size="lg" :color="$period->isPublished() ? 'green' : 'zinc'">{{ $period->isPublished() ? __('Published') : __('Draft') }}</flux:badge>
            @unless ($period->semester->isReadOnly())
                <flux:button :variant="$period->isPublished() ? 'ghost' : 'primary'" :icon="$period->isPublished() ? 'arrow-uturn-left' : 'megaphone'" wire:click="togglePublish"
                    wire:confirm="{{ $period->isPublished() ? __('Unpublish? Mahasantri can no longer print their card until you publish again.') : __('Publish? The schedule and seats are fixed and mahasantri are notified.') }}"
                    data-test="toggle-publish">
                    {{ $period->isPublished() ? __('Unpublish') : __('Publish') }}
                </flux:button>
            @endunless
            <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('admin.exams.index', ['semester' => $period->semester_id])" wire:navigate>{{ __('Exams') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    @error('exam')
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-4" :text="$message" />
    @enderror

    <flux:radio.group wire:model.live="tab" variant="segmented" class="mb-6 w-full sm:w-auto">
        <flux:radio value="schedule" :label="__('Exam schedule')" icon="calendar-days" />
        <flux:radio value="participants" :label="__('Participants & cards')" icon="identification" />
    </flux:radio.group>

    @if ($tab === 'schedule')
        <flux:card class="mb-6 space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <flux:heading>{{ __('Rooms and seats') }}</flux:heading>
                    <flux:text class="text-sm">{{ __('Each mahasantri gets one room and seat number for every exam of this period. Putra sit only in putra rooms, putri only in putri rooms.') }}</flux:text>
                </div>
                @if ($period->seats_assigned_at)
                    <flux:badge color="green" icon="check">{{ __('Seats shuffled :date', ['date' => $period->seats_assigned_at->translatedFormat('j M H:i')]) }}</flux:badge>
                @else
                    <flux:badge color="amber">{{ __('Seats not shuffled') }}</flux:badge>
                @endif
            </div>

            @if ($this->rooms->isEmpty())
                <flux:callout icon="information-circle" :text="__('Add exam rooms first.')">
                    <x-slot name="actions"><flux:button size="sm" :href="route('admin.rooms')" wire:navigate>{{ __('Exam rooms') }}</flux:button></x-slot>
                </flux:callout>
            @else
                <flux:checkbox.group wire:model="periodRoomIds" class="grid gap-2 sm:grid-cols-3" :disabled="! $this->editable">
                    @foreach ($this->rooms as $room)
                        <flux:checkbox :value="$room->id" :label="$room->label()" />
                    @endforeach
                </flux:checkbox.group>
                <div class="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                    @foreach ($this->capacity as $row)
                        <span @class(['text-red-600 dark:text-red-400' => $row['students'] > $row['seats']])>
                            {{ __(':gender: :students mahasantri, :seats seats', $row) }}
                        </span>
                    @endforeach
                </div>
                @if ($this->editable)
                    <div class="flex flex-wrap gap-2">
                        <flux:button size="sm" wire:click="saveRooms" icon="check" data-test="save-rooms">{{ __('Save rooms') }}</flux:button>
                        <flux:button size="sm" variant="primary" icon="arrows-right-left" wire:click="assignSeats" :disabled="$this->sessions->isEmpty()" data-test="shuffle-seats">{{ __('Shuffle seats') }}</flux:button>
                    </div>
                    <flux:text class="text-xs">{{ __('Changing the rooms or the exams clears the seats; shuffle again before publishing.') }}</flux:text>
                @endif
            @endif
        </flux:card>

        @if ($this->editable)
            <div class="mb-4">
                <flux:button variant="primary" icon="plus" wire:click="createSession" :disabled="$this->unscheduled->isEmpty()">{{ __('Add exam') }}</flux:button>
            </div>
        @endif

        <div class="overflow-x-auto">
            <flux:table class="table-stack">
                <flux:table.columns>
                    <flux:table.column>{{ __('Subject') }}</flux:table.column>
                    <flux:table.column>{{ __('Time') }}</flux:table.column>
                    <flux:table.column>{{ __('Rooms') }}</flux:table.column>
                    <flux:table.column>{{ __('Invigilator') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->sessions as $session)
                        <flux:table.row :key="$session->id">
                            <flux:table.cell class="whitespace-normal">
                                <span class="font-medium">{{ $session->courseOffering->subject_name }}</span>
                                <div class="text-xs text-zinc-500">{{ $session->courseOffering->class_name }}</div>
                            </flux:table.cell>
                            <flux:table.cell>{{ $session->timeLabel() }}</flux:table.cell>
                            <flux:table.cell class="whitespace-normal">{{ implode(', ', $this->sessionRooms[$session->id] ?? []) ?: '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $session->invigilator?->user->name ?? '—' }}</flux:table.cell>
                            <flux:table.cell align="end">
                                @if ($this->editable)
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editSession({{ $session->id }})" :aria-label="__('Edit')" />
                                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteSession({{ $session->id }})" wire:confirm="{{ __('Remove this exam?') }}" :aria-label="__('Remove')" />
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="text-center">{{ __('No exams scheduled yet.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>

        @if ($this->unscheduled->isNotEmpty())
            <flux:text class="mt-4 text-sm">{{ __('Still without an exam: :subjects', ['subjects' => $this->unscheduled->map(fn ($offering) => $offering->subject_name.' ('.$offering->class_name.')')->join(', ')]) }}</flux:text>
        @endif
    @else
        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="__('Search NIM or name…')" class="mb-4 sm:max-w-sm" />

        <div class="overflow-x-auto">
            <flux:table class="table-stack" :paginate="$this->participants">
                <flux:table.columns>
                    <flux:table.column>{{ __('Mahasantri') }}</flux:table.column>
                    <flux:table.column>{{ __('Seat') }}</flux:table.column>
                    <flux:table.column>{{ __('Payment') }}</flux:table.column>
                    <flux:table.column>{{ __('Subjects on the card') }}</flux:table.column>
                    <flux:table.column>{{ __('Dispensation') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->participants as $student)
                        @php($status = $this->statuses[$student->id])
                        @php($subjects = collect($status['subjects']))
                        <flux:table.row :key="$student->id">
                            <flux:table.cell>
                                <span class="font-medium">{{ $student->user->name }}</span>
                                <span class="ms-1 font-mono text-xs text-zinc-500">{{ $student->nim }}</span>
                            </flux:table.cell>
                            <flux:table.cell class="text-sm">{{ $status['seat'] ? __('Room :room · seat :seat', ['room' => $status['seat']->room->name, 'seat' => $status['seat']->seat_number]) : '—' }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($status['payment_ok'])
                                    <flux:badge size="sm" color="green">{{ __('Paid') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="red">{{ __('Still to pay :amount', ['amount' => Money::format($status['outstanding'])]) }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $subjects->where('ok', true)->count() }} / {{ $subjects->count() }}</flux:table.cell>
                            <flux:table.cell class="whitespace-normal">
                                @if ($status['dispensation'])
                                    <span class="text-sm">{{ $status['dispensation']->letter_number }}</span>
                                    @unless ($period->semester->isReadOnly())
                                        <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="revokeDispensation({{ $student->id }})" wire:confirm="{{ __('Remove this dispensation?') }}" :aria-label="__('Remove')" />
                                    @endunless
                                @else
                                    —
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                @if ($status['eligible'] && $status['seat'] !== null)
                                    <x-print-button size="sm" icon="printer" :href="route('exams.card', [$period, $student])" :document="PrintDocument::ExamCard">{{ __('Card') }}</x-print-button>
                                @elseif (! $status['dispensation'] && ! $period->semester->isReadOnly())
                                    <flux:button size="sm" variant="ghost" icon="document-check" wire:click="openDispensation({{ $student->id }})" data-test="dispensation-{{ $student->id }}">{{ __('Dispensation') }}</flux:button>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6" class="text-center">{{ __('Schedule exams to see the participants.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    <flux:modal name="session-form" class="w-full max-w-lg">
        <form wire:submit="saveSession" class="space-y-5">
            <flux:heading size="lg">{{ $sessionId ? __('Edit exam') : __('Add exam') }}</flux:heading>
            <flux:select wire:model="course_offering_id" :label="__('Subject')" :placeholder="__('Choose…')" required>
                @foreach ($this->offeringChoices as $offering)
                    <flux:select.option :value="$offering->id">{{ $offering->subject_name }} — {{ $offering->class_name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="grid gap-5 sm:grid-cols-3">
                <flux:input wire:model="held_on" :label="__('Date')" type="date" required />
                <flux:input wire:model="starts_at" :label="__('Starts')" type="time" required />
                <flux:input wire:model="ends_at" :label="__('Ends')" type="time" required />
            </div>
            <flux:select wire:model="invigilator_id" :label="__('Invigilator')">
                <flux:select.option value="">{{ __('— none —') }}</flux:select.option>
                @foreach ($this->teachers as $teacher)
                    <flux:select.option :value="$teacher->id">{{ $teacher->displayName() }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-exam">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="dispensation-form" class="w-full max-w-md">
        <form wire:submit="saveDispensation" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Dispensation') }}</flux:heading>
                <flux:text class="mt-1">{{ __('With a letter from the rector, the exam card can be printed even though the requirements are not met. The letter is shown on the card and kept in the audit log.') }}</flux:text>
            </div>
            <flux:input wire:model="letterNumber" :label="__('Letter number')" required />
            <flux:error name="letter_number" />
            <flux:input wire:model="letterDate" :label="__('Letter date')" type="date" required />
            <flux:error name="letter_date" />
            <flux:textarea wire:model="letterNote" :label="__('Note (optional)')" rows="2" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-dispensation">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
