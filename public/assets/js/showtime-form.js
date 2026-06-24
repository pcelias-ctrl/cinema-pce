(function () {
    const dateInput = document.getElementById('showtime-date');
    const timeInput = document.getElementById('showtime-time');
    const addButton = document.getElementById('add-showtime');
    const periodFrom = document.getElementById('period-date-from');
    const periodTo = document.getElementById('period-date-to');
    const periodTimes = document.getElementById('period-times');
    const generatePeriodButton = document.getElementById('generate-period-showtimes');
    const list = document.getElementById('showtime-list');
    const form = document.getElementById('showtime-form');
    const fullPrice = form.querySelector('input[name="price"]');
    const halfPrice = form.querySelector('input[name="half_price"]');
    let halfPriceEdited = halfPrice.value.trim() !== '';

    fullPrice.addEventListener('input', () => {
        if (halfPriceEdited) return;
        const value = parseFloat(fullPrice.value.replace(/\./g, '').replace(',', '.'));
        halfPrice.value = Number.isFinite(value) && value > 0 ? (value / 2).toFixed(2).replace('.', ',') : '';
    });
    halfPrice.addEventListener('input', () => {
        halfPriceEdited = halfPrice.value.trim() !== '';
    });

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

    function parsePeriodTimes(value) {
        return [...new Set(String(value || '').split(/[\s,;]+/).map((item) => item.trim()).filter(Boolean))]
            .map((item) => {
                const match = item.match(/^(\d{1,2}):(\d{2})$/);
                if (!match) return '';
                const hour = Number(match[1]);
                const minute = Number(match[2]);
                if (hour < 0 || hour > 23 || minute < 0 || minute > 59) return '';
                return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
            })
            .filter(Boolean);
    }

    function selectedWeekdays() {
        const selected = [...form.querySelectorAll('input[name="period_weekdays[]"]:checked')].map((input) => Number(input.value));
        return selected.length ? selected : [1, 2, 3, 4, 5, 6, 7];
    }

    generatePeriodButton?.addEventListener('click', () => {
        if (!periodFrom.value || !periodTo.value || !periodTimes.value) return;
        const from = new Date(`${periodFrom.value}T00:00:00`);
        const to = new Date(`${periodTo.value}T00:00:00`);
        const times = parsePeriodTimes(periodTimes.value);
        const weekdays = selectedWeekdays();
        if (!Number.isFinite(from.getTime()) || !Number.isFinite(to.getTime()) || to < from || !times.length) return;

        const cursor = new Date(from);
        while (cursor <= to) {
            const weekday = cursor.getDay() === 0 ? 7 : cursor.getDay();
            if (weekdays.includes(weekday)) {
                const date = cursor.toISOString().slice(0, 10);
                times.forEach((time) => addShowtime(`${date}T${time}`));
            }
            cursor.setDate(cursor.getDate() + 1);
        }
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
