(function () {
    const targets = document.querySelectorAll('[data-ticket-qr], #ticket-qr');
    if (!targets.length || !window.QRCode) return;

    targets.forEach((target) => {
        new QRCode(target, {
            text: target.dataset.url || '',
            width: 180,
            height: 180,
            colorDark: '#171717',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });
    });
})();
