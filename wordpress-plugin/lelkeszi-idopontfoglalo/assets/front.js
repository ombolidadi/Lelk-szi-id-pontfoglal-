// Másolás gomb és a 2FA QR-kód kirajzolása (a QR-kód a böngészőben készül, külső szolgáltatás nélkül).
(function () {
  document.addEventListener('click', async function (e) {
    var btn = e.target.closest('[data-copy]');
    if (!btn) return;
    var text = btn.getAttribute('data-copy');
    try { await navigator.clipboard.writeText(text); } catch (err) {
      var input = btn.parentElement.querySelector('input');
      if (input) { input.select(); document.execCommand('copy'); }
    }
    var old = btn.textContent;
    btn.textContent = 'Másolva ✓';
    setTimeout(function () { btn.textContent = old; }, 1500);
  });
  var qrEl = document.getElementById('lif-qr');
  if (qrEl && window.qrcode) {
    var qr = window.qrcode(0, 'M');
    qr.addData(qrEl.getAttribute('data-uri'));
    qr.make();
    qrEl.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 2, scalable: true });
    var svg = qrEl.querySelector('svg');
    if (svg) svg.setAttribute('aria-label', 'QR-kód a hitelesítő alkalmazáshoz');
  }
})();
