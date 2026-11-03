// Bouton « Imprimer / PDF »
document.addEventListener('click', function (ev) { var b = ev.target.closest('[data-print]'); if (!b) return; ev.preventDefault(); window.print(); });
