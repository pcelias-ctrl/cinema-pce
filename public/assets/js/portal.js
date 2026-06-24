(function () {
    document.querySelectorAll('.program-card[data-buy-url]').forEach((card) => {
        card.addEventListener('click', (event) => {
            if (event.target.closest('a,button,input,select,textarea')) return;
            window.location.href = card.dataset.buyUrl;
        });
    });

    document.querySelectorAll('[data-open-modal]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const modal = document.getElementById(button.dataset.openModal || '');
            if (modal && typeof modal.showModal === 'function') modal.showModal();
        });
    });
    document.querySelectorAll('[data-close-modal]').forEach((button) => {
        button.addEventListener('click', () => button.closest('dialog')?.close());
    });
    document.querySelectorAll('.movie-modal').forEach((modal) => {
        modal.addEventListener('click', (event) => {
            if (event.target === modal) modal.close();
        });
    });

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
        const method = document.getElementById('public-payment-method');
        const cardFields = document.getElementById('public-card-fields');
        const payButton = document.getElementById('public-pay-button');
        function updateCheckout() {
            const products = [...checkout.querySelectorAll('[data-product-price]')].reduce((sum, input) => sum + (Number(input.value) || 0) * Number(input.dataset.productPrice || 0), 0);
            productTotal.textContent = money(products);
            grandTotal.textContent = money(Number(checkout.dataset.ticketTotal || 0) + products);
            if (cardFields && method) cardFields.hidden = method.value !== 'cartao';
        }
        checkout.querySelectorAll('[data-product-price]').forEach((input) => input.addEventListener('input', updateCheckout));
        checkout.querySelectorAll('[data-qty-minus],[data-qty-plus]').forEach((button) => button.addEventListener('click', () => {
            const input = button.parentElement.querySelector('[data-product-price]');
            const direction = button.hasAttribute('data-qty-plus') ? 1 : -1;
            input.value = String(Math.max(Number(input.min), Math.min(Number(input.max), Number(input.value) + direction)));
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }));
        if (method) method.addEventListener('change', updateCheckout);
        checkout.addEventListener('submit', async (event) => {
            const tokenInput = document.getElementById('card-token');
            if (!method || method.value !== 'cartao' || !tokenInput || tokenInput.value) return;
            event.preventDefault();
            const expiry = document.getElementById('card-expiry').value.replace(/\D/g, '');
            const number = document.getElementById('card-number').value.replace(/\D/g, '');
            const holderName = document.getElementById('card-holder').value.trim();
            const cvv = document.getElementById('card-cvv').value.replace(/\D/g, '');
            const expMonth = Number(expiry.slice(0, 2));
            const expYear = expiry.length === 6 ? Number(expiry.slice(2, 6)) : Number(`20${expiry.slice(2, 4)}`);
            const now = new Date();
            const luhnValid = number.length >= 13 && number.length <= 19 && [...number].reverse().reduce((sum, digit, index) => {
                let value = Number(digit) * (index % 2 ? 2 : 1);
                if (value > 9) value -= 9;
                return sum + value;
            }, 0) % 10 === 0;
            if (!luhnValid) { window.alert('Confira o número do cartão.'); return; }
            if (holderName.length < 3) { window.alert('Informe o nome impresso no cartão.'); return; }
            if (![4, 6].includes(expiry.length) || expMonth < 1 || expMonth > 12 || expYear < now.getFullYear() || (expYear === now.getFullYear() && expMonth < now.getMonth() + 1)) { window.alert('Informe uma validade futura no formato MM/AA ou MM/AAAA.'); return; }
            if (!/^\d{3,4}$/.test(cvv)) { window.alert('Confira o código de segurança do cartão.'); return; }
            const payload = { type: 'card', card: { number, holder_name: holderName, exp_month: expMonth, exp_year: expYear, cvv } };
            payButton.disabled = true;
            payButton.textContent = 'Protegendo cartão...';
            try {
                const response = await fetch(`https://api.pagar.me/core/v5/tokens?appId=${encodeURIComponent(checkout.dataset.pagarmeKey)}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
                const data = await response.json();
                if (!response.ok || !data.id) {
                    const details = [];
                    const collect = (value) => {
                        if (typeof value === 'string' && value.trim()) details.push(value.trim());
                        else if (value && typeof value === 'object') Object.values(value).forEach(collect);
                    };
                    collect(data.errors);
                    throw new Error(details.join(' ') || data.message || 'Confira os dados do cartão.');
                }
                tokenInput.value = data.id;
                checkout.submit();
            } catch (error) {
                window.alert(error.message);
                payButton.disabled = false;
                payButton.textContent = 'Finalizar pagamento';
            }
        });
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
