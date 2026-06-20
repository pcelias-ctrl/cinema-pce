(function () {
    const target = document.getElementById('ticket-qr');
    if (!target || !window.QRCode) return;

    new QRCode(target, {
        text: target.dataset.url || '',
        width: 180,
        height: 180,
        colorDark: '#171717',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
    });
})();
