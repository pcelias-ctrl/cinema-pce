(function () {
    const canvas = document.getElementById('canvas');
    const screen = document.getElementById('screen');
    const layoutInput = document.getElementById('seat-layout');
    const screenInput = document.getElementById('screen-config');
    const summary = document.getElementById('seat-summary');
    const normalInput = document.getElementById('normal-seats');
    const largeInput = document.getElementById('large-seats');
    let seats = [];
    let selectedSeatId = null;

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function readJson(input, fallback) {
        try {
            const value = JSON.parse(input.value || '');
            return value || fallback;
        } catch (error) {
            return fallback;
        }
    }

    function persist() {
        layoutInput.value = JSON.stringify(seats);
        screenInput.value = JSON.stringify({
            x: parseFloat(screen.style.left || 270),
            y: parseFloat(screen.style.top || 28),
            w: parseFloat(screen.style.width || 500),
            h: parseFloat(screen.style.height || 34)
        });
        const large = seats.filter((seat) => seat.type === 'grande').length;
        const unavailable = seats.filter((seat) => seat.unavailable).length;
        summary.textContent = `${seats.length} poltronas | ${seats.length - large} normais | ${large} grandes | ${unavailable} inutilizadas`;
    }

    function normalizeSeat(seat, index) {
        const row = String(seat.row || 'A').trim().toUpperCase() || 'A';
        const number = parseInt(seat.number, 10) || (index + 1);
        return {
            id: seat.id || `${row}-${number}`,
            row,
            number,
            type: seat.type === 'grande' ? 'grande' : 'normal',
            unavailable: seat.unavailable === true || seat.unavailable === 1 || seat.unavailable === '1',
            x: parseFloat(seat.x ?? 0),
            y: parseFloat(seat.y ?? 0),
            w: parseFloat(seat.w ?? (seat.type === 'grande' ? 62 : 44)),
            h: parseFloat(seat.h ?? 44)
        };
    }

    function makeDraggable(element, onMove) {
        let start = null;
        element.addEventListener('pointerdown', (event) => {
            event.preventDefault();
            element.setPointerCapture(event.pointerId);
            const rect = canvas.getBoundingClientRect();
            start = {
                offsetX: event.clientX - rect.left - parseFloat(element.style.left || 0),
                offsetY: event.clientY - rect.top - parseFloat(element.style.top || 0)
            };
        });
        element.addEventListener('pointermove', (event) => {
            if (!start) return;
            const rect = canvas.getBoundingClientRect();
            const width = element.offsetWidth;
            const height = element.offsetHeight;
            const x = clamp(event.clientX - rect.left - start.offsetX, 0, canvas.clientWidth - width);
            const y = clamp(event.clientY - rect.top - start.offsetY, 0, canvas.clientHeight - height);
            element.style.left = `${Math.round(x)}px`;
            element.style.top = `${Math.round(y)}px`;
            onMove(x, y);
            persist();
        });
        element.addEventListener('pointerup', () => { start = null; });
    }

    function render() {
        canvas.querySelectorAll('.seat').forEach((node) => node.remove());
        seats.forEach((seat) => {
            const node = document.createElement('button');
            node.type = 'button';
            node.className = `seat ${seat.type === 'grande' ? 'large' : ''} ${seat.unavailable ? 'unavailable' : ''} ${seat.id === selectedSeatId ? 'selected' : ''}`;
            node.textContent = `${seat.row}${seat.number}`;
            node.style.left = `${seat.x}px`;
            node.style.top = `${seat.y}px`;
            node.dataset.id = seat.id;
            node.addEventListener('pointerdown', () => {
                selectedSeatId = seat.id;
            }, { capture: true });
            node.addEventListener('click', () => {
                selectedSeatId = seat.id;
                render();
            });
            node.addEventListener('dblclick', () => {
                seat.unavailable = !seat.unavailable;
                selectedSeatId = seat.id;
                render();
            });
            makeDraggable(node, (x, y) => {
                seat.x = Math.round(x);
                seat.y = Math.round(y);
            });
            canvas.appendChild(node);
        });
        persist();
    }

    function loadInitial() {
        seats = readJson(layoutInput, []).map(normalizeSeat);
        const screenConfig = readJson(screenInput, {});
        if (screenConfig.x !== undefined) screen.style.left = `${screenConfig.x}px`;
        if (screenConfig.y !== undefined) screen.style.top = `${screenConfig.y}px`;
        if (screenConfig.w !== undefined) screen.style.width = `${screenConfig.w}px`;
        if (screenConfig.h !== undefined) screen.style.height = `${screenConfig.h}px`;
        hydrateGeneratorFields();
        render();
    }

    function hydrateGeneratorFields() {
        if (!seats.length) return;

        const rowsInput = document.getElementById('rows');
        const perRowInput = document.getElementById('seats-per-row');
        const rows = [...new Set(seats.map((seat) => seat.row).filter(Boolean))];
        const maxPerRow = rows.reduce((max, row) => {
            return Math.max(max, seats.filter((seat) => seat.row === row).length);
        }, 0);

        if (!rowsInput.value.trim()) rowsInput.value = rows.join(',');
        if (!perRowInput.value.trim() && maxPerRow > 0) perRowInput.value = String(maxPerRow);
    }

    document.getElementById('generate-layout').addEventListener('click', () => {
        const rowsInput = document.getElementById('rows');
        const perRowInput = document.getElementById('seats-per-row');
        const largeTarget = parseInt(largeInput.value, 10) || 0;
        const total = (parseInt(normalInput.value, 10) || 0) + largeTarget;
        const perRow = parseInt(perRowInput.value, 10) || Math.max(1, Math.ceil(Math.sqrt(Math.max(1, total))));
        let rows = rowsInput.value.split(',').map((row) => row.trim().toUpperCase()).filter(Boolean);
        const next = [];
        let count = 0;

        if (!rows.length && total > 0) {
            const rowCount = Math.max(1, Math.ceil(total / perRow));
            rows = Array.from({ length: rowCount }, (_, index) => String.fromCharCode(65 + index));
            rowsInput.value = rows.join(',');
        }

        perRowInput.value = String(perRow);

        rows.forEach((row, rowIndex) => {
            for (let number = 1; number <= perRow && count < total; number += 1) {
                const aisleOffset = number > Math.ceil(perRow / 2) ? 34 : 0;
                next.push({
                    id: `${row}-${number}-${Date.now()}-${count}`,
                    row,
                    number,
                    type: count >= total - largeTarget ? 'grande' : 'normal',
                    unavailable: false,
                    x: 72 + (number - 1) * 48 + aisleOffset,
                    y: 110 + rowIndex * 58,
                    w: count >= total - largeTarget ? 62 : 44,
                    h: 44
                });
                count += 1;
            }
        });

        seats = next;
        selectedSeatId = null;
        render();
    });

    document.getElementById('add-large-seat').addEventListener('click', () => {
        const seat = seats.find((item) => item.id === selectedSeatId);
        if (!seat) return;
        seat.type = seat.type === 'grande' ? 'normal' : 'grande';
        seat.w = seat.type === 'grande' ? 62 : 44;
        render();
    });

    document.getElementById('toggle-unavailable-seat').addEventListener('click', () => {
        const seat = seats.find((item) => item.id === selectedSeatId);
        if (!seat) return;
        seat.unavailable = !seat.unavailable;
        render();
    });

    document.getElementById('fit-screen').addEventListener('click', () => {
        screen.style.left = '220px';
        screen.style.top = '28px';
        screen.style.width = '600px';
        screen.style.height = '34px';
        persist();
    });

    document.getElementById('clear-layout').addEventListener('click', () => {
        if (seats.length > 0 && !window.confirm('Limpar o mapa atual da sala?')) {
            return;
        }

        seats = [];
        selectedSeatId = null;
        screen.style.left = '270px';
        screen.style.top = '28px';
        screen.style.width = '500px';
        screen.style.height = '34px';
        render();
    });

    document.getElementById('room-form').addEventListener('submit', persist);

    makeDraggable(screen, persist);
    loadInitial();
})();
