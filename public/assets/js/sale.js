(function () {
    const form = document.getElementById('sale-form');
    const fullPrice = parseFloat(form.dataset.fullPrice || '0');
    const halfPrice = parseFloat(form.dataset.halfPrice || '0');
    const seatsLabel = document.getElementById('selected-seats');
    const totalLabel = document.getElementById('sale-total');
    const method = document.getElementById('payment-method');
    const amountRow = document.getElementById('amount-paid-row');
    const amountPaid = document.getElementById('amount-paid');
    const changeRow = document.getElementById('change-row');
    const changeLabel = document.getElementById('sale-change');
    const finishButton = document.getElementById('finish-sale');

    function parseMoney(value) {
        value = String(value || '').trim();
        if (value.includes(',')) {
            value = value.replace(/\./g, '').replace(',', '.');
        }
        return Math.max(0, parseFloat(value) || 0);
    }

    function money(value) {
        return value.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    function selectedSeats() {
        return [...form.querySelectorAll('.sale-seat input[type="checkbox"]:checked')];
    }

    function activeTicketType() {
        return form.querySelector('input[name="active_ticket_type"]:checked')?.value === 'meia' ? 'meia' : 'inteira';
    }

    function setSeatType(input, type) {
        const seat = input.closest('.sale-seat');
        const hidden = seat.querySelector('.seat-ticket-type');
        const normalized = type === 'meia' ? 'meia' : 'inteira';
        input.dataset.ticketType = normalized;
        hidden.value = normalized;
        hidden.disabled = !input.checked;
        seat.classList.toggle('selected-half', input.checked && normalized === 'meia');
        seat.classList.toggle('selected-full', input.checked && normalized === 'inteira');
    }

    function update() {
        const selected = selectedSeats();
        const total = selected.reduce((sum, input) => sum + (input.dataset.ticketType === 'meia' ? halfPrice : fullPrice), 0);
        const seatCodes = selected.map((input) => {
            const code = input.closest('.sale-seat').querySelector('span').textContent;
            return `${code} (${input.dataset.ticketType === 'meia' ? 'M' : 'I'})`;
        });
        seatsLabel.textContent = seatCodes.length ? seatCodes.join(', ') : 'Nenhuma';
        totalLabel.textContent = money(total);

        const isCash = method.value === 'dinheiro';
        amountRow.style.display = isCash ? 'grid' : 'none';
        changeRow.style.display = isCash ? 'block' : 'none';
        const paid = isCash ? parseMoney(amountPaid.value) : total;
        changeLabel.textContent = money(Math.max(0, paid - total));
        finishButton.disabled = selected.length === 0 || (isCash && paid < total);
    }

    form.querySelectorAll('.sale-seat input[type="checkbox"]').forEach((input) => {
        input.addEventListener('change', () => {
            if (input.checked) setSeatType(input, activeTicketType());
            else setSeatType(input, input.dataset.ticketType || 'inteira');
            update();
        });
    });
    form.addEventListener('change', update);
    form.addEventListener('input', update);
    update();
})();
