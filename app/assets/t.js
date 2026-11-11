/* StayInRiad — compteur de visites maison (sans cookie, sans service externe).
   Ne compte une visite que si la page est vraiment regardée (geste de l'utilisateur ou 4 s visible),
   ce qui écarte la plupart des robots. Les appareils de l'équipe sont exclus (data-nt / connexion admin). */
(function () {
  try {
    var d = document, n = navigator, s = d.currentScript;
    if (s && s.getAttribute('data-nt')) { try { localStorage.setItem('sir_nt', '1'); } catch (e) {} return; }
    try { if (localStorage.getItem('sir_nt')) return; } catch (e) {}
    if (n.webdriver || /bot|crawl|spider|slurp|headless|lighthouse|preview|facebookexternalhit|scan|python|curl|wget/i.test(n.userAgent)) return;
    var url = '/app/t.php', shown = false;
    // si la page d'accueil a redirigé vers la langue du visiteur, on récupère le vrai site d'origine
    var ref = d.referrer || '', qs = location.search;
    try { var o = JSON.parse(sessionStorage.getItem('sir_ref') || 'null'); if (o) { ref = o[0] || ''; qs = o[1] || qs; sessionStorage.removeItem('sir_ref'); } } catch (e) {}
    var utm = (qs.match(/[?&]utm_source=([^&#]{1,40})/) || [])[1] || '';
    function send(ev, extra) {
      var o = { e: ev, p: location.pathname, r: ref, u: decodeURIComponent(utm),
                l: (n.language || '').slice(0, 5), tz: '', w: screen.width || 0 };
      try { o.tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}
      if (extra) o.x = String(extra).slice(0, 120);
      var b = JSON.stringify(o);
      if (n.sendBeacon) n.sendBeacon(url, new Blob([b], { type: 'text/plain' }));
      else fetch(url, { method: 'POST', body: b, keepalive: true, credentials: 'same-origin' });
    }
    function view() {
      if (shown) return; shown = true;
      ['scroll', 'mousemove', 'touchstart', 'keydown'].forEach(function (t) { removeEventListener(t, view); });
      send('view');
    }
    ['scroll', 'mousemove', 'touchstart', 'keydown'].forEach(function (t) { addEventListener(t, view, { passive: true }); });
    setTimeout(function () { if (d.visibilityState === 'visible') view(); }, 4000);
    // clics vers l'inscription des riads, et lecture des vidéos tutoriel
    d.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('a[href]');
      if (a && /register\.php|inscrire-mon-riad|list-your-riad/.test(a.getAttribute('href'))) { view(); send('cta', a.getAttribute('href')); }
    }, true);
    d.addEventListener('play', function (e) {
      if (e.target && e.target.tagName === 'VIDEO' && !e.target._sirPlayed) { e.target._sirPlayed = 1; view(); send('video', e.target.currentSrc || ''); }
    }, true);
  } catch (e) {}
})();
