// Fiche du riad : choisir l'emplacement sur une carte (OpenStreetMap). Remplit les champs latitude / longitude.
var EN = document.documentElement.lang === 'en';
(function () {
  var box = document.getElementById('mapPick');
  if (!box || !window.L) return;
  var lat = document.querySelector('input[name=lat]'), lng = document.querySelector('input[name=lng]');
  var city = document.querySelector('select[name=city]');
  var CENTERS = {
    'Marrakech': [31.6295, -7.9811],
    'Fès': [34.0331, -5.0003],
    'Essaouira': [31.5085, -9.7595],
    'Chefchaouen': [35.1688, -5.2684],
    'Meknès': [33.8935, -5.5547],
    'Rabat': [34.0209, -6.8416],
    'Tanger': [35.7595, -5.834],
    'Tétouan': [35.5785, -5.3684],
    'Asilah': [35.465, -6.034],
    'Casablanca': [33.5731, -7.5898],
    'El Jadida': [33.2316, -8.5007],
    'Oualidia': [32.733, -9.03],
    'Safi': [32.2994, -9.2372],
    'Agadir': [30.4278, -9.5981],
    'Taghazout': [30.544, -9.709],
    'Taroudant': [30.4703, -8.877],
    'Tiznit': [29.6974, -9.7316],
    'Mirleft': [29.583, -10.037],
    'Imlil': [31.137, -7.919],
    'Ouarzazate': [30.9335, -6.937],
    'Aït Ben Haddou': [31.047, -7.13],
    'Skoura': [31.06, -6.556],
    'Tinghir': [31.5147, -5.5328],
    'Merzouga': [31.0802, -4.0134],
    'Rissani': [31.28, -4.26],
    'Zagora': [30.3324, -5.8384],
    'Ifrane': [33.5333, -5.1167],
    'Moulay Idriss': [34.055, -5.522],
    'Larache': [35.1932, -6.1557],
    'Al Hoceïma': [35.2517, -3.9372],
    'Dakhla': [23.6848, -15.958]
  };
  var num = function (v) { v = parseFloat(String(v || '').replace(',', '.')); return isFinite(v) ? v : null; };
  var inMorocco = function (a, b) { return a !== null && b !== null && a > 20.5 && a < 36.5 && b > -17.5 && b < -0.9; };
  var start = function () {
    var a = num(lat.value), b = num(lng.value);
    if (inMorocco(a, b)) return [[a, b], 17];
    var c = city && CENTERS[city.value];
    return c ? [c, 14] : [[31.79, -7.09], 5];
  };
  var s = start();
  var map = L.map(box, { scrollWheelZoom: false, tap: true }).setView(s[0], s[1]);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>'
  }).addTo(map);
  map.attributionControl.setPrefix('<a href="https://leafletjs.com" target="_blank" rel="noopener">Leaflet</a>');
  var icon = L.icon({ iconUrl: 'assets/leaflet/images/marker-icon.png', iconRetinaUrl: 'assets/leaflet/images/marker-icon-2x.png',
    shadowUrl: 'assets/leaflet/images/marker-shadow.png', iconSize: [25, 41], iconAnchor: [12, 41], shadowSize: [41, 41] });
  var marker = null;
  var status = document.getElementById('mapStatus');
  var put = function (ll, center) {
    var a = Math.round(ll.lat * 1e6) / 1e6, b = Math.round(ll.lng * 1e6) / 1e6;
    if (!marker) {
      marker = L.marker([a, b], { draggable: true, icon: icon }).addTo(map);
      marker.on('dragend', function () { put(marker.getLatLng(), false); });
    } else marker.setLatLng([a, b]);
    lat.value = a; lng.value = b;
    if (center) map.setView([a, b], Math.max(map.getZoom(), 17));
    if (status) status.textContent = inMorocco(a, b) ? (EN ? '✅ Location saved. You can move the pin to adjust it.' : '✅ Emplacement enregistré. Vous pouvez déplacer l\'épingle pour l\'ajuster.') : (EN ? '⚠️ This point is outside Morocco.' : '⚠️ Ce point est hors du Maroc.');
  };
  if (inMorocco(num(lat.value), num(lng.value))) put(L.latLng(num(lat.value), num(lng.value)), false);
  map.on('click', function (e) { put(e.latlng, false); });
  var sync = function () { var a = num(lat.value), b = num(lng.value); if (inMorocco(a, b)) put(L.latLng(a, b), true); };
  lat.addEventListener('change', sync); lng.addEventListener('change', sync);
  if (city) city.addEventListener('change', function () { if (!marker && CENTERS[city.value]) map.setView(CENTERS[city.value], 14); });
  var me = document.getElementById('mapMe');
  if (me) {
    if (!navigator.geolocation) me.style.display = 'none';
    me.addEventListener('click', function () {
      if (status) status.textContent = 'Recherche de votre position…';
      navigator.geolocation.getCurrentPosition(function (p) { put(L.latLng(p.coords.latitude, p.coords.longitude), true); },
        function () { if (status) status.textContent = EN ? 'Location not found: tap the map where your riad is.' : 'Position introuvable : touchez la carte à l\'endroit de votre riad.'; },
        { enableHighAccuracy: true, timeout: 10000 });
    });
  }
  setTimeout(function () { map.invalidateSize(); }, 200);
})();
