<?php

use App\Enums\DocumentStatus;
use App\Enums\StudentStatus;
use App\Models\Student;
use App\Models\StudyProgram;
use App\Support\SpreadsheetDownload;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new #[Title('Mahasantri')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'active')]
    public string $status = 'active';

    #[Url(except: '')]
    public string $program = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'program'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return Builder<Student>
     */
    private function query(): Builder
    {
        $term = '%'.mb_strtolower(trim($this->search)).'%';

        return Student::query()
            ->with(['user', 'studyProgram'])
            ->withCount([
                'documents',
                'documents as verified_documents_count' => fn (Builder $query) => $query->where('status', DocumentStatus::Verified),
            ])
            ->when($this->status !== '', fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->program !== '', fn (Builder $query) => $query->where('study_program_id', $this->program))
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereRaw('lower(nim) like ?', [$term])
                ->orWhereHas('user', fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$term]))))
            ->orderBy('nim');
    }

    /**
     * @return LengthAwarePaginator<int, Student>
     */
    #[Computed]
    public function students(): LengthAwarePaginator
    {
        return $this->query()->paginate(25);
    }

    /**
     * @return Collection<int, StudyProgram>
     */
    #[Computed]
    public function programs(): Collection
    {
        return StudyProgram::query()->orderBy('code')->get();
    }

    public function export(): BinaryFileResponse
    {
        $rows = $this->query()->lazy()->map(fn (Student $student) => [
            __('NIM') => $student->nim,
            __('Name') => $student->user->name,
            __('Study program') => $student->studyProgram->code,
            __('Entry year') => $student->entry_year,
            __('Gender') => $student->gender->label(),
            __('Birth place') => $student->birth_place,
            __('Birth date') => $student->birth_date?->format('Y-m-d'),
            __('Phone') => $student->phone,
            __('Email') => $student->user->email,
            __('Status') => $student->status->label(),
        ]);

        return SpreadsheetDownload::make('mahasantri-'.now()->format('Ymd').'.xlsx', $rows);
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Mahasantri')" :subtitle="__('Students, identified by NIM. Their NIM is also their login ID.')">
        <x-slot:actions>
            <flux:button icon="arrow-down-tray" wire:click="export">{{ __('Export') }}</flux:button>
            <flux:button icon="arrow-up-tray" :href="route('admin.imports', ['type' => 'students'])" wire:navigate>{{ __('Import') }}</flux:button>
            <flux:button variant="primary" icon="plus" :href="route('admin.students.create')" wire:navigate>{{ __('Add mahasantri') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row">
        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="__('Search NIM or name…')" class="sm:max-w-xs" />
        <flux:select wire:model.live="status" class="sm:max-w-44">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            @foreach (StudentStatus::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="program" class="sm:max-w-56">
            <flux:select.option value="">{{ __('All study programs') }}</flux:select.option>
            @foreach ($this->programs as $studyProgram)
                <flux:select.option :value="$studyProgram->id">{{ $studyProgram->code }} — {{ $studyProgram->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="overflow-x-auto">
        <flux:table :paginate="$this->students">
            <flux:table.columns>
                <flux:table.column>{{ __('NIM') }}</flux:table.column>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column class="hidden md:table-cell">{{ __('Study program') }}</flux:table.column>
                <flux:table.column class="hidden md:table-cell">{{ __('Entry') }}</flux:table.column>
                <flux:table.column class="hidden sm:table-cell">{{ __('Documents') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->students as $student)
                    <flux:table.row :key="$student->id">
                        <flux:table.cell class="font-mono">{{ $student->nim }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:link :href="route('admin.students.edit', $student)" wire:navigate class="font-medium">{{ $student->user->name }}</flux:link>
                            @unless ($student->user->is_active)
                                <flux:badge size="sm" color="red" class="ms-1">{{ __('Login disabled') }}</flux:badge>
                            @endunless
                        </flux:table.cell>
                        <flux:table.cell class="hidden md:table-cell">{{ $student->studyProgram->code }}</flux:table.cell>
                        <flux:table.cell class="hidden md:table-cell">{{ $student->entry_year }}</flux:table.cell>
                        <flux:table.cell class="hidden sm:table-cell">
                            <flux:badge size="sm" :color="$student->verified_documents_count === $student->documents_count ? 'green' : 'zinc'">
                                {{ $student->verified_documents_count }}/{{ $student->documents_count }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$student->status->color()">{{ $student->status->label() }}</flux:badge>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6" class="text-center">{{ __('No mahasantri found.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</section>
