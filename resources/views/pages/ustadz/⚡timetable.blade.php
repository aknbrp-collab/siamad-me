<?php

use App\Models\CourseOffering;
use App\Models\Semester;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('My timetable')] class extends Component {
    /**
     * This muhadhir's classes in the active semester.
     *
     * @return Collection<int, CourseOffering>
     */
    #[Computed]
    public function offerings(): Collection
    {
        return CourseOffering::query()
            ->with(['classGroup', 'teacher.user'])
            ->where('semester_id', Semester::active()?->id)
            ->where('teacher_id', auth()->user()->teacher?->id)
            ->get();
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('My timetable')" :subtitle="Semester::active()?->label()">
        <x-slot:actions>
            <flux:button icon="printer" onclick="window.print()" class="print:hidden">{{ __('Print') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <x-timetable :offerings="$this->offerings" :show="['class', 'room']" />
</section>
