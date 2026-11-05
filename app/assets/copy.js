// Boutons « Copier » (StayInRiad)
document.addEventListener('click', function (ev) {
  var b = ev.target.closest('[data-copy]'); if (!b) return;
  var i = document.getElementById(b.getAttribute('data-copy')); if (!i) return;
  var done = function () { var t = b.textContent; b.textContent = document.documentElement.lang === 'en' ? 'Copied ✓' : 'Copié ✓'; setTimeout(function () { b.textContent = t; }, 1600); };
  if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(i.value).then(done, function () { i.select(); document.execCommand('copy'); done(); });
  else { i.select(); document.execCommand('copy'); done(); }
});
document.addEventListener('focusin', function (ev) { if (ev.target.classList && ev.target.classList.contains('copy')) ev.target.select(); });
