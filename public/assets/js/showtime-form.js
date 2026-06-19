(function () {
    const dateInput = document.getElementById('showtime-date');
    const timeInput = document.getElementById('showtime-time');
    const addButton = document.getElementById('add-showtime');
    const list = document.getElementById('showtime-list');
    const form = document.getElementById('showtime-form');

    function formatDateTime(value) {
        const [date, time] = value.split('T');
        const [year, month, day] = date.split('-');
        return `${day}/${month}/${year} ${time}`;
    }

    function sortRows() {
        [...list.querySelectorAll('.showtime-row')]
            .sort((a, b) => a.dataset.value.localeCompare(b.dataset.value))
            .forEach((row) => list.appendChild(row));
    }

    function addShowtime(value) {
        if (!value || list.querySelector(`[data-value="${value}"]`)) {
            return;
        }

        const row = document.createElement('div');
        row.className = 'showtime-row';
        row.dataset.value = value;
        row.innerHTML = `
            <span>${formatDateTime(value)}</span>
            <input type="hidden" name="starts_at[]" value="${value}">
            <button type="button" class="button danger">Remover</button>
        `;
        row.querySelector('button').addEventListener('click', () => row.remove());
        list.appendChild(row);
        sortRows();
    }

    addButton.addEventListener('click', () => {
        if (!dateInput.value || !timeInput.value) {
            return;
        }

        addShowtime(`${dateInput.value}T${timeInput.value}`);
        timeInput.value = '';
        timeInput.focus();
    });

    try {
        JSON.parse(list.dataset.initial || '[]').forEach(addShowtime);
    } catch (error) {
        // Keeps the form usable if a stale browser cache serves malformed data.
    }

    form.addEventListener('submit', (event) => {
        if (!list.querySelector('input[name="starts_at[]"]')) {
            event.preventDefault();
            dateInput.focus();
        }
    });
})();
