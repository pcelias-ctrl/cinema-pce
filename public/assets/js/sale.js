(function () {
    const form = document.getElementById('sale-form');
    const price = parseFloat(form.dataset.price || '0');
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
        return [...form.querySelectorAll('.sale-seat input:checked')];
    }

    function update() {
        const selected = selectedSeats();
        const total = selected.length * price;
        const seatCodes = selected.map((input) => input.closest('.sale-seat').querySelector('span').textContent);
        seatsLabel.textContent = seatCodes.length ? seatCodes.join(', ') : 'Nenhuma';
        totalLabel.textContent = money(total);

        const isCash = method.value === 'dinheiro';
        amountRow.style.display = isCash ? 'grid' : 'none';
        changeRow.style.display = isCash ? 'block' : 'none';
        const paid = isCash ? parseMoney(amountPaid.value) : total;
        changeLabel.textContent = money(Math.max(0, paid - total));
        finishButton.disabled = selected.length === 0 || (isCash && paid < total);
    }

    form.addEventListener('change', update);
    form.addEventListener('input', update);
    update();
})();

