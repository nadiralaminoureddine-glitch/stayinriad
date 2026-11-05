// Page d'import Booking : favori à glisser + import automatique des données envoyées par le favori.
document.addEventListener('DOMContentLoaded', function () {
  var a = document.getElementById('bmLink'), help = document.getElementById('bmHelp');
  if (a && help) a.addEventListener('click', function (e) { e.preventDefault(); help.hidden = false; help.scrollIntoView({ block: 'nearest' }); });
  var copy = document.getElementById('bmCopy');
  if (copy && a) copy.addEventListener('click', function () {
    var code = a.getAttribute('href');
    var done = function () { copy.textContent = '✅ Code copié'; };
    if (navigator.clipboard) navigator.clipboard.writeText(code).then(done, function () { window.prompt('Copiez ce code :', code); });
    else window.prompt('Copiez ce code :', code);
  });

  // Données envoyées par le favori : #sir=<base64>
  var m = location.hash.match(/^#sir=(.+)$/);
  var form = document.getElementById('impForm'), data = document.getElementById('impData'), sel = document.getElementById('impRiad'), status = document.getElementById('impStatus');
  if (!m || !form || !data || !sel) return;
  history.replaceState(null, '', location.pathname + location.search); // pas de double import en rechargeant la page
  var json, d;
  try { json = decodeURIComponent(escape(atob(m[1]))); d = JSON.parse(json); } catch (e) { if (status) { status.hidden = false; status.className = 'flash bad'; status.textContent = 'Les données du favori sont illisibles. Rechargez la page Booking et recommencez.'; } return; }
  data.value = json;
  var key = String((d.url || '').replace(/^https?:\/\/[^/]+\//, '')).replace(/\.[a-z]{2}(-[a-z]{2})?\.html$|\.html$/i, '').toLowerCase();
  var found = null;
  for (var i = 0; i < sel.options.length; i++) if (key && sel.options[i].getAttribute('data-key') === key) found = sel.options[i];
  var info = (d.name || 'Riad') + ' : ' + (d.rooms || []).length + ' chambre(s) et ' + (d.photos || []).length + ' photo(s) trouvées sur Booking.';
  if (found) {
    sel.value = found.value;
    if (status) { status.hidden = false; status.textContent = '⏳ ' + info + ' Import dans « ' + found.textContent + ' » en cours… (environ 1 minute pour les photos)'; }
    form.querySelector('button').disabled = true;
    form.submit();
  } else if (status) {
    status.hidden = false; status.className = 'flash warn';
    status.textContent = info + ' Choisissez le riad à remplir, puis cliquez sur « Importer dans la fiche ».';
    sel.focus();
  }
});
