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
    const notice = document.getElementById('sale-seat-notice');
    const csrf = form.querySelector('input[name="csrf_token"]')?.value || '';
    const showtimeId = form.dataset.showtimeId || '';
    const statusUrl = form.dataset.seatStatusUrl || '';
    const holdUrl = form.dataset.seatHoldUrl || '';
    const voucherLookupUrl = form.dataset.voucherLookupUrl || '';
    const voucherInput = document.getElementById('voucher-code');
    const voucherAdd = document.getElementById('voucher-add');
    const voucherList = document.getElementById('voucher-used-list');
    const voucherMessage = document.getElementById('voucher-message');
    const voucherDiscountLabel = document.getElementById('voucher-discount');
    const vouchers = new Map();
    let statusTimer = null;
    let holdRenewTimer = null;
    let saleSubmitting = false;

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

    function showNotice(message) {
        if (!notice) return;
        notice.textContent = message;
        notice.hidden = !message;
        if (message) {
            window.clearTimeout(showNotice.timeout);
            showNotice.timeout = window.setTimeout(() => {
                notice.hidden = true;
            }, 6000);
        }
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

    function selectedTicketPrices() {
        return selectedSeats()
            .map((input) => input.dataset.ticketType === 'meia' ? halfPrice : fullPrice)
            .sort((left, right) => right - left);
    }

    function voucherDiscount() {
        return selectedTicketPrices()
            .slice(0, vouchers.size)
            .reduce((sum, price) => sum + price, 0);
    }

    function setVoucherMessage(message, error) {
        if (!voucherMessage) return;
        voucherMessage.textContent = message || '';
        voucherMessage.classList.toggle('error', Boolean(error));
    }

    function renderVouchers() {
        if (!voucherList) return;
        voucherList.replaceChildren();
        vouchers.forEach((voucher, token) => {
            const item = document.createElement('span');
            item.className = 'voucher-used';
            const text = document.createElement('b');
            text.textContent = `Voucher ...${token.slice(-8)} · até ${voucher.validUntil}`;
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'voucher_tokens[]';
            hidden.value = token;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.setAttribute('aria-label', `Remover voucher ${token}`);
            remove.textContent = '×';
            remove.addEventListener('click', () => {
                vouchers.delete(token);
                renderVouchers();
                setVoucherMessage('');
                update();
            });
            item.append(text, hidden, remove);
            voucherList.append(item);
        });
    }

    async function addVoucher() {
        if (!voucherInput || !voucherLookupUrl) return;
        const match = voucherInput.value.trim().toUpperCase().match(/VCH-[A-F0-9]{20}/);
        const token = match ? match[0] : voucherInput.value.trim().toUpperCase();
        if (!token) {
            setVoucherMessage('Leia ou digite o código do voucher.', true);
            return;
        }
        if (vouchers.has(token)) {
            setVoucherMessage('Este voucher já foi adicionado.', true);
            voucherInput.select();
            return;
        }
        if (vouchers.size >= selectedSeats().length) {
            setVoucherMessage('Cada voucher vale um ingresso. Não há outro ingresso selecionado.', true);
            return;
        }

        voucherAdd.disabled = true;
        setVoucherMessage('Validando voucher...');
        try {
            const response = await fetch(`${voucherLookupUrl}&token=${encodeURIComponent(token)}`, { credentials: 'same-origin' });
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.message || 'Voucher inválido.');
            vouchers.set(payload.token, { validUntil: payload.valid_until });
            voucherInput.value = '';
            renderVouchers();
            setVoucherMessage('Voucher aceito.');
            update();
            voucherInput.focus();
        } catch (error) {
            setVoucherMessage(error.message || 'Não foi possível validar o voucher.', true);
            voucherInput.select();
        } finally {
            voucherAdd.disabled = false;
        }
    }

    function update() {
        const selected = selectedSeats();
        const ticketTotal = selectedTicketPrices().reduce((sum, price) => sum + price, 0);
        const discount = voucherDiscount();
        const total = Math.max(0, ticketTotal - discount) + productTotal();
        const seatCodes = selected.map((input) => {
            const code = input.closest('.sale-seat').querySelector('span').textContent;
            return `${code} (${input.dataset.ticketType === 'meia' ? 'M' : 'I'})`;
        });
        seatsLabel.textContent = seatCodes.length ? seatCodes.join(', ') : 'Nenhuma';
        totalLabel.textContent = money(total);
        if (wizardTotal) wizardTotal.textContent = money(total);
        if (voucherDiscountLabel) voucherDiscountLabel.textContent = discount > 0 ? `Vouchers: - ${money(discount)}` : '';

        const isCash = method.value === 'dinheiro';
        amountRow.style.display = isCash ? 'grid' : 'none';
        changeRow.style.display = isCash ? 'block' : 'none';
        const paid = isCash ? parseMoney(amountPaid.value) : total;
        changeLabel.textContent = money(Math.max(0, paid - total));
        const blocked = selected.length === 0 || vouchers.size > selected.length || (isCash && paid < total);
        finishButton.disabled = blocked;
        continueButton.disabled = selected.length === 0;
    }

    async function seatRequest(action, seatId) {
        if (!holdUrl || !showtimeId || !seatId) return { ok: true };
        const body = new URLSearchParams();
        body.set('csrf_token', csrf);
        body.set('showtime_id', showtimeId);
        body.set('seat_id', seatId);
        body.set('action', action);
        const response = await fetch(holdUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body,
            credentials: 'same-origin'
        });
        return response.json();
    }

    function applySeatStatuses(seats) {
        const checkedBefore = new Set(selectedSeats().map((input) => input.value));
        seats.forEach((seat) => {
            const label = form.querySelector(`.sale-seat[data-seat-id="${seat.id}"]`);
            if (!label) return;
            const input = label.querySelector('input[type="checkbox"]');
            const blocked = seat.unavailable || seat.sold || seat.held;
            label.classList.toggle('unavailable', Boolean(seat.unavailable));
            label.classList.toggle('held', Boolean(seat.held));
            label.classList.toggle('sold', Boolean(seat.sold || seat.held));
            if (blocked && !seat.held_by_me) {
                if (input.checked) {
                    input.checked = false;
                    setSeatType(input, input.dataset.ticketType || 'inteira');
                    showNotice(seat.held ? 'Essa poltrona acabou de ser selecionada em outro terminal.' : 'Essa poltrona acabou de ficar indisponivel.');
                }
                input.disabled = true;
            } else {
                input.disabled = false;
            }
        });
        const checkedAfter = new Set(selectedSeats().map((input) => input.value));
        if (checkedBefore.size !== checkedAfter.size) update();
    }

    async function refreshSeats() {
        if (!statusUrl || !showtimeId) return;
        try {
            const response = await fetch(`${statusUrl}&showtime_id=${encodeURIComponent(showtimeId)}`, { credentials: 'same-origin' });
            const payload = await response.json();
            if (payload.ok && Array.isArray(payload.seats)) applySeatStatuses(payload.seats);
        } catch (error) {
            // Mantem a venda fluida mesmo se uma consulta pontual falhar.
        }
    }

    async function renewSelectedHolds() {
        if (!holdUrl || !showtimeId) return;
        await Promise.allSettled(selectedSeats().map((input) => seatRequest('hold', input.value)));
    }

    form.querySelectorAll('.sale-seat input[type="checkbox"]').forEach((input) => {
        input.addEventListener('change', async () => {
            input.disabled = true;
            try {
                if (input.checked) {
                    const result = await seatRequest('hold', input.value);
                    if (!result.ok) {
                        input.checked = false;
                        input.disabled = false;
                        showNotice(result.message || 'Essa poltrona acabou de ser selecionada em outro terminal.');
                        if (Array.isArray(result.seats)) applySeatStatuses(result.seats);
                        update();
                        return;
                    }
                    setSeatType(input, activeTicketType());
                    if (Array.isArray(result.seats)) applySeatStatuses(result.seats);
                } else {
                    await seatRequest('release', input.value);
                    setSeatType(input, input.dataset.ticketType || 'inteira');
                    input.disabled = false;
                }
            } catch (error) {
                input.checked = false;
                setSeatType(input, input.dataset.ticketType || 'inteira');
                input.disabled = false;
                showNotice('Falha ao reservar a poltrona. Tente novamente.');
            }
            update();
        });
    });
    continueButton.addEventListener('click', () => {
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
    voucherAdd?.addEventListener('click', addVoucher);
    voucherInput?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        addVoucher();
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
    form.addEventListener('submit', () => {
        if (selectedSeats().length === 0 || finishButton.disabled) return;
        saleSubmitting = true;
        const popup = window.open('', 'cinema_sale_print_pending', 'popup=yes,width=420,height=720');
        if (!popup) return;
        popup.document.open();
        popup.document.write('<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><title>Preparando impressao</title><style>body{margin:0;display:grid;min-height:100vh;place-items:center;font:14px Arial,sans-serif;color:#111;background:#fff}main{width:260px;text-align:center}strong{display:block;margin-bottom:8px;font-size:18px}</style></head><body><main><strong>Preparando impressao</strong><p>Aguarde a finalizacao da venda.</p></main></body></html>');
        popup.document.close();
        popup.focus();
    });
    window.addEventListener('beforeunload', () => {
        if (saleSubmitting) return;
        selectedSeats().forEach((input) => {
            if (!holdUrl || !showtimeId) return;
            const body = new URLSearchParams();
            body.set('csrf_token', csrf);
            body.set('showtime_id', showtimeId);
            body.set('seat_id', input.value);
            body.set('action', 'release');
            navigator.sendBeacon?.(holdUrl, body);
        });
    });
    refreshSeats();
    statusTimer = window.setInterval(refreshSeats, 4000);
    holdRenewTimer = window.setInterval(renewSelectedHolds, 10000);
    update();
})();
