<?php

use App\Actions\Lms\RecordActivity;
use App\Enums\ActivityCategory;
use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Models\ActivityLog;
use App\Models\Student;
use App\Support\ActiveRole;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Activity log')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $category = '';

    // Form
    public string $studentSearch = '';
    public ?int $studentId = null;
    public string $formCategory = 'tahfidz';
    public ?string $happenedOn = null;
    public string $title = '';
    public ?string $notes = null;

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'category'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, ActivityLog>
     */
    #[Computed]
    public function logs(): LengthAwarePaginator
    {
        $term = '%'.mb_strtolower(trim($this->search)).'%';

        return ActivityLog::query()
            ->with(['student.user', 'recorder'])
            ->when($this->category !== '', fn (Builder $query) => $query->where('category', $this->category))
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->whereHas('student', fn (Builder $query) => $query
                ->whereRaw('lower(nim) like ?', [$term])
                ->orWhereHas('user', fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$term]))))
            ->latest('happened_on')->latest('id')
            ->paginate(25);
    }

    /**
     * @return Collection<int, Student>
     */
    #[Computed]
    public function candidates(): Collection
    {
        if (mb_strlen(trim($this->studentSearch)) < 2) {
            return new Collection;
        }

        $term = '%'.mb_strtolower(trim($this->studentSearch)).'%';

        return Student::query()
            ->with('user')
            ->where('status', StudentStatus::Active)
            ->where(fn (Builder $query) => $query
                ->whereRaw('lower(nim) like ?', [$term])
                ->orWhereHas('user', fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$term])))
            ->limit(8)
            ->get();
    }

    #[Computed]
    public function chosen(): ?Student
    {
        return $this->studentId ? Student::query()->with('user')->find($this->studentId) : null;
    }

    public function create(): void
    {
        $this->reset('studentSearch', 'studentId', 'title', 'notes');
        $this->formCategory = ActivityCategory::Tahfidz->value;
        $this->happenedOn = now()->format('Y-m-d');
        $this->resetErrorBag();
        Flux::modal('activity-form')->show();
    }

    public function choose(int $id): void
    {
        $this->studentId = $id;
        $this->studentSearch = '';
    }

    public function save(RecordActivity $record): void
    {
        $this->resetErrorBag();

        if ($this->chosen === null) {
            $this->addError('studentId', __('Choose a mahasantri.'));

            return;
        }

        $record->handle($this->chosen, auth()->user(), [
            'category' => $this->formCategory,
            'happened_on' => $this->happenedOn,
            'title' => $this->title,
            'notes' => $this->notes ?: null,
        ]);

        Flux::modal('activity-form')->close();
        unset($this->logs);
        Flux::toast(variant: 'success', text: __('Activity recorded.'));
    }

    public function delete(int $id): void
    {
        $log = ActivityLog::query()->findOrFail($id);
        $isAdmin = in_array(ActiveRole::get(), [Role::Admin, Role::Superadmin], true);
        abort_unless($isAdmin || $log->recorded_by === auth()->id(), 403);

        $log->delete();
        unset($this->logs);
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Activity log')" :subtitle="__('Pesantren activities (halaqah, tahfidz, kajian…) for monitoring. They are never part of the KHS or transcript.')">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" wire:click="create">{{ __('Record activity') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row">
        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="__('Search NIM or name…')" class="sm:max-w-xs" />
        <flux:select wire:model.live="category" class="sm:max-w-56">
            <flux:select.option value="">{{ __('All categories') }}</flux:select.option>
            @foreach (ActivityCategory::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="overflow-x-auto">
        <flux:table :paginate="$this->logs">
            <flux:table.columns>
                <flux:table.column>{{ __('Date') }}</flux:table.column>
                <flux:table.column>{{ __('Mahasantri') }}</flux:table.column>
                <flux:table.column>{{ __('Activity') }}</flux:table.column>
                <flux:table.column class="hidden md:table-cell">{{ __('Recorded by') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->logs as $log)
                    <flux:table.row :key="$log->id">
                        <flux:table.cell>{{ $log->happened_on->translatedFormat('j M Y') }}</flux:table.cell>
                        <flux:table.cell>{{ $log->student->user->name }} <span class="font-mono text-xs text-zinc-500">{{ $log->student->nim }}</span></flux:table.cell>
                        <flux:table.cell class="whitespace-normal">
                            <flux:badge size="sm">{{ $log->category->label() }}</flux:badge>
                            <span class="font-medium">{{ $log->title }}</span>
                            @if ($log->notes)<div class="text-xs text-zinc-500">{{ $log->notes }}</div>@endif
                        </flux:table.cell>
                        <flux:table.cell class="hidden md:table-cell">{{ $log->recorder->name }}</flux:table.cell>
                        <flux:table.cell align="end">
                            @if ($log->recorded_by === auth()->id() || in_array(ActiveRole::get(), [Role::Admin, Role::Superadmin], true))
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $log->id }})" wire:confirm="{{ __('Remove this entry?') }}" :aria-label="__('Remove')" />
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-center">{{ __('No activities recorded yet.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="activity-form" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ __('Record activity') }}</flux:heading>

            @if ($this->chosen)
                <div class="flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-zinc-800">
                    <span>{{ $this->chosen->user->name }} <span class="font-mono text-zinc-500">{{ $this->chosen->nim }}</span></span>
                    <flux:button size="xs" variant="ghost" wire:click="$set('studentId', null)">{{ __('Change') }}</flux:button>
                </div>
            @else
                <div>
                    <flux:input wire:model.live.debounce.300ms="studentSearch" icon="magnifying-glass" :label="__('Mahasantri')" :placeholder="__('NIM or name…')" />
                    @foreach ($this->candidates as $candidate)
                        <button type="button" wire:key="cand-{{ $candidate->id }}" wire:click="choose({{ $candidate->id }})"
                            class="mt-1 block w-full rounded px-2 py-1 text-start text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">
                            <span class="font-mono text-zinc-500">{{ $candidate->nim }}</span> {{ $candidate->user->name }}
                        </button>
                    @endforeach
                    <flux:error name="studentId" />
                </div>
            @endif

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:select wire:model="formCategory" :label="__('Category')">
                    @foreach (ActivityCategory::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="happenedOn" :label="__('Date')" type="date" required />
            </div>
            <flux:input wire:model="title" :label="__('What')" :placeholder="__('e.g. Setoran Juz 30, An-Naba 1–20')" required />
            <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" />
            @foreach (['category', 'happened_on', 'title'] as $field)
                <flux:error :name="$field" />
            @endforeach
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
