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
    const continueButton = document.getElementById('continue-sale');
    const wizard = document.getElementById('sale-wizard');
    const wizardTotal = document.getElementById('wizard-total');
    const wizardPaidRow = document.getElementById('wizard-paid-row');
    const wizardAmountPaid = document.getElementById('wizard-amount-paid');

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

    function productTotal() {
        return [...form.querySelectorAll('.product-pick-card')].reduce((sum, card) => {
            const quantity = parseInt(card.querySelector('input').value, 10) || 0;
            return sum + quantity * (parseFloat(card.dataset.price) || 0);
        }, 0);
    }

    function update() {
        const selected = selectedSeats();
        const ticketTotal = selected.reduce((sum, input) => sum + (input.dataset.ticketType === 'meia' ? halfPrice : fullPrice), 0);
        const total = ticketTotal + productTotal();
        const seatCodes = selected.map((input) => {
            const code = input.closest('.sale-seat').querySelector('span').textContent;
            return `${code} (${input.dataset.ticketType === 'meia' ? 'M' : 'I'})`;
        });
        seatsLabel.textContent = seatCodes.length ? seatCodes.join(', ') : 'Nenhuma';
        totalLabel.textContent = money(total);
        if (wizardTotal) wizardTotal.textContent = money(total);

        const isCash = method.value === 'dinheiro';
        amountRow.style.display = isCash ? 'grid' : 'none';
        changeRow.style.display = isCash ? 'block' : 'none';
        wizardPaidRow.style.display = isCash ? 'grid' : 'none';
        const paid = isCash ? parseMoney(amountPaid.value) : total;
        changeLabel.textContent = money(Math.max(0, paid - total));
        const blocked = selected.length === 0 || (isCash && paid < total);
        finishButton.disabled = blocked;
        continueButton.disabled = selected.length === 0;
    }

    form.querySelectorAll('.sale-seat input[type="checkbox"]').forEach((input) => {
        input.addEventListener('change', () => {
            if (input.checked) setSeatType(input, activeTicketType());
            else setSeatType(input, input.dataset.ticketType || 'inteira');
            update();
        });
    });
    continueButton.addEventListener('click', () => {
        wizardAmountPaid.value = amountPaid.value;
        wizard.hidden = false;
        document.body.classList.add('wizard-open');
        update();
    });
    function closeWizard() {
        wizard.hidden = true;
        document.body.classList.remove('wizard-open');
    }
    document.getElementById('close-wizard').addEventListener('click', closeWizard);
    document.getElementById('back-to-seats').addEventListener('click', closeWizard);
    wizardAmountPaid.addEventListener('input', () => {
        amountPaid.value = wizardAmountPaid.value;
        update();
    });
    amountPaid.addEventListener('input', () => {
        wizardAmountPaid.value = amountPaid.value;
    });
    form.querySelectorAll('.product-category-tree button').forEach((button) => {
        button.addEventListener('click', () => {
            form.querySelectorAll('.product-category-tree button').forEach((item) => item.classList.toggle('active', item === button));
            form.querySelectorAll('.product-pick-card').forEach((card) => {
                card.hidden = button.dataset.category !== 'all' && card.dataset.category !== button.dataset.category;
            });
        });
    });
    form.querySelectorAll('.quantity-stepper button').forEach((button) => {
        button.addEventListener('click', () => {
            const input = button.parentElement.querySelector('input');
            input.value = Math.min(20, Math.max(0, (parseInt(input.value, 10) || 0) + parseInt(button.dataset.delta, 10)));
            button.closest('.product-pick-card').classList.toggle('selected', parseInt(input.value, 10) > 0);
            update();
        });
    });
    form.addEventListener('change', update);
    form.addEventListener('input', update);
    update();
})();
