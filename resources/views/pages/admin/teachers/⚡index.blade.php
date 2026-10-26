<?php

use App\Enums\DocumentStatus;
use App\Enums\TeacherStatus;
use App\Models\Teacher;
use App\Support\SpreadsheetDownload;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new #[Title('Ustadz')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'active')]
    public string $status = 'active';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return Builder<Teacher>
     */
    private function query(): Builder
    {
        $term = '%'.mb_strtolower(trim($this->search)).'%';

        return Teacher::query()
            ->with('user')
            ->withCount([
                'documents',
                'documents as verified_documents_count' => fn (Builder $query) => $query->where('status', DocumentStatus::Verified),
            ])
            ->when($this->status !== '', fn (Builder $query) => $query->where('status', $this->status))
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereRaw('lower(nidm) like ?', [$term])
                ->orWhereRaw('lower(expertise) like ?', [$term])
                ->orWhereHas('user', fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$term]))))
            ->orderBy('nidm');
    }

    /**
     * @return LengthAwarePaginator<int, Teacher>
     */
    #[Computed]
    public function teachers(): LengthAwarePaginator
    {
        return $this->query()->paginate(25);
    }

    public function export(): BinaryFileResponse
    {
        $rows = $this->query()->lazy()->map(fn (Teacher $teacher) => [
            __('NIDM') => $teacher->nidm,
            __('Name') => $teacher->user->name,
            __('Title') => $teacher->title,
            __('Gender') => $teacher->gender->label(),
            __('Expertise') => $teacher->expertise,
            __('Phone') => $teacher->phone,
            __('Email') => $teacher->user->email,
            __('Status') => $teacher->status->label(),
        ]);

        return SpreadsheetDownload::make('ustadz-'.now()->format('Ymd').'.xlsx', $rows);
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Ustadz')" :subtitle="__('Teachers (dosen), identified by NIDM. Their NIDM is also their login ID.')">
        <x-slot:actions>
            <flux:button icon="arrow-down-tray" wire:click="export">{{ __('Export') }}</flux:button>
            <flux:button icon="arrow-up-tray" :href="route('admin.imports', ['type' => 'teachers'])" wire:navigate>{{ __('Import') }}</flux:button>
            <flux:button variant="primary" icon="plus" :href="route('admin.teachers.create')" wire:navigate>{{ __('Add ustadz') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row">
        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="__('Search NIDM, name or expertise…')" class="sm:max-w-xs" />
        <flux:select wire:model.live="status" class="sm:max-w-44">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            @foreach (TeacherStatus::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="overflow-x-auto">
        <flux:table :paginate="$this->teachers">
            <flux:table.columns>
                <flux:table.column>{{ __('NIDM') }}</flux:table.column>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column class="hidden md:table-cell">{{ __('Expertise') }}</flux:table.column>
                <flux:table.column class="hidden sm:table-cell">{{ __('Documents') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->teachers as $teacher)
                    <flux:table.row :key="$teacher->id">
                        <flux:table.cell class="font-mono">{{ $teacher->nidm }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:link :href="route('admin.teachers.edit', $teacher)" wire:navigate class="font-medium">{{ $teacher->displayName() }}</flux:link>
                            @unless ($teacher->user->is_active)
                                <flux:badge size="sm" color="red" class="ms-1">{{ __('Login disabled') }}</flux:badge>
                            @endunless
                        </flux:table.cell>
                        <flux:table.cell class="hidden md:table-cell">{{ $teacher->expertise ?? '—' }}</flux:table.cell>
                        <flux:table.cell class="hidden sm:table-cell">
                            <flux:badge size="sm" :color="$teacher->verified_documents_count === $teacher->documents_count ? 'green' : 'zinc'">
                                {{ $teacher->verified_documents_count }}/{{ $teacher->documents_count }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$teacher->status->color()">{{ $teacher->status->label() }}</flux:badge>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-center">{{ __('No ustadz found.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</section>
