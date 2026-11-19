// Link másolása vágólapra
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('[data-copy]');
  if (!btn) return;
  const text = btn.dataset.copy;
  try {
    await navigator.clipboard.writeText(text);
  } catch {
    const input = btn.parentElement.querySelector('input');
    if (input) { input.select(); document.execCommand('copy'); }
  }
  const old = btn.textContent;
  btn.textContent = 'Másolva ✓';
  setTimeout(() => { btn.textContent = old; }, 1500);
});
