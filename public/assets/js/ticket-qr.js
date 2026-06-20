(function () {
    const targets = document.querySelectorAll('[data-ticket-qr], #ticket-qr');
    if (!targets.length || !window.QRCode) return;

    const conversions = [];
    targets.forEach((target) => {
        new QRCode(target, {
            text: target.dataset.url || '',
            width: 180,
            height: 180,
            colorDark: '#171717',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });

        conversions.push(new Promise((resolve) => {
            window.setTimeout(() => {
                const canvas = target.querySelector('canvas');
                if (canvas) {
                    const image = document.createElement('img');
                    image.src = canvas.toDataURL('image/png');
                    image.alt = 'QR Code do ingresso';
                    target.replaceChildren(image);
                }
                resolve();
            }, 60);
        }));
    });

    window.ticketQrReady = Promise.all(conversions);
})();
