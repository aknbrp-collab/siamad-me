<?php

use App\Models\CmsRequest;
use App\Support\Cms\CmsClient;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('CMS integration')] class extends Component {
    use WithPagination;

    #[Url]
    public string $show = 'all';

    public ?string $testResult = null;
    public bool $testOk = false;

    /**
     * What is set up, and what is wrong (spec §2, §7).
     *
     * @return list<array{label: string, ok: bool, detail: string}>
     */
    #[Computed]
    public function checks(): array
    {
        $secret = (string) config('services.cms.secret');
        $waiting = DB::table('jobs')->count();
        $oldest = DB::table('jobs')->min('created_at');
        $stuck = $oldest !== null && now()->timestamp - (int) $oldest > 300;

        return [
            ['label' => __('CMS address'), 'ok' => filled(config('services.cms.url')), 'detail' => (string) (config('services.cms.url') ?: __('CMS_WEBHOOK_URL is empty: the integration is off.'))],
            ['label' => __('Shared secret'), 'ok' => mb_strlen($secret) >= 32, 'detail' => $secret === '' ? __('CMS_WEBHOOK_SECRET is empty.') : (mb_strlen($secret) >= 32 ? __(':count characters', ['count' => mb_strlen($secret)]) : __('Too short: use at least 32 random characters (openssl rand -hex 32).'))],
            ['label' => __('API key for the CMS'), 'ok' => filled(config('services.cms.api_key')), 'detail' => filled(config('services.cms.api_key')) ? __('Set') : __('SIAKAD_API_KEY is empty: the CMS cannot forward Tanya Hukum questions.')],
            ['label' => __('Article type sent'), 'ok' => true, 'detail' => (string) config('services.cms.article_type')],
            ['label' => __('Queue worker'), 'ok' => ! $stuck, 'detail' => $stuck
                ? __(':count calls wait for more than 5 minutes: the scheduler (php artisan schedule:run every minute) or a queue worker is not running.', ['count' => $waiting])
                : trans_choice(':count call waiting|:count calls waiting', $waiting)],
            ['label' => __('Failed calls'), 'ok' => DB::table('failed_jobs')->count() === 0, 'detail' => trans_choice(':count after all retries|:count after all retries', DB::table('failed_jobs')->count())],
        ];
    }

    /**
     * @return LengthAwarePaginator<int, CmsRequest>
     */
    #[Computed]
    public function requests(): LengthAwarePaginator
    {
        return CmsRequest::query()
            ->when($this->show === 'failed', fn ($query) => $query->where(fn ($query) => $query->whereNull('status')->orWhere('status', '>=', 300)))
            ->when($this->show === 'in', fn ($query) => $query->where('direction', 'in'))
            ->latest('id')
            ->paginate(25);
    }

    public function updatedShow(): void
    {
        $this->resetPage();
    }

    /**
     * Ask the CMS about an article that does not exist: "not found" proves the
     * address, the secret and the clocks are right (a bad signature gives 401).
     */
    public function test(CmsClient $client): void
    {
        if (! CmsClient::enabled()) {
            $this->testOk = false;
            $this->testResult = __('Set CMS_WEBHOOK_URL and CMS_WEBHOOK_SECRET first.');

            return;
        }

        try {
            $response = $client->send('GET', 'articles/SIAKAD-CONNECTION-TEST', null, 'connection test');
            $this->testOk = $response->status() === 404;
            $this->testResult = match (true) {
                $response->status() === 404 => __('Connected: the CMS accepted the signature.'),
                $response->status() === 401 => __('The CMS refused the signature (401): check that both secrets are the same and the clocks are right.'),
                default => __('Unexpected answer :status: :body', ['status' => $response->status(), 'body' => Str::limit($response->body(), 200)]),
            };
        } catch (ConnectionException $exception) {
            $this->testOk = false;
            $this->testResult = __('The CMS could not be reached: :reason', ['reason' => $exception->getMessage()]);
        }

        unset($this->requests);
        Flux::toast(variant: $this->testOk ? 'success' : 'danger', text: $this->testResult);
    }
}; ?>

<section class="w-full max-w-5xl">
    <x-page-header :title="__('CMS integration')" :subtitle="__('The link with the website CMS: articles sent to the website and Tanya Hukum questions. Settings live in .env; this page checks them and shows every call.')">
        <x-slot:actions>
            <flux:button icon="signal" wire:click="test" data-test="test-connection">{{ __('Test connection') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    @if ($testResult)
        <flux:callout class="mb-6" :variant="$testOk ? 'success' : 'danger'" :icon="$testOk ? 'check-circle' : 'exclamation-triangle'" :text="$testResult" />
    @endif

    <div class="mb-8 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @foreach ($this->checks as $check)
            <div class="flex items-start gap-3 px-4 py-2.5 text-sm">
                @if ($check['ok'])
                    <flux:icon.check-circle class="size-5 text-green-600" />
                @else
                    <flux:icon.exclamation-triangle class="size-5 text-amber-500" />
                @endif
                <span class="w-48 shrink-0 font-medium">{{ $check['label'] }}</span>
                <span class="min-w-0 break-words text-zinc-600 dark:text-zinc-300">{{ $check['detail'] }}</span>
            </div>
        @endforeach
    </div>

    <div class="mb-3 flex flex-wrap items-center gap-3">
        <flux:heading class="me-auto">{{ __('Calls') }}</flux:heading>
        <flux:radio.group wire:model.live="show" variant="segmented" size="sm">
            <flux:radio value="all" :label="__('All')" />
            <flux:radio value="failed" :label="__('Not accepted')" />
            <flux:radio value="in" :label="__('From the CMS')" />
        </flux:radio.group>
    </div>
    <div class="overflow-x-auto">
        <flux:table class="table-stack" :paginate="$this->requests">
            <flux:table.columns>
                <flux:table.column>{{ __('Time') }}</flux:table.column>
                <flux:table.column>{{ __('Call') }}</flux:table.column>
                <flux:table.column>{{ __('About') }}</flux:table.column>
                <flux:table.column>{{ __('Answer') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->requests as $request)
                    <flux:table.row :key="$request->id">
                        <flux:table.cell class="whitespace-nowrap text-sm">{{ $request->created_at->translatedFormat('j M H:i:s') }}</flux:table.cell>
                        <flux:table.cell class="text-sm">
                            <flux:badge size="sm" :color="$request->direction === 'in' ? 'sky' : 'zinc'">{{ $request->direction === 'in' ? __('in') : __('out') }}</flux:badge>
                            <span class="font-mono text-xs">{{ $request->method }} {{ $request->endpoint }}</span>
                        </flux:table.cell>
                        <flux:table.cell class="font-mono text-xs">{{ $request->subject ?? '—' }}</flux:table.cell>
                        <flux:table.cell class="whitespace-normal text-xs">
                            <flux:badge size="sm" :color="$request->succeeded() ? 'green' : 'red'">{{ $request->status ?? __('no answer') }}</flux:badge>
                            @if ($request->duration_ms !== null) <span class="text-zinc-500">{{ $request->duration_ms }} ms</span> @endif
                            <div class="mt-1 break-all text-zinc-500">{{ Str::limit((string) ($request->error ?? $request->response), 160) }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4" class="text-center">{{ __('No calls yet.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</section>
