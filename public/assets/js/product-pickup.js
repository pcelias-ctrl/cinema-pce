(function () {
    const video = document.getElementById('product-qr-video');
    const start = document.getElementById('start-product-reader');
    const stop = document.getElementById('stop-product-reader');
    const status = document.getElementById('product-reader-status');
    const list = document.getElementById('pickup-items');
    const count = document.getElementById('pickup-count');
    const finish = document.getElementById('finish-pickup');
    const items = new Map();
    let stream = null;
    let detector = null;
    let scanning = false;

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>'"]/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
    }

    function tokenFrom(value) {
        const text = String(value || '').trim();
        try { return new URL(text, window.location.href).searchParams.get('token') || text; } catch (error) { return text; }
    }
    function render() {
        list.innerHTML = '';
        items.forEach((item) => {
            const row = document.createElement('article');
            const image = item.has_image ? `<img src="index.php?route=product_image&id=${Number(item.product_id)}" alt="">` : '<span class="pickup-image-placeholder">+</span>';
            row.innerHTML = `${image}<div><strong>${escapeHtml(item.product_name)}</strong><span>${escapeHtml(item.category_name)} | Venda ${escapeHtml(item.sale_code)}</span></div><button type="button" title="Remover">×</button><input type="hidden" name="item_ids[]" value="${Number(item.id)}">`;
            row.querySelector('button').onclick = () => { items.delete(String(item.id)); render(); };
            list.appendChild(row);
        });
        if (!items.size) list.innerHTML = '<p class="muted empty">Nenhum produto lido.</p>';
        count.textContent = String(items.size);
        finish.disabled = items.size === 0;
    }
    async function add(value) {
        const token = tokenFrom(value);
        if (!token) return;
        status.textContent = 'Consultando produto...';
        try {
            const response = await fetch(`index.php?route=product_pickup_lookup&token=${encodeURIComponent(token)}`);
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || 'Produto não encontrado.');
            if (data.item.status !== 'pendente') throw new Error(`Produto já ${data.item.status}.`);
            if (items.has(String(data.item.id))) throw new Error('Este produto já está na lista.');
            items.set(String(data.item.id), data.item);
            render();
            status.textContent = `${data.item.product_name} adicionado.`;
        } catch (error) { status.textContent = error.message; }
    }
    function stopCamera() {
        scanning = false;
        if (stream) stream.getTracks().forEach((track) => track.stop());
        stream = null; video.srcObject = null; start.disabled = false; stop.disabled = true;
    }
    async function loop() {
        if (!scanning) return;
        try {
            const codes = await detector.detect(video);
            if (codes.length) { scanning = false; await add(codes[0].rawValue); scanning = true; window.setTimeout(loop, 900); return; }
        } catch (error) { status.textContent = 'Não foi possível ler este quadro.'; }
        window.requestAnimationFrame(loop);
    }
    start.onclick = async () => {
        if (!('BarcodeDetector' in window)) { status.textContent = 'Navegador sem leitura automática. Use o campo manual.'; return; }
        try { detector = new BarcodeDetector({formats:['qr_code']}); stream = await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}},audio:false}); video.srcObject=stream; await video.play(); scanning=true; start.disabled=true; stop.disabled=false; status.textContent='Câmera aberta.'; loop(); } catch(error){ status.textContent='Não foi possível abrir a câmera.'; stopCamera(); }
    };
    stop.onclick = stopCamera;
    document.getElementById('manual-product-form').onsubmit = (event) => { event.preventDefault(); const input=document.getElementById('manual-product-token'); add(input.value); input.value=''; };
    render();
})();
