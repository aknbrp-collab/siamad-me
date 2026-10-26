/**
 * Tables with the "table-stack" class turn into stacked cards on small
 * screens (see app.css). Each cell is labelled with its column header here,
 * so the Blade markup only needs the class. Re-runs whenever the DOM changes
 * (Livewire navigation, search, pagination).
 */
function labelCells(table) {
    const headers = [];

    table.querySelectorAll('thead th').forEach((th) => {
        const span = Number(th.getAttribute('colspan') ?? 1);

        for (let i = 0; i < span; i++) {
            headers.push(th.textContent.trim());
        }
    });

    table.querySelectorAll('tbody tr').forEach((row) => {
        let index = 0;

        for (const cell of row.cells) {
            const label = headers[index] ?? '';

            if (cell.colSpan === 1 && label !== '' && cell.dataset.label !== label) {
                cell.dataset.label = label;
            }

            index += cell.colSpan;
        }
    });
}

let scheduled = false;

function labelAll() {
    scheduled = false;
    document.querySelectorAll('table.table-stack').forEach(labelCells);
}

function schedule() {
    if (!scheduled) {
        scheduled = true;
        requestAnimationFrame(labelAll);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    labelAll();
    new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
});
