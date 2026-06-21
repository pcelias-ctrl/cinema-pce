(function () {
    const banner = document.getElementById('cookie-banner');
    const storageKey = 'cinesys_cookie_consent';
    if (banner && !localStorage.getItem(storageKey)) banner.hidden = false;
    document.querySelectorAll('[data-cookie-choice]').forEach((button) => {
        button.addEventListener('click', () => {
            localStorage.setItem(storageKey, JSON.stringify({ choice: button.dataset.cookieChoice, at: new Date().toISOString() }));
            banner.hidden = true;
        });
    });

    document.querySelectorAll('input[name="cpf"]').forEach((input) => {
        input.addEventListener('input', () => {
            const value = input.value.replace(/\D/g, '').slice(0, 11);
            input.value = value.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2');
        });
    });
    document.querySelectorAll('input[name="whatsapp"],input[name="phone"]').forEach((input) => {
        input.addEventListener('input', () => {
            const value = input.value.replace(/\D/g, '').slice(0, 11);
            input.value = value.replace(/^(\d{2})(\d)/, '($1) $2').replace(/(\d{5})(\d{4})$/, '$1-$2');
        });
    });
    document.querySelectorAll('input[name="code"]').forEach((input) => {
        input.addEventListener('input', () => { input.value = input.value.replace(/\D/g, '').slice(0, 6); });
    });

    const seatForm = document.getElementById('public-seat-form');
    if (seatForm) {
        const list = document.getElementById('public-selected-seats');
        const total = document.getElementById('public-seat-total');
        const proceed = document.getElementById('public-seat-continue');
        const fullPrice = Number(seatForm.dataset.fullPrice || 0);
        const halfPrice = Number(seatForm.dataset.halfPrice || 0);
        const money = (value) => value.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
        function renderSeats() {
            const selected = [...seatForm.querySelectorAll('.public-seat input:checked')];
            list.innerHTML = '';
            let amount = 0;
            selected.forEach((input) => {
                const row = document.createElement('label');
                row.innerHTML = `<span>Poltrona <strong>${input.dataset.code}</strong></span><select name="seat_types[${Number(input.value)}]"><option value="meia">Meia - ${money(halfPrice)}</option><option value="inteira">Inteira - ${money(fullPrice)}</option></select>`;
                row.querySelector('select').addEventListener('change', renderTotal);
                list.appendChild(row);
                amount += halfPrice;
            });
            if (!selected.length) list.innerHTML = '<p>Nenhuma poltrona selecionada.</p>';
            total.textContent = money(amount);
            proceed.disabled = selected.length === 0;
        }
        function renderTotal() {
            const selects = [...list.querySelectorAll('select')];
            total.textContent = money(selects.reduce((sum, select) => sum + (select.value === 'meia' ? halfPrice : fullPrice), 0));
        }
        seatForm.querySelectorAll('.public-seat input').forEach((input) => input.addEventListener('change', renderSeats));
        renderSeats();
    }

    const timer = document.querySelector('.hold-timer');
    if (timer) {
        const label = timer.querySelector('strong');
        const expiresAt = Number(timer.dataset.expiresEpoch || 0) * 1000;
        const tick = () => {
            const remaining = Math.max(0, Math.floor((expiresAt - Date.now()) / 1000));
            label.textContent = `${String(Math.floor(remaining / 60)).padStart(2, '0')}:${String(remaining % 60).padStart(2, '0')}`;
            if (remaining === 0) window.location.href = '/';
        };
        tick();
        window.setInterval(tick, 1000);
    }

    const checkout = document.getElementById('public-checkout-form');
    if (checkout) {
        const money = (value) => value.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
        const productTotal = document.getElementById('checkout-products-total');
        const grandTotal = document.getElementById('checkout-grand-total');
        function updateCheckout() {
            const products = [...checkout.querySelectorAll('[data-product-price]')].reduce((sum, input) => sum + (Number(input.value) || 0) * Number(input.dataset.productPrice || 0), 0);
            productTotal.textContent = money(products);
            grandTotal.textContent = money(Number(checkout.dataset.ticketTotal || 0) + products);
        }
        checkout.querySelectorAll('[data-product-price]').forEach((input) => input.addEventListener('input', updateCheckout));
        checkout.querySelectorAll('[data-qty-minus],[data-qty-plus]').forEach((button) => button.addEventListener('click', () => {
            const input = button.parentElement.querySelector('[data-product-price]');
            const direction = button.hasAttribute('data-qty-plus') ? 1 : -1;
            input.value = String(Math.max(Number(input.min), Math.min(Number(input.max), Number(input.value) + direction)));
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }));
        updateCheckout();
    }

    document.querySelectorAll('[data-pix-qr]').forEach((element) => {
        if (element.dataset.value && window.QRCode) new QRCode(element, { text: element.dataset.value, width: 220, height: 220, correctLevel: QRCode.CorrectLevel.M });
    });
    document.querySelectorAll('[data-copy-pix]').forEach((button) => button.addEventListener('click', async () => {
        await navigator.clipboard.writeText(document.getElementById('pix-code').value);
        button.textContent = 'Código copiado';
    }));
    if (document.querySelector('.payment-result.pending')) window.setTimeout(() => window.location.reload(), 7000);
})();
