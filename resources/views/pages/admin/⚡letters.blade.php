<?php

use App\Actions\Letters\ManageLetters;
use App\Enums\LetterRequestStatus;
use App\Enums\LetterSubject;
use App\Enums\PrintDocument;
use App\Models\Letter;
use App\Models\LetterRequest;
use App\Models\LetterType;
use App\Models\Signatory;
use App\Models\Stamp;
use App\Models\Student;
use App\Models\Teacher;
use App\Support\LetterTemplate;
use App\Support\SpreadsheetDownload;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new #[Title('Outgoing letters')] class extends Component {
    use WithPagination;

    #[Url]
    public string $tab = 'register';

    #[Url]
    public ?int $year = null;

    #[Url(as: 'type')]
    public ?int $typeFilter = null;

    public string $search = '';

    // Compose.
    public ?int $requestId = null;
    public ?int $letterTypeId = null;
    public ?int $studentId = null;
    public ?int $teacherId = null;
    public string $personSearch = '';
    public ?string $recipient = null;
    public string $subjectLine = '';
    public string $body = '';
    public ?string $issuedOn = null;
    public ?int $signatoryId = null;
    public ?int $stampId = null;
    public bool $printMarks = true;
    public ?string $purpose = null;

    // Cancel / reject.
    public ?int $cancellingId = null;
    public string $cancelReason = '';
    public ?int $rejectingId = null;
    public string $rejectReason = '';

    public function mount(): void
    {
        $this->year ??= now()->year;
    }

    /**
     * @return Collection<int, LetterType>
     */
    #[Computed]
    public function types(): Collection
    {
        return LetterType::query()->orderBy('name')->get();
    }

    #[Computed]
    public function type(): ?LetterType
    {
        return $this->types->firstWhere('id', $this->letterTypeId);
    }

    /**
     * @return LengthAwarePaginator<int, Letter>
     */
    #[Computed]
    public function letters(): LengthAwarePaginator
    {
        $term = '%'.mb_strtolower(trim($this->search)).'%';

        return Letter::query()
            ->with(['type', 'student.user', 'teacher.user', 'issuer', 'signatory', 'stamp'])
            ->where('year', $this->year)
            ->when($this->typeFilter, fn ($query) => $query->where('letter_type_id', $this->typeFilter))
            ->when(trim($this->search) !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereRaw('lower(number) like ?', [$term])
                ->orWhereRaw('lower(subject_line) like ?', [$term])
                ->orWhereRaw('lower(coalesce(recipient, \'\')) like ?', [$term])
                ->orWhereHas('student.user', fn ($query) => $query->whereRaw('lower(name) like ?', [$term]))
                ->orWhereHas('teacher.user', fn ($query) => $query->whereRaw('lower(name) like ?', [$term]))))
            ->orderByDesc('sequence')
            ->paginate(25);
    }

    /**
     * @return Collection<int, LetterRequest>
     */
    #[Computed]
    public function requests(): Collection
    {
        return LetterRequest::query()->with(['student.user', 'type'])
            ->where('status', LetterRequestStatus::Pending)->orderBy('created_at')->get();
    }

    /**
     * Matches for the person search in the compose dialog: id => label.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function people(): array
    {
        $term = '%'.mb_strtolower(trim($this->personSearch)).'%';

        if ($this->type?->subject === LetterSubject::Student) {
            return Student::query()->with('user')
                ->when(trim($this->personSearch) !== '', fn ($query) => $query->where(fn ($query) => $query
                    ->whereRaw('lower(nim) like ?', [$term])
                    ->orWhereHas('user', fn ($query) => $query->whereRaw('lower(name) like ?', [$term]))))
                ->orderBy('nim')->limit(20)->get()
                ->mapWithKeys(fn (Student $student) => [$student->id => $student->nim.' · '.$student->user->name])->all();
        }

        if ($this->type?->subject === LetterSubject::Teacher) {
            return Teacher::query()->with('user')
                ->when(trim($this->personSearch) !== '', fn ($query) => $query->where(fn ($query) => $query
                    ->whereRaw('lower(nidm) like ?', [$term])
                    ->orWhereHas('user', fn ($query) => $query->whereRaw('lower(name) like ?', [$term]))))
                ->orderBy('nidm')->limit(20)->get()
                ->mapWithKeys(fn (Teacher $teacher) => [$teacher->id => $teacher->nidm.' · '.$teacher->displayName()])->all();
        }

        return [];
    }

    /**
     * @return Collection<int, Signatory>
     */
    #[Computed]
    public function signatories(): Collection
    {
        return Signatory::query()->where('is_active', true)->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Stamp>
     */
    #[Computed]
    public function stamps(): Collection
    {
        return Stamp::query()->where('is_active', true)->orderBy('name')->get();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function compose(?int $requestId = null): void
    {
        $this->reset('requestId', 'letterTypeId', 'studentId', 'teacherId', 'personSearch', 'recipient', 'subjectLine', 'body', 'purpose');
        $this->issuedOn = now()->toDateString();
        $this->resetErrorBag();

        if ($requestId !== null) {
            $request = LetterRequest::query()->with('student')->where('status', LetterRequestStatus::Pending)->findOrFail($requestId);
            $this->requestId = $request->id;
            $this->letterTypeId = $request->letter_type_id;
            $this->studentId = $request->student_id;
            $this->personSearch = $request->student->nim;
            $this->purpose = $request->purpose;
            $this->applyType();
        }

        Flux::modal('compose')->show();
    }

    public function updatedLetterTypeId(): void
    {
        $this->reset('studentId', 'teacherId', 'personSearch');
        $this->applyType();
    }

    public function updatedStudentId(): void
    {
        $this->fillBody();
    }

    public function updatedTeacherId(): void
    {
        $this->fillBody();
    }

    public function updatedIssuedOn(): void
    {
        $this->fillBody();
    }

    /**
     * Take the type's subject line, signer and stamp, and fill its template.
     */
    private function applyType(): void
    {
        unset($this->type, $this->people);
        $type = $this->type;

        if ($type === null) {
            return;
        }

        $this->subjectLine = $type->name;
        $this->signatoryId = $type->signatory_id;
        $this->stampId = $type->stamp_id;
        $this->printMarks = $type->print_marks;
        $this->fillBody();
    }

    /**
     * Fill the template again with the chosen person and date (replaces edits).
     */
    public function fillBody(): void
    {
        $type = $this->type;

        if ($type === null) {
            return;
        }

        $this->body = LetterTemplate::fill(
            $type->template,
            $this->studentId ? Student::query()->find($this->studentId) : null,
            $this->teacherId ? Teacher::query()->find($this->teacherId) : null,
            $this->issuedOn ? Carbon::parse($this->issuedOn) : now(),
            $this->purpose,
        );
    }

    public function issue(ManageLetters $letters): void
    {
        $this->resetErrorBag();

        $letter = $letters->issue([
            'letter_type_id' => $this->letterTypeId,
            'student_id' => $this->studentId,
            'teacher_id' => $this->teacherId,
            'recipient' => $this->recipient,
            'subject_line' => $this->subjectLine,
            'body' => $this->body,
            'issued_on' => $this->issuedOn,
            'signatory_id' => $this->signatoryId ?: null,
            'stamp_id' => $this->stampId ?: null,
            'print_marks' => $this->printMarks,
        ], auth()->user(), $this->requestId ? LetterRequest::query()->findOrFail($this->requestId) : null);

        Flux::modal('compose')->close();
        $this->year = $letter->year;
        $this->tab = 'register';
        unset($this->letters, $this->requests);
        Flux::toast(variant: 'success', text: __('Letter :number issued.', ['number' => $letter->number]));
    }

    public function startCancel(int $id): void
    {
        $this->cancellingId = Letter::query()->findOrFail($id)->id;
        $this->cancelReason = '';
        $this->resetErrorBag();
        Flux::modal('cancel-letter')->show();
    }

    public function cancelLetter(ManageLetters $letters): void
    {
        $this->resetErrorBag();
        $letters->cancel(Letter::query()->findOrFail($this->cancellingId), $this->cancelReason, auth()->user());
        Flux::modal('cancel-letter')->close();
        unset($this->letters);
        Flux::toast(variant: 'warning', text: __('Letter cancelled. Its number stays in the register.'));
    }

    public function startReject(int $id): void
    {
        $this->rejectingId = LetterRequest::query()->findOrFail($id)->id;
        $this->rejectReason = '';
        $this->resetErrorBag();
        Flux::modal('reject-request')->show();
    }

    public function rejectRequest(ManageLetters $letters): void
    {
        $this->resetErrorBag();
        $letters->reject(LetterRequest::query()->findOrFail($this->rejectingId), $this->rejectReason, auth()->user());
        Flux::modal('reject-request')->close();
        unset($this->requests);
        Flux::toast(variant: 'success', text: __('Request rejected. The mahasantri sees your reason.'));
    }

    public function export(): BinaryFileResponse
    {
        $letters = Letter::query()->with(['type', 'student.user', 'teacher.user', 'issuer'])->where('year', $this->year)->orderBy('sequence')->get();

        return SpreadsheetDownload::make('register-surat-keluar-'.$this->year.'.xlsx', $letters->map(fn (Letter $letter) => [
            __('Number') => $letter->number,
            __('Date') => $letter->issued_on->format('Y-m-d'),
            __('Letter type') => $letter->type->name,
            __('Subject') => $letter->subject_line,
            __('About') => $letter->subjectName() ?? '',
            __('To') => $letter->recipient ?? '',
            __('Issued by') => $letter->issuer->name,
            __('Status') => $letter->isCancelled() ? __('Cancelled: :reason', ['reason' => $letter->cancel_reason]) : __('Issued'),
        ]));
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Outgoing letters')" :subtitle="__('Write letters from the templates, with automatic numbers, and keep the register. Mahasantri requests arrive here too.')">
        <x-slot:actions>
            <flux:button size="sm" icon="inbox-arrow-down" :href="route('admin.incoming-letters')" wire:navigate>{{ __('Incoming letters') }}</flux:button>
            <flux:button variant="primary" icon="plus" wire:click="compose" data-test="compose">{{ __('Write a letter') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <flux:radio.group wire:model.live="tab" variant="segmented" class="mb-6">
        <flux:radio value="register" :label="__('Register')" icon="queue-list" />
        <flux:radio value="requests" :label="__('Requests (:count)', ['count' => $this->requests->count()])" icon="inbox" />
    </flux:radio.group>

    @if ($tab === 'register')
        <div class="mb-4 flex flex-wrap items-end gap-3">
            <flux:input wire:model.live="year" :label="__('Year')" type="number" class="sm:max-w-28" />
            <flux:select wire:model.live="typeFilter" :label="__('Letter type')" class="sm:max-w-60">
                <flux:select.option value="">{{ __('All') }}</flux:select.option>
                @foreach ($this->types as $item)
                    <flux:select.option :value="$item->id">{{ $item->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="__('Number, subject, name…')" class="sm:max-w-64" />
            <flux:button size="sm" variant="ghost" icon="arrow-down-tray" wire:click="export" class="ms-auto">{{ __('Excel') }}</flux:button>
        </div>

        <div class="overflow-x-auto">
            <flux:table class="table-stack" :paginate="$this->letters">
                <flux:table.columns>
                    <flux:table.column>{{ __('Number') }}</flux:table.column>
                    <flux:table.column>{{ __('Subject') }}</flux:table.column>
                    <flux:table.column>{{ __('About / to') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->letters as $letter)
                        <flux:table.row :key="$letter->id" @class(['opacity-60' => $letter->isCancelled()])>
                            <flux:table.cell>
                                <span class="font-mono text-sm">{{ $letter->number }}</span>
                                <div class="text-xs text-zinc-500">{{ $letter->issued_on->translatedFormat('j M Y') }} · {{ $letter->issuer->name }}</div>
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-normal">
                                <div class="text-sm">{{ $letter->subject_line }}</div>
                                <div class="text-xs text-zinc-500">{{ $letter->type->name }}</div>
                                @if ($letter->isCancelled())
                                    <flux:badge size="sm" color="red">{{ __('Cancelled: :reason', ['reason' => $letter->cancel_reason]) }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-normal text-sm">{{ $letter->subjectName() ?? $letter->recipient ?? '—' }}</flux:table.cell>
                            <flux:table.cell align="end">
                                <x-print-button size="sm" variant="ghost" icon="printer" :href="route('letters.pdf', $letter)" :document="PrintDocument::Letter"
                                    :preset="['signatory' => $letter->signatory_id, 'stamp' => $letter->stamp_id, 'marks' => $letter->print_marks]">{{ __('PDF') }}</x-print-button>
                                @unless ($letter->isCancelled())
                                    <flux:button size="sm" variant="ghost" icon="no-symbol" wire:click="startCancel({{ $letter->id }})" :aria-label="__('Cancel letter')" data-test="cancel-{{ $letter->id }}" />
                                @endunless
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4" class="text-center">{{ __('No letters in :year yet.', ['year' => $year]) }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    @else
        <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @forelse ($this->requests as $request)
                <div wire:key="request-{{ $request->id }}" class="flex flex-wrap items-center gap-3 px-4 py-3">
                    <div class="min-w-0 flex-1 text-sm">
                        <div class="font-medium">{{ $request->type->name }}</div>
                        <div>{{ $request->student->user->name }} <span class="font-mono text-xs text-zinc-500">{{ $request->student->nim }}</span></div>
                        <div class="text-zinc-600 dark:text-zinc-400">{{ __('For: :purpose', ['purpose' => $request->purpose]) }}</div>
                        <div class="text-xs text-zinc-500">{{ $request->created_at->diffForHumans() }}</div>
                    </div>
                    <flux:button size="sm" variant="primary" icon="pencil-square" wire:click="compose({{ $request->id }})" data-test="process-{{ $request->id }}">{{ __('Write the letter') }}</flux:button>
                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="startReject({{ $request->id }})">{{ __('Reject') }}</flux:button>
                </div>
            @empty
                <flux:text class="p-4 text-sm">{{ __('No requests waiting.') }}</flux:text>
            @endforelse
        </div>
    @endif

    <flux:modal name="compose" class="w-full max-w-3xl">
        <form wire:submit="issue" class="space-y-5">
            <flux:heading size="lg">{{ $requestId ? __('Write the requested letter') : __('Write a letter') }}</flux:heading>
            <div class="grid gap-5 sm:grid-cols-2">
                <flux:select wire:model.live="letterTypeId" :label="__('Letter type')" :placeholder="__('Choose…')" :disabled="(bool) $requestId" required>
                    @foreach ($this->types->where('is_active', true) as $item)
                        <flux:select.option :value="$item->id">{{ $item->name }} ({{ $item->code }})</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model.live="issuedOn" :label="__('Date')" type="date" required />
            </div>
            <flux:error name="letter_type_id" />

            @if ($this->type && $this->type->subject !== LetterSubject::None)
                <div class="grid gap-5 sm:grid-cols-2">
                    <flux:input wire:model.live.debounce.300ms="personSearch" :label="$this->type->subject === LetterSubject::Student ? __('Find the mahasantri') : __('Find the muhadhir')"
                        icon="magnifying-glass" :disabled="(bool) $requestId" />
                    @if ($this->type->subject === LetterSubject::Student)
                        <flux:select wire:model.live="studentId" :label="__('About')" :placeholder="__('Choose…')" :disabled="(bool) $requestId" data-test="letter-student">
                            @foreach ($this->people as $id => $label)
                                <flux:select.option :value="$id">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @else
                        <flux:select wire:model.live="teacherId" :label="__('About')" :placeholder="__('Choose…')">
                            @foreach ($this->people as $id => $label)
                                <flux:select.option :value="$id">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif
                </div>
                <flux:error name="student_id" />
                <flux:error name="teacher_id" />
            @endif

            @if ($requestId)
                <flux:callout icon="information-circle" :text="__('Requested for: :purpose', ['purpose' => $purpose])" />
            @endif

            <div class="grid gap-5 sm:grid-cols-2">
                <flux:input wire:model="subjectLine" :label="__('Subject (perihal)')" required />
                <flux:textarea wire:model="recipient" :label="__('To (optional)')" rows="2" :placeholder="__('e.g. Kepala Kantor Imigrasi Jember')" />
            </div>
            <div>
                <div class="mb-2 flex items-center justify-between">
                    <flux:label>{{ __('Text') }}</flux:label>
                    <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="fillBody" type="button">{{ __('Fill from the template again') }}</flux:button>
                </div>
                <flux:textarea wire:model="body" rows="14" class="text-sm" required data-test="letter-body" />
                <flux:error name="body" />
            </div>
            <div class="grid gap-5 sm:grid-cols-3">
                <flux:select wire:model="signatoryId" :label="__('Signed by')">
                    <flux:select.option value="">{{ __('— blank signature line —') }}</flux:select.option>
                    @foreach ($this->signatories as $signatory)
                        <flux:select.option :value="$signatory->id">{{ $signatory->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="stampId" :label="__('Stamp')">
                    <flux:select.option value="">{{ __('— none —') }}</flux:select.option>
                    @foreach ($this->stamps as $stamp)
                        <flux:select.option :value="$stamp->id">{{ $stamp->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div class="pt-7">
                    <flux:checkbox wire:model="printMarks" :label="__('Print the images')" />
                </div>
            </div>
            <flux:text class="text-xs">{{ __('Issuing takes the next number. An issued letter cannot be edited; cancel it and write a new one instead.') }}</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check" data-test="issue-letter">{{ __('Issue the letter') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="cancel-letter" class="w-full max-w-md">
        <form wire:submit="cancelLetter" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Cancel letter') }}</flux:heading>
                <flux:text class="mt-1">{{ __('The letter stays in the register, marked cancelled, and its number is not used again.') }}</flux:text>
            </div>
            <flux:textarea wire:model="cancelReason" :label="__('Reason')" rows="2" required />
            <flux:error name="cancelReason" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Back') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger" data-test="confirm-cancel">{{ __('Cancel letter') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="reject-request" class="w-full max-w-md">
        <form wire:submit="rejectRequest" class="space-y-5">
            <flux:heading size="lg">{{ __('Reject request') }}</flux:heading>
            <flux:textarea wire:model="rejectReason" :label="__('Reason (the mahasantri sees it)')" rows="2" required />
            <flux:error name="rejectReason" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Back') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger">{{ __('Reject') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
