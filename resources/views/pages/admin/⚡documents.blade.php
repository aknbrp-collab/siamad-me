<?php

use App\Actions\Documents\ReviewDocument;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\StudentDocument;
use App\Models\TeacherDocument;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Documents')] class extends Component {
    use WithPagination;

    #[Url(except: 'students')]
    public string $owner = 'students';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $search = '';

    public ?int $rejectingId = null;
    public string $rejectionNote = '';

    public function mount(): void
    {
        // Default to the review queue unless a person was searched for.
        if ($this->status === '' && $this->search === '') {
            $this->status = DocumentStatus::Uploaded->value;
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['owner', 'status', 'type', 'search'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return class-string<StudentDocument|TeacherDocument>
     */
    private function model(): string
    {
        return $this->owner === 'teachers' ? TeacherDocument::class : StudentDocument::class;
    }

    private function relation(): string
    {
        return $this->owner === 'teachers' ? 'teacher' : 'student';
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        return $this->model()::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * @return LengthAwarePaginator<int, StudentDocument|TeacherDocument>
     */
    #[Computed]
    public function documents(): LengthAwarePaginator
    {
        $relation = $this->relation();
        $idColumn = $relation === 'teacher' ? 'nidm' : 'nim';
        $term = '%'.mb_strtolower(trim($this->search)).'%';

        return $this->model()::query()
            ->with(["{$relation}.user", 'verifier'])
            ->when($this->status !== '', fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->type !== '', fn (Builder $query) => $query->where('type', $this->type))
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->whereHas($relation, fn (Builder $query) => $query
                ->whereRaw("lower({$idColumn}) like ?", [$term])
                ->orWhereHas('user', fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$term]))))
            ->orderByRaw('uploaded_at is null')
            ->orderBy('uploaded_at')
            ->orderBy('id')
            ->paginate(25);
    }

    private function find(int $id): StudentDocument|TeacherDocument
    {
        return $this->model()::query()->findOrFail($id);
    }

    public function verify(int $id, ReviewDocument $review): void
    {
        $this->resetErrorBag();

        $review->verify($this->find($id), auth()->user());

        unset($this->counts, $this->documents);
        Flux::toast(variant: 'success', text: __('Document verified.'));
    }

    public function startReject(int $id): void
    {
        $this->rejectingId = $id;
        $this->rejectionNote = '';
        $this->resetErrorBag();
        Flux::modal('reject-document')->show();
    }

    public function reject(ReviewDocument $review): void
    {
        $this->resetErrorBag();

        $review->reject($this->find($this->rejectingId), auth()->user(), $this->rejectionNote);

        Flux::modal('reject-document')->close();
        unset($this->counts, $this->documents);
        Flux::toast(variant: 'success', text: __('Document rejected. The owner sees your note.'));
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Documents')" :subtitle="__('KTP, KK and Ijazah of mahasantri and ustadz. Verify uploads or reject them with a note.')" />

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach (DocumentStatus::cases() as $case)
            <button type="button" wire:click="$set('status', '{{ $status === $case->value ? '' : $case->value }}')"
                @class(['rounded-md', 'ring-2 ring-zinc-400 ring-offset-1 dark:ring-offset-zinc-800' => $status === $case->value])>
                <flux:badge :color="$case->color()" size="lg">{{ $case->label() }}: {{ $this->counts[$case->value] ?? 0 }}</flux:badge>
            </button>
        @endforeach
    </div>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row">
        <flux:radio.group wire:model.live="owner" variant="segmented">
            <flux:radio value="students" :label="__('Mahasantri')" />
            <flux:radio value="teachers" :label="__('Ustadz')" />
        </flux:radio.group>
        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="__('Search ID or name…')" class="sm:max-w-xs" />
        <flux:select wire:model.live="type" class="sm:max-w-48">
            <flux:select.option value="">{{ __('All types') }}</flux:select.option>
            @foreach (DocumentType::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @error('document')
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-4" :text="$message" />
    @enderror

    <div class="overflow-x-auto">
        <flux:table :paginate="$this->documents">
            <flux:table.columns>
                <flux:table.column>{{ $owner === 'teachers' ? __('Ustadz') : __('Mahasantri') }}</flux:table.column>
                <flux:table.column>{{ __('Document') }}</flux:table.column>
                <flux:table.column class="hidden md:table-cell">{{ __('Uploaded') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->documents as $document)
                    @php($person = $owner === 'teachers' ? $document->teacher : $document->student)
                    <flux:table.row :key="$owner.'-'.$document->id">
                        <flux:table.cell>
                            <span class="font-mono text-zinc-500">{{ $owner === 'teachers' ? $person->nidm : $person->nim }}</span>
                            <span class="ms-1 font-medium">{{ $person->user->name }}</span>
                        </flux:table.cell>
                        <flux:table.cell>{{ $document->type->label() }}</flux:table.cell>
                        <flux:table.cell class="hidden md:table-cell">{{ $document->uploaded_at?->diffForHumans() ?? '—' }}</flux:table.cell>
                        <flux:table.cell class="whitespace-normal">
                            <flux:badge size="sm" :color="$document->status->color()">{{ $document->status->label() }}</flux:badge>
                            @if ($document->rejection_note)
                                <div class="mt-1 text-xs text-zinc-500">{{ $document->rejection_note }}</div>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            @if ($document->file_path)
                                <flux:button size="sm" variant="ghost" icon="eye" :href="route('files.show', [$owner === 'teachers' ? 'teacher-document' : 'student-document', $document->id])" target="_blank">{{ __('View') }}</flux:button>
                            @endif
                            @if ($document->status === DocumentStatus::Uploaded)
                                <flux:button size="sm" variant="primary" icon="check" wire:click="verify({{ $document->id }})">{{ __('Verify') }}</flux:button>
                                <flux:button size="sm" variant="danger" icon="x-mark" wire:click="startReject({{ $document->id }})">{{ __('Reject') }}</flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-center">{{ __('No documents match.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="reject-document" class="w-full max-w-md">
        <form wire:submit="reject" class="space-y-6">
            <flux:heading size="lg">{{ __('Reject document') }}</flux:heading>
            <flux:textarea wire:model="rejectionNote" :label="__('Note for the owner')" :placeholder="__('e.g. The scan is blurry, please upload a clearer photo.')" rows="3" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger">{{ __('Reject') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
