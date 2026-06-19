(function () {
    const canvas = document.getElementById('ticket-qr');
    if (!canvas || !window.QRCode) return;

    QRCode.toCanvas(canvas, canvas.dataset.url || '', {
        width: 180,
        margin: 1,
        errorCorrectionLevel: 'M'
    });
})();
