// Browsertest voor assets/kaart.js (headless Chrome via puppeteer-core).
// Draaien: NODE_PATH=/pad/naar/node_modules node tests/kaart_test.js  (CHROME=/usr/bin/google-chrome)
// Leaflet en fetch worden gestubd; de test stuurt de antwoorden van api.php zelf aan.
const path = require('path');
const fs = require('fs');
const os = require('os');
let puppeteer;
try { puppeteer = require('puppeteer-core'); } catch (e) { console.log('kaart: overgeslagen (puppeteer-core niet gevonden)'); process.exit(0); }

const kaartJs = 'file://' + path.resolve(__dirname, '../web/assets/kaart.js');
const html = `<!doctype html><html><body>
<form id="verzoek-form" data-kaart='{"lat":"f_lat","lon":"f_lon","adres":"f_adres","postcode":"f_pc","plaats":"f_plaats","land":"f_land"}' data-kaart-landen="NL,BE">
<input id="f_lat" value="52.1"><input id="f_lon" value="6.1"><input id="f_adres" value="Oudeweg 1">
<input id="f_pc" value="1111 AA"><input id="f_plaats" value="Oudstad">
<select id="f_land"><option value=""></option><option value="NL" selected>NL</option><option value="BE">BE</option></select>
<button type="button" data-kaart-open id="open">Kies</button></form>
<dialog id="kaart-dialog"><input id="kaart-zoek"><ul id="kaart-resultaten" hidden></ul><div id="kaart"></div>
<p id="kaart-status"></p><button id="kaart-overnemen" disabled>Overnemen</button></dialog>
<script>
window.pending = [];
window.fetch = function (url) {
  return new Promise(function (resolve) { window.pending.push({ url: String(url), answer: function (json) { resolve({ json: function () { return json; } }); } }); });
};
window.answer = function (match, json) {
  var i = window.pending.findIndex(function (p) { return p.url.indexOf(match) !== -1; });
  if (i === -1) { return false; }
  window.pending.splice(i, 1)[0].answer(json); return true;
};
var handlers = {};
window.L = { Icon: { Default: {} },
  map: function () { var m = { on: function (ev, fn) { handlers[ev] = fn; return m; }, setView: function () { return m; }, invalidateSize: function () {}, removeLayer: function () {} }; return m; },
  tileLayer: function () { return { addTo: function () {} }; },
  marker: function () { var mk = { addTo: function () { return mk; }, on: function () { return mk; }, setLatLng: function () {} }; return mk; } };
window.clickMap = function (lat, lng) { handlers.click({ latlng: { lat: lat, lng: lng } }); };
</script><script src="${kaartJs}"></script></body></html>`;

let fails = 0, checks = 0;
function check(ok, msg) { checks++; if (!ok) { fails++; console.log('FAIL: ' + msg); } }
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  const browser = await puppeteer.launch({ executablePath: process.env.CHROME || '/usr/bin/google-chrome', args: ['--no-sandbox', '--allow-file-access-from-files'] });
  const page = await browser.newPage();
  page.on('pageerror', (e) => { fails++; console.log('FAIL: JS-fout ' + e.message); });
  const harness = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'kaart-')), 'harness.html');
  fs.writeFileSync(harness, html);
  async function fresh() { await page.goto('file://' + harness, { waitUntil: 'load' }); await page.click('#open'); }
  async function waitFetch(match) {
    for (let i = 0; i < 60; i++) { if (await page.evaluate((m) => window.pending.some((p) => p.url.includes(m)), match)) { return true; } await sleep(100); }
    return false;
  }
  const fields = () => page.evaluate(() => ['f_lat', 'f_lon', 'f_adres', 'f_pc', 'f_plaats', 'f_land'].map((id) => document.getElementById(id).value));

  // 1. Nieuw punt zonder postcode en met land buiten de lijst: oude adresvelden worden leeggemaakt.
  await fresh();
  await page.evaluate(() => window.clickMap(48.85, 2.35));
  check(await waitFetch('geo-adres'), 'adres-opzoeking gestart');
  await page.evaluate(() => window.answer('geo-adres', { ok: true, adres: { lat: '48.85', lon: '2.35', adres: 'Rue X 5', postcode: '', plaats: 'Parijs', land: 'FR' } }));
  await sleep(50);
  await page.click('#kaart-overnemen');
  const f1 = await fields();
  check(f1[2] === 'Rue X 5' && f1[4] === 'Parijs', 'adres en plaats overgenomen');
  check(f1[3] === '', 'oude postcode leeggemaakt bij lege postcode (was: ' + f1[3] + ')');
  check(f1[5] === '', 'land buiten de lijst maakt het oude land leeg (was: ' + f1[5] + ')');

  // 2. Zoeken tijdens een lopende adres-opzoeking mag die niet ongeldig maken (aparte tellers).
  await fresh();
  await page.evaluate(() => window.clickMap(52.3, 4.9));
  check(await waitFetch('geo-adres'), 'adres-opzoeking gestart (2)');
  await page.evaluate(() => { document.getElementById('kaart-zoek').value = 'Amsterdam'; document.getElementById('kaart-zoek').dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' })); });
  check(await waitFetch('geo-zoek'), 'zoekopdracht gestart');
  await page.evaluate(() => window.answer('geo-zoek', { ok: true, resultaten: [] }));
  await page.evaluate(() => window.answer('geo-adres', { ok: true, adres: { lat: '52.3', lon: '4.9', adres: 'Nieuweweg 2', postcode: '1012 AB', plaats: 'Amsterdam', land: 'NL' } }));
  await sleep(50);
  await page.click('#kaart-overnemen');
  const f2 = await fields();
  check(f2[2] === 'Nieuweweg 2' && f2[3] === '1012 AB', 'adres van de klik overgenomen ondanks zoekopdracht ertussen (was: ' + f2[2] + ')');

  // 3. Adres niet gevonden: alleen coördinaten, bestaande adresvelden blijven staan.
  await fresh();
  await page.evaluate(() => window.clickMap(51.0, 5.0));
  check(await waitFetch('geo-adres'), 'adres-opzoeking gestart (3)');
  await page.evaluate(() => window.answer('geo-adres', { ok: false, fout: 'stuk' }));
  await sleep(50);
  await page.click('#kaart-overnemen');
  const f3 = await fields();
  check(f3[0] === '51' && f3[2] === 'Oudeweg 1' && f3[5] === 'NL', 'mislukte opzoeking: alleen coördinaten, adres blijft');

  await browser.close();
  console.log(fails ? `kaart: ${fails} van ${checks} checks mislukt` : `kaart: ${checks} checks OK`);
  process.exit(fails ? 1 : 0);
})();
