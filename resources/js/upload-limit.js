// Stop a file larger than the upload limit before Livewire sends it: the
// server (PHP) would refuse it with an unclear "failed to upload" message.
const meta = document.querySelector('meta[name="upload-max-kb"]');

if (meta) {
    const maxBytes = Number(meta.content) * 1024;

    window.addEventListener('change', (event) => {
        const input = event.target;

        if (!(input instanceof HTMLInputElement) || input.type !== 'file') {
            return;
        }

        if (!Array.from(input.files ?? []).some((file) => file.size > maxBytes)) {
            return;
        }

        // Capture phase on window: Livewire's own listener on the input never runs.
        event.stopImmediatePropagation();
        input.value = '';

        if (window.Flux?.toast) {
            window.Flux.toast({ variant: 'danger', text: meta.dataset.message });
        } else {
            window.alert(meta.dataset.message);
        }
    }, true);
}
