// Page d'import Booking : le bouton favori se glisse, il ne se clique pas ici.
document.addEventListener('DOMContentLoaded', function () {
  var a = document.getElementById('bmLink'), help = document.getElementById('bmHelp');
  if (!a || !help) return;
  a.addEventListener('click', function (e) {
    e.preventDefault();
    help.hidden = false;
    help.scrollIntoView({ block: 'nearest' });
  });
  var copy = document.getElementById('bmCopy');
  if (copy) copy.addEventListener('click', function () {
    var code = a.getAttribute('href');
    var done = function () { copy.textContent = '✅ Code copié'; };
    if (navigator.clipboard) navigator.clipboard.writeText(code).then(done, function () { window.prompt('Copiez ce code :', code); });
    else window.prompt('Copiez ce code :', code);
  });
});
