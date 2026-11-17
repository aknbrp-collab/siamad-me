{{--
    The confirmation dialog for every wire:confirm (v7 D46), driven by resources/js/confirm-dialog.js.
    Once per page, in the app layout. data-tone="danger" makes it red (removing, withdrawing …).
--}}
<dialog id="confirm-dialog" aria-labelledby="confirm-dialog-title" aria-describedby="confirm-dialog-body" data-tone="normal"
    data-test="confirm-dialog"
    class="group m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border border-zinc-200 bg-white p-0 text-zinc-800 shadow-2xl
        backdrop:bg-zinc-950/40 backdrop:backdrop-blur-[2px] dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100
        opacity-0 scale-95 transition-all transition-discrete duration-150 ease-out open:opacity-100 open:scale-100
        starting:open:opacity-0 starting:open:scale-95">
    <form method="dialog" class="p-5 sm:p-6">
        <div class="flex items-start gap-4">
            <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-soft text-accent-content
                group-data-[tone=danger]:bg-red-50 group-data-[tone=danger]:text-red-600 dark:group-data-[tone=danger]:bg-red-500/15 dark:group-data-[tone=danger]:text-red-400">
                {{-- A question mark normally, a warning when the action removes something. --}}
                <svg class="size-6 group-data-[tone=danger]:hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" />
                </svg>
                <svg class="hidden size-6 group-data-[tone=danger]:block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                </svg>
            </div>
            <div class="min-w-0 flex-1 pt-1">
                <h2 id="confirm-dialog-title" data-title class="text-base font-semibold text-zinc-900 dark:text-white"></h2>
                <p id="confirm-dialog-body" data-body class="mt-1 whitespace-pre-line text-sm text-zinc-600 dark:text-zinc-300"></p>
                <label data-prompt hidden class="mt-3 block">
                    <span data-prompt-hint class="mb-1 block text-xs text-zinc-500 dark:text-zinc-400" data-text="{{ __('Type :word to continue.') }}"></span>
                    <input data-prompt-input type="text" autocomplete="off" spellcheck="false"
                        class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm shadow-xs outline-none focus:border-zinc-400 focus:ring-2 focus:ring-[var(--color-accent)]/30 dark:border-zinc-600 dark:bg-zinc-900">
                </label>
            </div>
        </div>
        <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="submit" value="cancel" data-cancel
                class="inline-flex h-10 items-center justify-center rounded-lg px-4 text-sm font-medium text-zinc-700 hover:bg-zinc-800/5 dark:text-zinc-200 dark:hover:bg-white/10">
                {{ __('Cancel') }}
            </button>
            <button type="submit" value="ok" data-ok data-test="confirm-ok"
                class="inline-flex h-10 items-center justify-center rounded-lg px-4 text-sm font-medium shadow-xs disabled:cursor-not-allowed disabled:opacity-50
                    bg-[var(--color-accent)] text-[var(--color-accent-foreground)] hover:bg-[color-mix(in_oklab,_var(--color-accent),_transparent_10%)]
                    group-data-[tone=danger]:bg-red-600 group-data-[tone=danger]:text-white group-data-[tone=danger]:hover:bg-red-700">
                {{ __('Yes, continue') }}
            </button>
        </div>
    </form>
</dialog>
