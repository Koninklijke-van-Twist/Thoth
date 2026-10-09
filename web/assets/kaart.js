/* Kaartkiezer: adres/locatie kiezen op OpenStreetMap (Leaflet). Zoeken en omgekeerd geocoderen
 * lopen via api.php (server-side Nominatim met User-Agent, cache en max 1 req/s); hier ook
 * debounce en minstens 1 s tussen verzoeken. Overnemen vult de formuliervelden en triggert autosave. */
(function () {
  'use strict';

  var form = document.getElementById('verzoek-form');
  var dialog = document.getElementById('kaart-dialog');
  if (!form || !dialog || !form.dataset.kaart || typeof L === 'undefined') { return; }

  var roles = JSON.parse(form.dataset.kaart);           // rol -> input-id
  var allowed = (form.dataset.kaartLanden || '').split(',').filter(Boolean);
  var searchEl = document.getElementById('kaart-zoek');
  var resultsEl = document.getElementById('kaart-resultaten');
  var statusEl = document.getElementById('kaart-status');
  var applyBtn = document.getElementById('kaart-overnemen');
  var map = null, marker = null, picked = null;
  var lastRequest = 0, searchTimer = null, reverseTimer = null;
  // Aparte tellers: een zoekopdracht mag een lopende adres-opzoeking niet ongeldig maken (en omgekeerd).
  var searchSeq = 0, reverseSeq = 0;

  function el(role) { return roles[role] ? document.getElementById(roles[role]) : null; }
  function val(role) { var e = el(role); return e ? e.value.trim() : ''; }
  function setStatus(text) { statusEl.textContent = text; }
  function coord(n) { return (Math.round(n * 1e6) / 1e6).toString(); }

  // Max 1 verzoek per seconde vanuit deze pagina (de server bewaakt het ook globaal).
  function geo(params) {
    var wait = Math.max(0, lastRequest + 1000 - Date.now());
    return new Promise(function (resolve) { setTimeout(resolve, wait); }).then(function () {
      lastRequest = Date.now();
      return fetch('api.php?' + new URLSearchParams(params).toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
    }).then(function (r) { return r.json(); }).then(function (json) {
      if (!json.ok) { throw new Error(json.fout || 'Adreszoeker gaf een fout'); }
      return json;
    });
  }

  function describe(p) {
    var parts = [p.adres, [p.postcode, p.plaats].filter(Boolean).join(' '), p.land].filter(Boolean);
    var text = (parts.length ? parts.join(', ') : 'Geen adres gevonden') + ' · ' + p.lat + ', ' + p.lon;
    if (roles.land && p.land && allowed.indexOf(p.land) === -1) {
      text += ' · Let op: land ' + p.land + ' staat niet in de lijst (' + allowed.join('/') + '); het land wordt leeggemaakt.';
    }
    return text;
  }

  function placeMarker(lat, lon) {
    if (!marker) {
      marker = L.marker([lat, lon], { draggable: true }).addTo(map);
      marker.on('dragend', function () { var ll = marker.getLatLng(); pickPoint(ll.lat, ll.lng); });
    } else {
      marker.setLatLng([lat, lon]);
    }
  }

  function pickPoint(lat, lon) {
    placeMarker(lat, lon);
    // adresBekend=false: alleen coördinaten overnemen, bestaande adresvelden laten staan.
    picked = { lat: coord(lat), lon: coord(lon), adres: '', postcode: '', plaats: '', land: '', adresBekend: false };
    applyBtn.disabled = false;
    setStatus('Adres opzoeken… · ' + picked.lat + ', ' + picked.lon);
    clearTimeout(reverseTimer);
    var mine = ++reverseSeq;
    reverseTimer = setTimeout(function () {
      geo({ actie: 'geo-adres', lat: picked.lat, lon: picked.lon }).then(function (json) {
        if (mine !== reverseSeq) { return; }
        if (json.adres) { picked = json.adres; picked.adresBekend = true; }
        setStatus(describe(picked));
      }).catch(function (e) { if (mine === reverseSeq) { setStatus('Adres niet gevonden (' + e.message + '); alleen de coördinaten worden overgenomen.'); } });
    }, 500);
  }

  function pickResult(r) {
    reverseSeq++; // lopende adres-opzoeking van een eerdere klik negeren
    clearTimeout(reverseTimer);
    picked = r;
    picked.adresBekend = true;
    placeMarker(parseFloat(r.lat), parseFloat(r.lon));
    map.setView([parseFloat(r.lat), parseFloat(r.lon)], 17);
    applyBtn.disabled = false;
    resultsEl.hidden = true;
    setStatus(describe(r));
  }

  function search() {
    var q = searchEl.value.trim();
    if (q.length < 3) { resultsEl.hidden = true; return; }
    var mine = ++searchSeq;
    geo({ actie: 'geo-zoek', q: q }).then(function (json) {
      if (mine !== searchSeq) { return; }
      resultsEl.innerHTML = '';
      if (!json.resultaten.length) {
        resultsEl.innerHTML = '<li class="combo-empty">Geen resultaten</li>';
      }
      json.resultaten.forEach(function (r) {
        var li = document.createElement('li');
        li.textContent = r.label;
        li.setAttribute('role', 'option');
        li.addEventListener('click', function () { pickResult(r); });
        resultsEl.appendChild(li);
      });
      resultsEl.hidden = false;
    }).catch(function (e) { if (mine === searchSeq) { setStatus('Zoeken mislukt: ' + e.message); } });
  }

  function setField(role, value) {
    var e = el(role);
    if (!e) { return; }
    value = value === undefined || value === null ? '' : String(value);
    if (e.tagName === 'SELECT' && value !== '') {
      var ok = Array.prototype.some.call(e.options, function (o) { return o.value === value; });
      if (!ok) { return; }
    }
    e.value = value;
    e.dispatchEvent(new Event('input', { bubbles: true }));
    e.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function open() {
    dialog.showModal();
    if (!map) {
      L.Icon.Default.imagePath = 'assets/vendor/leaflet/images/';
      map = L.map('kaart');
      L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>-bijdragers'
      }).addTo(map);
      map.on('click', function (e) { pickPoint(e.latlng.lat, e.latlng.lng); });
    }
    var lat = parseFloat(val('lat')), lon = parseFloat(val('lon'));
    picked = null;
    applyBtn.disabled = true;
    if (isFinite(lat) && isFinite(lon) && Math.abs(lat) <= 90 && Math.abs(lon) <= 180) {
      map.setView([lat, lon], 16);
      placeMarker(lat, lon);
      setStatus('Huidige coördinaten: ' + lat + ', ' + lon + '. Klik of versleep de marker om te wijzigen.');
    } else {
      map.setView([52.2, 5.3], 7);
      if (marker) { map.removeLayer(marker); marker = null; }
      setStatus('Zoek een adres, of klik op de kaart. Je kunt de marker verslepen.');
    }
    var q = [val('adres'), val('postcode'), val('plaats')].filter(Boolean).join(' ');
    searchEl.value = q;
    setTimeout(function () { map.invalidateSize(); searchEl.focus(); }, 50);
  }

  form.querySelectorAll('[data-kaart-open]').forEach(function (b) { b.addEventListener('click', open); });
  form.querySelectorAll('[data-kaart-wis]').forEach(function (b) {
    b.addEventListener('click', function () {
      ['lat', 'lon'].forEach(function (role) {
        var e = el(role);
        if (e) { e.value = ''; e.dispatchEvent(new Event('input', { bubbles: true })); }
      });
    });
  });
  dialog.querySelectorAll('[data-kaart-sluit]').forEach(function (b) { b.addEventListener('click', function () { dialog.close(); }); });
  searchEl.addEventListener('input', function () { clearTimeout(searchTimer); searchTimer = setTimeout(search, 600); });
  searchEl.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); clearTimeout(searchTimer); search(); }
  });
  applyBtn.addEventListener('click', function () {
    if (!picked) { return; }
    setField('lat', picked.lat);
    setField('lon', picked.lon);
    if (picked.adresBekend) {
      // Ook lege waarden schrijven, anders blijft een oud adres naast nieuwe coördinaten staan.
      setField('adres', picked.adres);
      setField('postcode', picked.postcode);
      setField('plaats', picked.plaats);
      setField('land', picked.land && allowed.indexOf(picked.land) !== -1 ? picked.land : '');
    }
    dialog.close();
  });
})();
