{{-- One class kuliah's evaluation result (v6 D35). Expects $result from ManageEvaluations::results(). --}}
@props(['result'])

<div {{ $attributes->class('space-y-4') }} data-test="evaluation-result-{{ $result['offering']->id }}">
    <flux:text size="sm">
        {{ trans_choice(':count of :total mahasantri answered.|:count of :total mahasantri answered.', $result['responses'], ['total' => $result['enrolled']]) }}
        @if ($result['shown'] && $result['average'] !== null)
            · {{ __('Average') }} <strong>{{ number_format($result['average'], 2, ',', '.') }}</strong> / 5
        @endif
    </flux:text>

    @if (! $result['shown'])
        <flux:callout icon="eye-slash" color="zinc" :text="__('Results show once at least :min mahasantri have answered, so no single answer can be traced.', ['min' => \App\Actions\Evaluations\ManageEvaluations::MIN_RESPONSES])" />
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs text-zinc-500">
                    <tr>
                        <th class="py-1 pe-3 font-medium">{{ __('Statement') }}</th>
                        <th class="w-16 py-1 pe-3 text-end font-medium">{{ __('Average') }}</th>
                        <th class="w-48 py-1 font-medium">{{ __('Scores 1–5') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @php($group = null)
                    @foreach ($result['questions'] as $row)
                        @if ($row['question']->group !== $group)
                            @php($group = $row['question']->group)
                            <tr><td colspan="3" class="pt-3 pb-1 text-xs font-semibold tracking-wide text-zinc-500 uppercase">{{ $group }}</td></tr>
                        @endif
                        <tr>
                            <td class="py-1.5 pe-3">{{ $row['question']->question }}</td>
                            <td class="py-1.5 pe-3 text-end font-medium tabular-nums">{{ $row['average'] !== null ? number_format($row['average'], 2, ',', '.') : '—' }}</td>
                            <td class="py-1.5">
                                @php($max = max(1, max($row['distribution'])))
                                <div class="flex h-6 items-end gap-1" title="{{ collect($row['distribution'])->map(fn ($count, $score) => $score.': '.$count)->join(' · ') }}">
                                    @foreach ($row['distribution'] as $score => $count)
                                        <div class="flex flex-1 flex-col items-center">
                                            <div class="w-full rounded-sm bg-[var(--color-accent)]/70" style="height: {{ max(2, (int) round(20 * $count / $max)) }}px"></div>
                                            <span class="text-[10px] leading-none text-zinc-500">{{ $count }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div>
            <flux:heading size="sm" class="mb-2">{{ __('Comments') }} ({{ count($result['comments']) }})</flux:heading>
            @forelse ($result['comments'] as $comment)
                <p class="mb-2 rounded-md bg-zinc-50 p-2 text-sm whitespace-pre-line dark:bg-zinc-800">{{ $comment }}</p>
            @empty
                <flux:text size="sm">{{ __('No comments.') }}</flux:text>
            @endforelse
        </div>
    @endif
</div>
