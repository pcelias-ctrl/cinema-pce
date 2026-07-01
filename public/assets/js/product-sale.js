(function () {
    const form = document.getElementById('product-sale-form');
    if (!form) return;

    const cards = [...form.querySelectorAll('.product-pick-card')];
    const countLabel = document.getElementById('product-sale-count');
    const totalLabel = document.getElementById('product-sale-total');
    const method = document.getElementById('product-payment-method');
    const amountRow = document.getElementById('product-amount-row');
    const amountPaid = document.getElementById('product-amount-paid');
    const changeRow = document.getElementById('product-change-row');
    const changeLabel = document.getElementById('product-sale-change');
    const finish = document.getElementById('finish-product-sale');

    function parseMoney(value) {
        value = String(value || '').trim();
        if (value.includes(',')) value = value.replace(/\./g, '').replace(',', '.');
        return Math.max(0, parseFloat(value) || 0);
    }

    function money(value) {
        return value.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    function totals() {
        return cards.reduce((result, card) => {
            const quantity = parseInt(card.querySelector('.quantity-stepper input').value, 10) || 0;
            result.quantity += quantity;
            result.total += quantity * (parseFloat(card.dataset.price) || 0);
            return result;
        }, { quantity: 0, total: 0 });
    }

    function update() {
        const current = totals();
        const isCash = method.value === 'dinheiro';
        const paid = isCash ? parseMoney(amountPaid.value) : current.total;
        countLabel.textContent = String(current.quantity);
        totalLabel.textContent = money(current.total);
        amountRow.hidden = !isCash;
        changeRow.hidden = !isCash;
        changeLabel.textContent = money(Math.max(0, paid - current.total));
        finish.disabled = current.quantity === 0 || (isCash && paid < current.total);
    }

    form.querySelectorAll('.product-sale-categories button').forEach((button) => {
        button.addEventListener('click', () => {
            form.querySelectorAll('.product-sale-categories button').forEach((item) => item.classList.toggle('active', item === button));
            cards.forEach((card) => {
                card.hidden = button.dataset.category !== 'all' && card.dataset.category !== button.dataset.category;
            });
        });
    });

    form.querySelectorAll('.quantity-stepper button').forEach((button) => {
        button.addEventListener('click', () => {
            const card = button.closest('.product-pick-card');
            const input = card.querySelector('.quantity-stepper input');
            const maximum = parseInt(card.dataset.max, 10) || 50;
            input.value = String(Math.min(maximum, Math.max(0, (parseInt(input.value, 10) || 0) + parseInt(button.dataset.delta, 10))));
            card.classList.toggle('selected', parseInt(input.value, 10) > 0);
            update();
        });
    });

    method.addEventListener('change', update);
    amountPaid.addEventListener('input', update);
    form.addEventListener('submit', (event) => {
        if (finish.disabled) {
            event.preventDefault();
            return;
        }
        const popup = window.open('', 'cinema_product_print_pending', 'popup=yes,width=420,height=720');
        if (!popup) return;
        popup.document.open();
        popup.document.write('<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><title>Preparando impressão</title><style>body{margin:0;display:grid;min-height:100vh;place-items:center;font:14px Arial,sans-serif;background:#fff;color:#111}main{text-align:center}strong{display:block;font-size:18px}</style></head><body><main><strong>Preparando recibos</strong><p>Aguarde a finalização da venda.</p></main></body></html>');
        popup.document.close();
        popup.focus();
    });
    update();
})();
