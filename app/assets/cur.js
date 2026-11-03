// StayInRiad — première visite : choisit la devise selon le pays (fuseau horaire, puis langue du navigateur).
// Maroc → DH, Amériques → $, Europe et ailleurs → €. Le visiteur peut toujours changer en haut de la page.
(function () {
  try {
    if (/(^|;\s*)sir_cur=/.test(document.cookie)) return;
    var tz = (Intl.DateTimeFormat().resolvedOptions().timeZone || ''), lang = (navigator.language || '').toLowerCase();
    var cur = 'EUR';
    if (tz === 'Africa/Casablanca' || tz === 'Africa/El_Aaiun' || /-ma$/.test(lang)) cur = 'MAD';
    else if (/^America\//.test(tz) || lang === 'en-us') cur = 'USD';
    var u = new URL(location.href); u.searchParams.set('cur', cur);
    if (cur === 'EUR') { fetch(u.toString(), { credentials: 'same-origin' }); return; } // mémorise sans recharger
    location.replace(u.toString());
  } catch (e) {}
})();
