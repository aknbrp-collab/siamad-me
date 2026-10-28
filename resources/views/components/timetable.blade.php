{{-- Weekly timetable of course offerings: a column per day (stacked on phones). --}}
@props(['offerings', 'show' => ['class', 'teacher', 'room']])

@php
    use App\Models\CourseOffering;

    $byDay = $offerings->groupBy('day_of_week');
    $days = collect(CourseOffering::days())->filter(fn ($name, $day) => $byDay->has($day) || $day <= 6);
    $unscheduled = $byDay->get(null, collect())->merge($byDay->get('', collect()));
@endphp

{{-- Written out in full so Tailwind generates both column counts. --}}
<div @class([
    'grid gap-3 md:grid-cols-3',
    'xl:grid-cols-7 print:grid-cols-7' => $days->count() === 7,
    'xl:grid-cols-6 print:grid-cols-6' => $days->count() !== 7,
]) data-test="timetable">
    @foreach ($days as $day => $dayName)
        <div wire:key="day-{{ $day }}" class="rounded-lg border border-zinc-200 dark:border-zinc-700 print:break-inside-avoid">
            <div class="border-b border-zinc-200 bg-zinc-50 px-3 py-2 text-sm font-semibold dark:border-zinc-700 dark:bg-zinc-800">{{ $dayName }}</div>
            <div class="space-y-2 p-2">
                {{-- Classes taught together (joint class) at the same time are one block. --}}
                @forelse ($byDay->get($day, collect())->sortBy('starts_at')->groupBy(fn ($offering) => $offering->teachingLeadId().'|'.$offering->starts_at) as $together)
                    @php($offering = $together->first())
                    <div wire:key="slot-{{ $offering->id }}" class="rounded-md bg-zinc-100 px-2 py-1.5 text-xs dark:bg-zinc-800">
                        <div class="font-mono text-zinc-600 dark:text-zinc-300">
                            {{ $offering->starts_at ? substr($offering->starts_at, 0, 5) : '—' }}{{ $offering->ends_at ? '–'.substr($offering->ends_at, 0, 5) : '' }}
                        </div>
                        <div class="font-semibold text-zinc-900 dark:text-white">{{ $offering->subject_name }}</div>
                        <div class="text-zinc-600 dark:text-zinc-400">
                            {{ collect([
                                in_array('class', $show, true) || $together->count() > 1 ? $together->map(fn ($member) => $member->class_name)->join(' + ') : null,
                                in_array('teacher', $show, true) ? $offering->teacher->user->name : null,
                                in_array('room', $show, true) ? $offering->room : null,
                            ])->filter()->join(' · ') }}
                        </div>
                    </div>
                @empty
                    <div class="px-1 py-1 text-xs text-zinc-400">—</div>
                @endforelse
            </div>
        </div>
    @endforeach
</div>

@if ($unscheduled->isNotEmpty())
    <flux:text class="mt-4 text-sm">
        {{ __('No day or time yet: :subjects', ['subjects' => $unscheduled->map(fn ($offering) => $offering->subject_name.' ('.$offering->class_name.')')->join(', ')]) }}
    </flux:text>
@endif
