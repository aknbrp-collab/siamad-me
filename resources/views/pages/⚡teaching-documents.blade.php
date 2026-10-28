<?php

use App\Actions\Teaching\ManageTeachingDocuments;
use App\Enums\Role;
use App\Enums\TeachingDocumentKind;
use App\Enums\TeachingDocumentStatus;
use App\Models\CourseOffering;
use App\Models\Semester;
use App\Models\TeachingDocument;
use App\Support\ActiveRole;
use App\Support\SpreadsheetDownload;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new #[Title('Teaching documents')] class extends Component {
    #[Url(as: 'semester')]
    public ?int $semesterId = null;

    /** all | missing | waiting | returned | complete */
    #[Url]
    public string $filter = 'all';

    public string $search = '';

    // Review.
    public ?int $reviewingId = null;
    public string $reviewNote = '';

    // Join.
    public ?int $joinLeadId = null;

    /** @var list<int|string> */
    public array $joinIds = [];

    public function mount(): void
    {
        $this->semesterId ??= Semester::active()?->id ?? Semester::query()->orderByDesc('id')->value('id');
    }

    #[Computed]
    public function manages(): bool
    {
        return in_array(ActiveRole::get(), [Role::Admin, Role::Superadmin], true);
    }

    /**
     * @return Collection<int, Semester>
     */
    #[Computed]
    public function semesters(): Collection
    {
        return Semester::query()->with('academicYear')->orderByDesc('academic_year_id')->orderByDesc('term')->get();
    }

    #[Computed]
    public function semester(): ?Semester
    {
        return $this->semesters->firstWhere('id', $this->semesterId);
    }

    /**
     * One row per class or joint group: lead, members, latest RPS and journal.
     *
     * @return SupportCollection<int, array{lead: CourseOffering, members: SupportCollection<int, CourseOffering>, rps: TeachingDocument|null, journal: TeachingDocument|null}>
     */
    #[Computed]
    public function units(): SupportCollection
    {
        $offerings = CourseOffering::query()->with(['classGroup', 'teacher.user'])
            ->where('semester_id', $this->semesterId)->orderBy('subject_name')->orderBy('id')->get();

        $latest = TeachingDocument::query()->whereIn('course_offering_id', $offerings->modelKeys())
            ->orderByDesc('version')->get()
            ->unique(fn (TeachingDocument $document) => $document->course_offering_id.'|'.$document->kind->value)
            ->keyBy(fn (TeachingDocument $document) => $document->course_offering_id.'|'.$document->kind->value);

        $term = mb_strtolower(trim($this->search));

        return $offerings->whereNull('joined_to_id')->values()
            ->map(fn (CourseOffering $lead) => [
                'lead' => $lead,
                'members' => $offerings->filter(fn (CourseOffering $offering) => $offering->id === $lead->id || $offering->joined_to_id === $lead->id)->values(),
                'rps' => $latest->get($lead->id.'|rps'),
                'journal' => $latest->get($lead->id.'|journal'),
            ])
            ->filter(fn (array $unit) => $term === '' || str_contains(mb_strtolower($unit['lead']->subject_name.' '.$unit['lead']->subject_code.' '.$unit['lead']->teacher->user->name), $term))
            ->filter(fn (array $unit) => match ($this->filter) {
                'missing' => $unit['rps'] === null || $unit['journal'] === null,
                'waiting' => $unit['rps']?->status === TeachingDocumentStatus::Waiting || $unit['journal']?->status === TeachingDocumentStatus::Waiting,
                'returned' => $unit['rps']?->status === TeachingDocumentStatus::Returned || $unit['journal']?->status === TeachingDocumentStatus::Returned,
                'complete' => $unit['rps']?->status === TeachingDocumentStatus::Accepted && $unit['journal']?->status === TeachingDocumentStatus::Accepted,
                default => true,
            })
            ->values();
    }

    #[Computed]
    public function reviewing(): ?TeachingDocument
    {
        return $this->reviewingId ? TeachingDocument::query()->with('courseOffering.teacher.user')->find($this->reviewingId) : null;
    }

    /**
     * Offerings that can join the chosen class: same subject and muhadhir.
     *
     * @return Collection<int, CourseOffering>
     */
    #[Computed]
    public function joinCandidates(): Collection
    {
        $lead = $this->joinLeadId ? CourseOffering::query()->find($this->joinLeadId) : null;

        return $lead === null ? new Collection : CourseOffering::query()->with('classGroup')
            ->where('semester_id', $lead->semester_id)->where('subject_id', $lead->subject_id)->where('teacher_id', $lead->teacher_id)
            ->whereKeyNot($lead->id)->where(fn ($query) => $query->whereNull('joined_to_id')->orWhere('joined_to_id', '!=', $lead->id))
            ->orderBy('id')->get();
    }

    public function startReview(int $id): void
    {
        abort_unless($this->manages, 403);
        $this->reviewingId = TeachingDocument::query()->findOrFail($id)->id;
        $this->reviewNote = '';
        $this->resetErrorBag();
        Flux::modal('review')->show();
    }

    public function review(bool $accept, ManageTeachingDocuments $documents): void
    {
        abort_unless($this->manages, 403);
        $this->resetErrorBag();
        $documents->review(TeachingDocument::query()->findOrFail($this->reviewingId), $accept, $this->reviewNote, auth()->user());
        Flux::modal('review')->close();
        unset($this->units);
        Flux::toast(variant: 'success', text: $accept ? __('Accepted.') : __('Returned to the muhadhir with your note.'));
    }

    public function startJoin(int $leadId): void
    {
        abort_unless($this->manages, 403);
        $this->joinLeadId = $leadId;
        $this->joinIds = [];
        unset($this->joinCandidates);
        $this->resetErrorBag();
        Flux::modal('join')->show();
    }

    public function join(ManageTeachingDocuments $documents): void
    {
        abort_unless($this->manages, 403);
        $this->resetErrorBag();
        $documents->join(array_values(array_map('intval', [$this->joinLeadId, ...$this->joinIds])));
        Flux::modal('join')->close();
        unset($this->units);
        Flux::toast(variant: 'success', text: __('Classes joined: one RPS and one journal for all of them.'));
    }

    public function split(int $leadId, ManageTeachingDocuments $documents): void
    {
        abort_unless($this->manages, 403);
        $this->resetErrorBag();
        $documents->split(CourseOffering::query()->findOrFail($leadId));
        unset($this->units);
        Flux::toast(variant: 'success', text: __('Classes separated.'));
    }

    public function export(): BinaryFileResponse
    {
        $status = fn (?TeachingDocument $document) => $document === null ? __('Not uploaded') : $document->status->label().' (v'.$document->version.')';

        return SpreadsheetDownload::make('dokumen-mengajar-'.$this->semesterId.'.xlsx', $this->units->map(fn (array $unit) => [
            __('Subject') => $unit['lead']->subject_code.' '.$unit['lead']->subject_name,
            __('Classes') => $unit['members']->map(fn (CourseOffering $member) => $member->class_name)->join(', '),
            __('Muhadhir') => $unit['lead']->teacher->user->name,
            'RPS' => $status($unit['rps']),
            __('Journal') => $status($unit['journal']),
        ]));
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Teaching documents')" :subtitle="__('The RPS and the signed teaching journal of every class this semester. Classes taught together share one set.')" />

    @error('join')
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-4" :text="$message" />
    @enderror

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <flux:select wire:model.live="semesterId" :label="__('Semester')" class="sm:max-w-60">
            @foreach ($this->semesters as $item)
                <flux:select.option :value="$item->id">{{ $item->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="filter" :label="__('Show')" class="sm:max-w-52">
            <flux:select.option value="all">{{ __('All classes') }}</flux:select.option>
            <flux:select.option value="missing">{{ __('Something missing') }}</flux:select.option>
            <flux:select.option value="waiting">{{ __('Waiting for review') }}</flux:select.option>
            <flux:select.option value="returned">{{ __('Returned') }}</flux:select.option>
            <flux:select.option value="complete">{{ __('Both accepted') }}</flux:select.option>
        </flux:select>
        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="__('Subject or muhadhir…')" class="sm:max-w-64" />
        <flux:button size="sm" variant="ghost" icon="arrow-down-tray" wire:click="export" class="ms-auto">{{ __('Excel') }}</flux:button>
    </div>

    <div class="overflow-x-auto">
        <flux:table class="table-stack">
            <flux:table.columns>
                <flux:table.column>{{ __('Subject & classes') }}</flux:table.column>
                <flux:table.column>{{ __('Muhadhir') }}</flux:table.column>
                @foreach (TeachingDocumentKind::cases() as $kind)
                    <flux:table.column>{{ $kind->short() }}</flux:table.column>
                @endforeach
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->units as $unit)
                    @php($lead = $unit['lead'])
                    <flux:table.row :key="$lead->id">
                        <flux:table.cell class="whitespace-normal">
                            <span class="font-medium">{{ $lead->subject_name }}</span>
                            <div class="text-xs text-zinc-500">
                                {{ $unit['members']->map(fn ($member) => $member->class_name)->join(' + ') }}
                                @if ($unit['members']->count() > 1)<flux:badge size="sm" icon="link" class="ms-1">{{ __('Together') }}</flux:badge>@endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="text-sm">{{ $lead->teacher->user->name }}</flux:table.cell>
                        @foreach (['rps', 'journal'] as $kind)
                            @php($document = $unit[$kind])
                            <flux:table.cell>
                                @if ($document)
                                    <div class="flex flex-wrap items-center gap-1">
                                        <flux:badge size="sm" :color="$document->status->color()">{{ $document->status->label() }}</flux:badge>
                                        <flux:link :href="route('files.show', ['teaching-document', $document->id])" target="_blank" class="text-xs">v{{ $document->version }}</flux:link>
                                        @if ($this->manages && $document->status === TeachingDocumentStatus::Waiting)
                                            <flux:button size="xs" variant="ghost" icon="check-badge" wire:click="startReview({{ $document->id }})" data-test="review-{{ $document->id }}">{{ __('Review') }}</flux:button>
                                        @endif
                                    </div>
                                @else
                                    <flux:badge size="sm" color="zinc">{{ __('Not uploaded') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                        @endforeach
                        <flux:table.cell align="end">
                            <flux:button size="sm" variant="ghost" icon="printer" :href="route('teaching.journal', $lead)" target="_blank" :aria-label="__('Journal')" />
                            @if ($this->manages && ! $this->semester?->isReadOnly())
                                @if ($unit['members']->count() > 1)
                                    @if (! $unit['rps'] && ! $unit['journal'])
                                        <flux:button size="sm" variant="ghost" icon="scissors" wire:click="split({{ $lead->id }})" wire:confirm="{{ __('Separate these classes again?') }}" :aria-label="__('Separate')" />
                                    @endif
                                @elseif (! $unit['rps'] && ! $unit['journal'])
                                    <flux:button size="sm" variant="ghost" icon="link" wire:click="startJoin({{ $lead->id }})" data-test="join-{{ $lead->id }}">{{ __('Join classes') }}</flux:button>
                                @endif
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-center">{{ __('No classes match.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="review" class="w-full max-w-md">
        @if ($this->reviewing)
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Review :kind', ['kind' => $this->reviewing->kind->short()]) }}</flux:heading>
                    <flux:text class="mt-1">{{ $this->reviewing->courseOffering->subject_name }} · {{ $this->reviewing->courseOffering->teacher->user->name }} · {{ __('Version :version', ['version' => $this->reviewing->version]) }}</flux:text>
                </div>
                <flux:button size="sm" icon="arrow-down-tray" :href="route('files.show', ['teaching-document', $this->reviewing->id])" target="_blank">{{ __('Open the file') }}</flux:button>
                <flux:textarea wire:model="reviewNote" :label="__('Note to the muhadhir (required when returning)')" rows="3" />
                <flux:error name="reviewNote" />
                <div class="flex justify-end gap-2">
                    <flux:button variant="ghost" icon="arrow-uturn-left" wire:click="review(false)" data-test="return-document">{{ __('Return') }}</flux:button>
                    <flux:button variant="primary" icon="check" wire:click="review(true)" data-test="accept-document">{{ __('Accept') }}</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    <flux:modal name="join" class="w-full max-w-md">
        <form wire:submit="join" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Join classes taught together') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Choose the classes of the same subject and muhadhir that sit in one session. They will share one RPS and one journal.') }}</flux:text>
            </div>
            @if ($this->joinCandidates->isEmpty())
                <flux:text class="text-sm">{{ __('No other class has this subject with this muhadhir in the semester.') }}</flux:text>
            @else
                <flux:checkbox.group wire:model="joinIds">
                    @foreach ($this->joinCandidates as $candidate)
                        <flux:checkbox :value="$candidate->id" :label="$candidate->class_name" />
                    @endforeach
                </flux:checkbox.group>
            @endif
            <flux:error name="join" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" :disabled="$this->joinCandidates->isEmpty()" data-test="confirm-join">{{ __('Join') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
