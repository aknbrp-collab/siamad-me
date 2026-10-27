@props([
    'khs',
    'attendance' => null,
    'minimum' => 75,
    'correctable' => false,
])

{{--
    KHS of one semester. $attendance (optional): enrollment id => summary from AcademicRecord::attendance().
    $correctable: an edit button per row calling the page's correctGrade(enrollmentId) (maintenance mode).
--}}
<div class="overflow-x-auto border-zinc-200 md:rounded-lg md:border dark:border-zinc-700">
    <table class="table-stack min-w-full text-sm">
        <thead class="bg-zinc-50 text-left dark:bg-zinc-800">
            <tr>
                <th class="px-3 py-2 font-medium">{{ __('Subject') }}</th>
                <th class="px-3 py-2 text-center font-medium">{{ __('SKS') }}</th>
                @if ($attendance !== null)
                    <th class="px-3 py-2 text-center font-medium">{{ __('Attendance') }}</th>
                @endif
                <th class="px-3 py-2 text-center font-medium">{{ __('Score') }}</th>
                <th class="px-3 py-2 text-center font-medium">{{ __('Grade') }}</th>
                @if ($correctable)
                    <th class="px-3 py-2"></th>
                @endif
            </tr>
        </thead>
        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse ($khs['rows'] as $row)
                <tr wire:key="khs-{{ $row['enrollment_id'] }}">
                    <td class="px-3 py-2"><span class="font-mono text-zinc-500">{{ $row['code'] }}</span> {{ $row['name'] }}</td>
                    <td class="px-3 py-2 text-center">{{ $row['credits'] }}</td>
                    @if ($attendance !== null)
                        @php($percent = $attendance[$row['enrollment_id']]['percent'] ?? null)
                        <td class="px-3 py-2 text-center">
                            @if ($percent !== null)
                                <flux:badge size="sm" :color="$percent < $minimum ? 'red' : 'green'">{{ $percent }}%</flux:badge>
                            @else
                                <span class="text-zinc-400">—</span>
                            @endif
                        </td>
                    @endif
                    @if ($row['final'])
                        <td class="px-3 py-2 text-center tabular-nums">{{ $row['score'] }}</td>
                        <td class="px-3 py-2 text-center"><flux:badge size="sm">{{ $row['letter'] }}</flux:badge> <span class="text-xs text-zinc-500">{{ $row['point'] }}</span></td>
                    @else
                        <td colspan="2" class="px-3 py-2 text-center text-xs text-zinc-500">{{ __('not final yet') }}</td>
                    @endif
                    @if ($correctable)
                        <td class="px-3 py-2 text-end">
                            <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="correctGrade({{ $row['enrollment_id'] }})" :aria-label="__('Correct grade')" data-test="correct-{{ $row['enrollment_id'] }}" />
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="5" class="px-3 py-4 text-center text-zinc-500">{{ __('No subjects this semester.') }}</td></tr>
            @endforelse
        </tbody>
        <tfoot class="bg-zinc-50 dark:bg-zinc-800">
            <tr>
                <td class="px-3 py-2 font-medium">{{ __('IPS') }}</td>
                <td class="px-3 py-2 text-center">{{ $khs['credits'] }}</td>
                <td colspan="{{ ($attendance !== null ? 3 : 2) + ($correctable ? 1 : 0) }}" class="px-3 py-2 text-center text-base font-semibold tabular-nums">
                    {{ $khs['ip'] !== null ? number_format($khs['ip'], 2) : '—' }}
                </td>
            </tr>
        </tfoot>
    </table>
</div>
