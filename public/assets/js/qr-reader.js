(function () {
    const video = document.getElementById('qr-video');
    const canvas = document.getElementById('qr-canvas');
    const startButton = document.getElementById('start-qr-reader');
    const stopButton = document.getElementById('stop-qr-reader');
    const status = document.getElementById('qr-reader-status');
    let stream = null;
    let detector = null;
    let stopped = true;

    function setStatus(message) {
        status.textContent = message;
    }

    function validationUrl(value) {
        const text = String(value || '').trim();
        if (text.includes('route=ticket_validate')) return text;
        return `index.php?route=ticket_validate&token=${encodeURIComponent(text)}`;
    }

    function stop() {
        stopped = true;
        if (stream) {
            stream.getTracks().forEach((track) => track.stop());
            stream = null;
        }
        video.srcObject = null;
        startButton.disabled = false;
        stopButton.disabled = true;
    }

    async function scanLoop() {
        if (stopped || !detector) return;
        try {
            const codes = await detector.detect(video);
            if (codes.length > 0) {
                stop();
                window.location.href = validationUrl(codes[0].rawValue);
                return;
            }
        } catch (error) {
            setStatus('Não foi possível ler este quadro. Tente aproximar o QR Code.');
        }
        window.requestAnimationFrame(scanLoop);
    }

    startButton.addEventListener('click', async () => {
        if (!('BarcodeDetector' in window)) {
            setStatus('Este navegador não suporta leitura automática. Use o campo manual abaixo.');
            return;
        }

        try {
            detector = new BarcodeDetector({ formats: ['qr_code'] });
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: 'environment' } },
                audio: false
            });
            stopped = false;
            video.srcObject = stream;
            await video.play();
            startButton.disabled = true;
            stopButton.disabled = false;
            setStatus('Câmera aberta. Aponte para o QR Code do ingresso.');
            scanLoop();
        } catch (error) {
            setStatus('Não foi possível abrir a câmera. Verifique a permissão do navegador.');
            stop();
        }
    });

    stopButton.addEventListener('click', stop);
})();
