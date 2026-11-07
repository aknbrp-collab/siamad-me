{{-- Brand image cards with upload / replace / remove (pages using ManagesBrandImages). --}}
@props(['images', 'columns' => 'sm:grid-cols-3'])

<div {{ $attributes->class(['grid gap-4', $columns]) }}>
    @foreach ($images as $image => $info)
        @php($preview = \App\Support\Branding::dataUri(\App\Support\Branding::imagePath($image)))
        <div wire:key="image-{{ $image }}" class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="font-medium">{{ $info['label'] }}</div>
            <div class="flex h-24 items-center justify-center rounded-md bg-zinc-50 p-2 dark:bg-white">
                @if ($preview)
                    <img src="{{ $preview }}" alt="{{ $info['label'] }}" class="max-h-20 max-w-full object-contain">
                @else
                    <span class="text-xs text-zinc-500">{{ $info['empty'] ?? __('Not set') }}</span>
                @endif
            </div>
            <flux:text class="text-xs">{{ $info['hint'] }}</flux:text>
            <div class="mt-auto flex items-center gap-2">
                <label class="cursor-pointer">
                    <span class="inline-flex items-center gap-1 rounded-md border border-zinc-200 px-2.5 py-1.5 text-sm font-medium hover:bg-zinc-50 dark:border-zinc-600 dark:hover:bg-zinc-700">
                        <flux:icon.arrow-up-tray class="size-4" /> {{ $preview ? __('Replace') : __('Upload') }}
                    </span>
                    <input type="file" class="sr-only" accept="image/png,image/jpeg,image/webp" wire:model="uploads.{{ $image }}" data-test="upload-{{ $image }}">
                </label>
                @if ($preview)
                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeImage('{{ $image }}')" wire:confirm="{{ __('Remove this image?') }}" :aria-label="__('Remove')" />
                @endif
                <span wire:loading wire:target="uploads.{{ $image }}" class="text-xs text-zinc-500">{{ __('Uploading…') }}</span>
            </div>
            <flux:error name="uploads.{{ $image }}" />
        </div>
    @endforeach
</div>
