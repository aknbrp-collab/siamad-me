<?php

use App\Actions\Teaching\AcademicRecord;
use App\Actions\Teaching\ManageMeetings;
use App\Enums\AttendanceStatus;
use App\Enums\MeetingMode;
use App\Enums\MeetingProvider;
use App\Models\ClassSession;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\Setting;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Meetings & attendance')] class extends Component {
    public CourseOffering $offering;

    // Meeting form
    public ?int $sessionId = null;
    public ?string $heldOn = null;
    public ?string $topic = null;
    public string $mode = 'offline';
    public ?string $provider = null;
    public ?string $url = null;

    // Jurnal mengajar
    public ?string $material = null;
    public ?string $method = null;
    public ?string $journalNote = null;

    // Attendance sheet
    public ?int $takingId = null;

    /** @var array<int|string, string> enrollment id => status */
    public array $statuses = [];

    /** @var array<int|string, string|null> */
    public array $notes = [];

    public function mount(CourseOffering $offering): void
    {
        abort_unless($offering->teacher()->value('user_id') === auth()->id(), 403);

        $this->offering = $offering->load(['classGroup', 'semester.academicYear']);
    }

    #[Computed]
    public function locked(): bool
    {
        return $this->offering->semester->isLocked();
    }

    /**
     * @return Collection<int, ClassSession>
     */
    #[Computed]
    public function sessions(): Collection
    {
        return $this->offering->sessions()->withCount([
            'attendances as present_count' => fn ($query) => $query->where('status', AttendanceStatus::Present),
        ])->get();
    }

    /**
     * @return Collection<int, CourseEnrollment>
     */
    #[Computed]
    public function enrollments(): Collection
    {
        return $this->offering->enrollments()->with('student.user')->get()->sortBy('student.user.name')->values();
    }

    /**
     * @return array<int, array{hadir: int, izin: int, sakit: int, alpa: int, total: int, percent: int|null}>
     */
    #[Computed]
    public function summary(): array
    {
        return app(AcademicRecord::class)->attendance($this->enrollments->modelKeys());
    }

    #[Computed]
    public function minimum(): int
    {
        return (int) Setting::get('attendance.min_percent', 75);
    }

    #[Computed]
    public function taking(): ?ClassSession
    {
        return $this->sessions->firstWhere('id', $this->takingId);
    }

    public function createMeeting(): void
    {
        $this->reset('sessionId', 'topic', 'provider', 'url', 'material', 'method', 'journalNote');
        $this->heldOn = now()->format('Y-m-d');
        $this->mode = MeetingMode::Offline->value;
        $this->resetErrorBag();
        Flux::modal('meeting-form')->show();
    }

    public function editMeeting(int $id): void
    {
        $session = $this->offering->sessions()->findOrFail($id);

        $this->sessionId = $session->id;
        $this->heldOn = $session->held_on->format('Y-m-d');
        $this->topic = $session->topic;
        $this->mode = $session->mode->value;
        $this->provider = $session->meeting_provider?->value;
        $this->url = $session->meeting_url;
        $this->material = $session->material;
        $this->method = $session->method;
        $this->journalNote = $session->journal_note;
        $this->resetErrorBag();
        Flux::modal('meeting-form')->show();
    }

    public function saveMeeting(ManageMeetings $meetings): void
    {
        $this->resetErrorBag();

        $meetings->save($this->offering, $this->sessionId ? $this->offering->sessions()->findOrFail($this->sessionId) : null, [
            'held_on' => $this->heldOn,
            'topic' => $this->topic ?: null,
            'mode' => $this->mode,
            'meeting_provider' => $this->provider ?: null,
            'meeting_url' => $this->url ?: null,
            'material' => $this->material ?: null,
            'method' => $this->method ?: null,
            'journal_note' => $this->journalNote ?: null,
        ]);

        Flux::modal('meeting-form')->close();
        unset($this->sessions);
        Flux::toast(variant: 'success', text: __('Meeting saved.'));
    }

    public function deleteMeeting(int $id, ManageMeetings $meetings): void
    {
        $this->resetErrorBag();

        $meetings->delete($this->offering->sessions()->findOrFail($id));

        unset($this->sessions);
        Flux::toast(variant: 'success', text: __('Meeting removed.'));
    }

    public function takeAttendance(int $id): void
    {
        $session = $this->offering->sessions()->with('attendances')->findOrFail($id);
        $existing = $session->attendances->keyBy('course_enrollment_id');

        $this->takingId = $session->id;
        $this->statuses = [];
        $this->notes = [];

        foreach ($this->enrollments as $enrollment) {
            $this->statuses[$enrollment->id] = $existing->get($enrollment->id)?->status->value ?? AttendanceStatus::Present->value;
            $this->notes[$enrollment->id] = $existing->get($enrollment->id)?->note;
        }

        $this->resetErrorBag();
        Flux::modal('attendance')->show();
    }

    public function saveAttendance(ManageMeetings $meetings): void
    {
        $this->resetErrorBag();

        $meetings->recordAttendance($this->offering->sessions()->findOrFail($this->takingId), $this->statuses, $this->notes);

        Flux::modal('attendance')->close();
        unset($this->sessions, $this->summary);
        Flux::toast(variant: 'success', text: __('Attendance saved.'));
    }
}; ?>

<section class="w-full">
    <x-offering-header :offering="$offering" current="meetings" />

    @error('meeting')
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6" :text="$message" />
    @enderror
    @error('semester')
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6" :text="$message" />
    @enderror

    <div class="grid gap-8 xl:grid-cols-[1fr_24rem]">
        <div>
            <div class="mb-3 flex items-center justify-between">
                <flux:heading size="lg">{{ __('Meetings') }}</flux:heading>
                @unless ($this->locked)
                    <flux:button size="sm" variant="primary" icon="plus" wire:click="createMeeting">{{ __('Add meeting') }}</flux:button>
                @endunless
            </div>

            <div class="flex flex-col gap-3">
                @forelse ($this->sessions as $session)
                    <flux:card wire:key="session-{{ $session->id }}" class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <div class="flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">{{ __('Meeting :no', ['no' => $session->meeting_no]) }}</span>
                                <flux:badge size="sm" :color="$session->mode === MeetingMode::Online ? 'blue' : 'zinc'">{{ $session->mode->label() }}</flux:badge>
                                @if ($session->attendance_taken_at)
                                    <flux:badge size="sm" color="green">{{ __(':present of :total present', ['present' => $session->present_count, 'total' => $this->enrollments->count()]) }}</flux:badge>
                                @endif
                            </div>
                            <flux:text class="text-sm">{{ $session->held_on->translatedFormat('l, j M Y') }}@if ($session->topic) · {{ $session->topic }}@endif</flux:text>
                            @if ($session->material)
                                <flux:text class="text-xs">{{ __('Journal: :material', ['material' => Str::limit($session->material, 120)]) }}</flux:text>
                            @endif
                            @if ($session->meeting_url)
                                <flux:link :href="$session->meeting_url" target="_blank" class="text-sm">{{ __('Join on :provider', ['provider' => $session->meeting_provider?->label() ?? __('link')]) }}</flux:link>
                            @endif
                        </div>
                        @unless ($this->locked)
                            <div class="flex gap-1">
                                <flux:button size="sm" :variant="$session->attendance_taken_at ? 'ghost' : 'primary'" icon="clipboard-document-check" wire:click="takeAttendance({{ $session->id }})">
                                    {{ $session->attendance_taken_at ? __('Edit attendance') : __('Take attendance') }}
                                </flux:button>
                                <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editMeeting({{ $session->id }})" :aria-label="__('Edit')" />
                                @unless ($session->attendance_taken_at)
                                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteMeeting({{ $session->id }})" wire:confirm="{{ __('Remove this meeting?') }}" :aria-label="__('Remove')" />
                                @endunless
                            </div>
                        @endunless
                    </flux:card>
                @empty
                    <flux:text>{{ __('No meetings yet.') }}</flux:text>
                @endforelse
            </div>
        </div>

        <div>
            <flux:heading size="lg" class="mb-3">{{ __('Attendance so far') }}</flux:heading>
            <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($this->enrollments as $enrollment)
                    @php($counts = $this->summary[$enrollment->id])
                    <div wire:key="summary-{{ $enrollment->id }}" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                        <span class="truncate">{{ $enrollment->student->user->name }}</span>
                        <span class="flex items-center gap-2">
                            <span class="text-xs text-zinc-500">H{{ $counts['hadir'] }} I{{ $counts['izin'] }} S{{ $counts['sakit'] }} A{{ $counts['alpa'] }}</span>
                            @if ($counts['percent'] !== null)
                                <flux:badge size="sm" :color="$counts['percent'] < $this->minimum ? 'red' : 'green'">{{ $counts['percent'] }}%</flux:badge>
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
            <flux:text class="mt-2 text-xs">{{ __('Below :min% hadir is flagged for the academic monitor.', ['min' => $this->minimum]) }}</flux:text>
        </div>
    </div>

    <flux:modal name="meeting-form" class="w-full max-w-md">
        <form wire:submit="saveMeeting" class="space-y-6">
            <flux:heading size="lg">{{ $sessionId ? __('Edit meeting') : __('Add meeting') }}</flux:heading>
            <flux:input wire:model="heldOn" :label="__('Date')" type="date" required />
            <flux:input wire:model="topic" :label="__('Topic')" :placeholder="__('e.g. Bab Thaharah')" />
            <flux:radio.group wire:model.live="mode" variant="segmented">
                @foreach (MeetingMode::cases() as $case)
                    <flux:radio :value="$case->value" :label="$case->label()" />
                @endforeach
            </flux:radio.group>
            @if ($mode === MeetingMode::Online->value)
                <flux:select wire:model="provider" :label="__('Platform')" :placeholder="__('Choose…')" required>
                    @foreach (MeetingProvider::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="url" :label="__('Meeting link')" type="url" placeholder="https://" required />
                <flux:error name="meeting_url" />
                <flux:error name="meeting_provider" />
            @endif
            <flux:error name="held_on" />
            <flux:separator />
            <div class="space-y-4">
                <div>
                    <flux:heading>{{ __('Teaching journal') }}</flux:heading>
                    <flux:text class="text-xs">{{ __('Printed in the jurnal mengajar. For classes taught together, fill it in for one of them.') }}</flux:text>
                </div>
                <flux:textarea wire:model="material" :label="__('Material covered')" rows="2" />
                <flux:input wire:model="method" :label="__('Method')" :placeholder="__('e.g. bandongan, sorogan, discussion')" />
                <flux:textarea wire:model="journalNote" :label="__('Notes')" rows="2" />
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="attendance" class="w-full max-w-2xl">
        @if ($this->taking)
            <form wire:submit="saveAttendance" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Attendance · meeting :no', ['no' => $this->taking->meeting_no]) }}</flux:heading>
                    <flux:text class="mt-1">{{ $this->taking->held_on->translatedFormat('l, j M Y') }}</flux:text>
                </div>
                <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->enrollments as $enrollment)
                        <div wire:key="att-{{ $enrollment->id }}" class="flex flex-col gap-2 py-2 sm:flex-row sm:items-center">
                            <span class="flex-1 text-sm font-medium">{{ $enrollment->student->user->name }}</span>
                            <flux:radio.group wire:model="statuses.{{ $enrollment->id }}" variant="segmented" size="sm">
                                @foreach (AttendanceStatus::cases() as $status)
                                    <flux:radio :value="$status->value" :label="$status->label()" />
                                @endforeach
                            </flux:radio.group>
                            <flux:input wire:model="notes.{{ $enrollment->id }}" size="sm" :placeholder="__('Note')" class="sm:w-40" />
                        </div>
                    @endforeach
                </div>
                @if ($errors->has('statuses.*'))
                    <flux:callout variant="danger" icon="exclamation-triangle" :text="$errors->first('statuses.*')" />
                @endif
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="save-attendance">{{ __('Save attendance') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</section>
