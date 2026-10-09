# Thoth

sleutels.kvt.nl-app om **Servicelocaties (Main Entity)** en **Componenten** via een formulier aan te vragen, te laten goedkeuren en bij goedkeuring direct in Business Central aan te maken.

Pagina-root: `web/`. PHP 8.4, SQLite, geen externe dependencies.

## Werking

| Stap | Wie | Wat |
|---|---|---|
| Indienen | gebruiker in `$allowedUsers` | Opent een formulier (Servicelocatie of Component). Wijzigingen worden debounced (1 s) server-side als **Concept** opgeslagen, met de melding "Opgeslagen hh:mm:ss". **Inzenden** kan pas als alle verplichte velden gevuld zijn en aan de restricties is voldaan (de server controleert opnieuw). Status wordt **Ingediend**. |
| Goedkeuren | gebruiker in `$approvers` (en `$allowedUsers`) | Ziet alle ingediende verzoeken. Een goedkeurder beoordeelt **nooit zijn eigen aanvraag** (server-side, e-mail hoofdletterongevoelig; de knoppen zijn dan verborgen met uitleg). **Goedkeuren** maakt het record direct in BC aan via OData (POST); status **Goedgekeurd** en het BC-nummer wordt bewaard. Mislukt de insert, dan blijft het **Ingediend** met de BC-foutmelding zichtbaar voor de goedkeurder. **Afwijzen** kan met een optionele reden. |
| Afgewezen | aanvrager | Blijft **Afgewezen**, staat apart bovenaan *Mijn verzoeken* met de reden, en is net als een Concept te bewerken en opnieuw in te dienen. |

Statusovergangen: `Concept → Ingediend`, `Afgewezen → Ingediend`, `Ingediend → Goedgekeurd | Afgewezen`. Goedgekeurd is eindstatus. Elke overgang komt in de statushistorie (wie, wanneer, van, naar, reden).

### Schermen

- `index.php` – *Mijn verzoeken*: bedrijfskeuze, knoppen "Nieuwe servicelocatie/component aanvragen", afgewezen verzoeken bovenaan met reden, daaronder de overige verzoeken met status en BC-nummer.
- `verzoek.php?type=…` / `verzoek.php?id=…` – formulier (bewerkbaar voor de eigenaar bij Concept/Afgewezen, anders alleen-lezen), autosave, inzenden, statushistorie. Voor goedkeurders bij Ingediend: goedkeuren / afwijzen met reden, en de laatste BC-fout.
- `goedkeuren.php` – ingediende verzoeken (oudste eerst) en de 25 laatst beslisten. Alleen voor `$approvers`.
- `api.php` – JSON: `POST actie=opslaan` (autosave, CSRF) en `GET actie=zoek` (suggesties combobox/lookup).

### Rollen en rechten (server-side)

- Toegang: `$allowedUsers` via de gedeelde sleutels.kvt.nl-login (`../login/lib.php`). Lokaal (`php -S`, 127.0.0.1) ben je automatisch de eerste gebruiker uit `$allowedUsers`.
- Alleen de eigenaar bewerkt/indient zijn eigen Concept of Afgewezen verzoek.
- Alleen `$approvers` keuren goed of wijzen af. Zonder `$approvers` keurt niemand goed (fail-closed).
- Goedkeurders zien geen concepten van anderen.
- CSRF-token op alle POSTs, alle output ge-escaped.

## Config (`web/config/`, in git)

`servicelocatie-config.json` en `component-config.json`. Tims formaat:

```json
{
  "auto-increment-field": "No",
  "autoIncrementPrefix": "SL1",
  "autoIncrementNumberPadding": 5,
  "autoIncrementPrefixYear": true,
  "formFields": [
    {"name": "Label", "placeholder": "...", "invoerType": "tekst", "bc-tabel": "Webservice", "bc-kolom": "Kolom", "verplicht": true}
  ]
}
```

| Sleutel | Betekenis |
|---|---|
| `auto-increment-field` | Nummerkolom in de hoofdtabel (de `bc-tabel` van de velden). Thoth vult hem; hij hoort niet op het formulier. |
| `autoIncrementPrefix` | Vaste prefix, bv. `SL1`. |
| `autoIncrementNumberPadding` | Aantal cijfers van het volgnummer (1–12). |
| `autoIncrementPrefixYear` | `true` = 2-cijferig jaartal na de prefix. Nummer 12 in 2026: `SL1` + `26` + `00012` = `SL12600012`. |
| `autoIncrementStrategie` | *Optioneel.* `eerste-vrije` (standaard) of `max+1`. |
| `formFields[].invoerType` | `tekst`, `nummer`, `dropdown`, `combobox`, `lookup`, `date`, `time`, `datetime`. |
| `formFields[].bc-tabel` / `bc-kolom` | OData-webservice en kolom. **v1: één `bc-tabel` per formulier**; een config met meerdere tabellen wordt geweigerd. |
| `formFields[].verplicht` | Moet gevuld zijn om in te zenden. |

Uitbreidingen (optioneel, niet in Tims formaat):

- `"opties": ["A", "B"]` of `[{"waarde": "Open", "label": "Open", "aliassen": ["Geopend"]}]` – vaste lijst voor `dropdown`/`combobox`. Aliassen vangen Nederlandse (Mímir) en Engelse (OData) enum-captions; naar BC gaat altijd `waarde`.
- `"optiesBron": {"bc-tabel": "…", "waarde-kolom": "No", "label-kolommen": ["Name", "City"], "zoek-kolommen": ["No", "Name", "Address", "City"], "filter": "OData-filter"}` – opties uit BC via Mímir (cache 24 uur). `label-kolom` (enkelvoud) mag ook. Zonder `zoek-kolommen` wordt op waarde + labels gezocht.
- `combobox` = vrije tekst met suggesties.
- `lookup` (of `combobox` met `"strikt": true`) = **strikte** keuze: je typt op nummer/naam/adres/…, kiest een bestaande rij uit de suggesties, en alleen die waarde wordt opgeslagen. Bij inzenden (Mímir max 10 min oud) en goedkeuren (vers, max_age 0) controleert de server dat de waarde in de bron bestaat. Gebruikt in Component voor de Servicelocatie.
- `"restricties": [{"regel": "naam", …}]` – hook voor v2. Regels komen in `thoth_field_rule_registry()` (`web/lib/validation.php`); regels per invoerType in `thoth_input_type_rules()`. In v1 is het veldregister leeg; een onbekende regel geeft een configfout.
- `"_voorbeeld": "…"` – markeert een voorbeeldconfig (gele melding op het formulier).

De config wordt bij het laden gevalideerd; fouten verschijnen als duidelijke lijst op het formulier.

- `"maxLengte": 50` (per veld) – maximaal aantal tekens, gelijk aan de BC-veldlengte. De server controleert het bij inzenden en goedkeuren; het formulier zet `maxlength`.
- `"invoerType": "automatisch"` + `"afgeleidVan": {"veld": "Main_Entity", "bc-tabel": "LVS_MainEntityCard", "sleutel-kolom": "No", "kolom": "KVT_Latitude_…"}` – niet invulbaar. Bij **goedkeuren** haalt de server de waarde vers (max_age 0) uit het BC-record waarvan `sleutel-kolom` gelijk is aan de waarde van formulierveld `veld`. Zonder `bc-tabel` (alleen `{"veld": "…"}`) wordt de waarde van dat formulierveld gekopieerd (afgekapt op `maxLengte`). Een verplicht automatisch veld dat leeg blijft, laat goedkeuren falen met een duidelijke melding.
- `optiesBron."afhankelijkVan": {"veld": "Manufacturer_Code", "kolom": "Manufacturer_Code"}` – suggesties alleen uit rijen waarvan `kolom` gelijk is aan de huidige waarde van formulierveld `veld` (leeg = alles). Dubbele waarden in de bron worden één keer getoond.
- `"kaartKiezer": {"lat": "…", "lon": "…", "adres"?, "postcode"?, "plaats"?, "land"?}` (hoogste niveau) – knop **Kies op kaart** met een OpenStreetMap-modal (Leaflet 1.9.4, lokaal in `web/assets/vendor/leaflet/`, BSD-2). Je kunt een adres zoeken, op de kaart klikken of de marker slepen; reverse-geocoding vult daarna de genoemde velden (bc-kolommen). Het land wordt alleen ingevuld als het een toegestane optie van het dropdownveld is. Alles blijft aanpasbaar. lat/lon worden gecontroleerd als decimale graden met een punt.
- `afgeleidVan."extraSleutels": {"Manufacturer_Code": "Manufacturer_Code"}` (samengestelde sleutel: kolom in de bron → formulierveld) en `"terugvalOpSleutel": true` (lege bronkolom → de sleutelwaarde zelf). Een strikte lookup met `afhankelijkVan` controleert server-side dat de rij bij de gekozen bovenliggende waarde hoort.
- `"overschrijfbaar": true` bij een automatisch veld – de gebruiker mag het via de kaart overschrijven. Leeg = automatisch (bij component: uit de servicelocatie). Knop "Coördinaten weer automatisch" wist de override.
- Zoeken/reverse lopen server-side via `api.php?actie=geo-zoek|geo-adres` (`lib/geo.php`): Nominatim met User-Agent `Thoth/1.0 (Koninklijke van Twist; https://sleutels.kvt.nl/thoth/)` en Referer, globaal max 1 request/s (lock in `data/`), 7 dagen cache in `data/geocache/`, plus debounce en 1 req/s in de browser. Alleen de kaarttegels komen rechtstreeks van `tile.openstreetmap.org`.
- CSP (gezet in `lib/bootstrap.php`, dus ook zonder root-.htaccess): `default-src 'self'`, `img-src 'self' data: https://tile.openstreetmap.org`, `connect-src 'self'`, `script-src 'self'`, `style-src 'self' 'unsafe-inline'` (Leaflet); `Referrer-Policy: strict-origin-when-cross-origin` (de OSM-tegelserver wil een Referer).
- `"bcGeblokkeerd": "melding"` (hoogste niveau) – aanmaken in BC kan (nog) niet. Het formulier toont de melding, indienen kan wel, en goedkeuren faalt vóór elke BC-call met deze melding (het verzoek blijft Ingediend). Weghalen zodra BC zover is.
- `"_bron"` / `"_todo"` – documentatie in de config, wordt genegeerd.

### Echte configs (9-10-2026)

| Formulier | Webservice | BC-pagina / tabel | Nummer |
|---|---|---|---|
| Servicelocatie | `LVS_MainEntityCard` | page 11333009 *LVS_Main Entity Card* / tabel 11332937 | `ME1` + jaar + 5 cijfers, bv. `ME12600055` |
| Component | `AppComponentCard` | page 11332880 *LVS_Component Card* (Componentkaart) / tabel 11332871 | `COM10` + 5 cijfers, geen jaar, bv. `COM1002172` |

Beide gebruiken `max+1` (geen gaten opvullen: verwijderde nummers als ME12600003 komen dan niet terug).

- **Servicelocatie:** `Description` (naam, verplicht), `KVT_Description_2`, `Bill_to_Contact_No` (strikte lookup op `Contacts` met `KVT_Customer_No ne ''`, verplicht; BC leidt `Bill_to_Customer_No` er zelf van af), `KVT_Address`, `KVT_Post_Code`, `KVT_City` (verplicht), `KVT_Address_2`, `KVT_Country_Region_Code` (NL/BE/DE/IT/FI/PL, verplicht), `KVT_Language_Code` en `KVT_Language_Service_Report` (uit `AppLanguages`), coördinaten (`KVT_Latitude_Coordinate__x005B_DD_x005D_`, `KVT_Longitude_…`) en `KVT_Safety_Text`.
- **Component** (volgens Tims veldspecificatie van 09-10-2026): `Main_Entity` (Servicelocatie, strikte lookup op `LVS_MainEntityCard`, verplicht; niet in Tims lijst maar nodig voor de koppeling en de coördinaten), `Sub_Entity` (Equipmentsoort, strikte keuze uit `KVT_LVS_Sub_Entity`), `Description` (Omschrijving, max 100, verplicht), `Manufacturer_Code` (strikte keuze uit `KVT_Producenten`, lege rij gefilterd, verplicht), `Manufacturer_Model` (strikte keuze uit `KVT_LVS_Manufacturer_Model`, gefilterd op de gekozen producent; kies je eerst een model, dan wordt de producent ingevuld; wissel je de producent, dan vervalt het model; verplicht), `Description_2` (automatisch: `Description` van het gekozen model (producent + modelcode), max 50, leeg → `Model_Code`), `Serial_No` (max 50, verplicht; `NOG NIET BEKEND` als het onbekend is), coördinaten `KVT_Latitude_Coordinate__x005B_DD_x005D_`/`KVT_Longitude_…` (automatisch uit de gekozen servicelocatie, verplicht) en `KVT_Place_On_Location` (max 50). 'Naam' en 'ProjectNr.' uit Tims lijst staan er (nog) niet in: zie de open vragen in de PR.
- **Component is geblokkeerd** (`bcGeblokkeerd`): `Main_Entity` is op `AppComponentCard` niet bewerkbaar (AllowEdit/AllowEditOnCreate=false). De LVS-partner wordt gevraagd dat aan te passen. Daarna `bcGeblokkeerd` weghalen en eerst op `kvtfat_aad` testen.
- **Nummerreeks-risico:** ME- en COM-nummers komen in BC uit een nummerreeks. Thoth kiest zelf het volgende nummer; de *laatst gebruikte* van de BC-reeks loopt niet mee, waardoor een BC-gebruiker daarna een "bestaat al"-fout kan krijgen. Afspreken met KVT.
- Niet op het formulier: `Super_Entity_Code`, `VAT_Bus_Posting_Group`, contactnummers van eigenaar/bouwer (BC-tabellen niet gepubliceerd of zelden gebruikt) en draaiuren (alleen-lezen).

### Nummering bij goedkeuren

1. Lock (bestandslock per environment/bedrijf/tabel) zodat twee goedkeuringen tegelijk niet hetzelfde nummer pakken; daarnaast een lock per verzoek tegen dubbel goedkeuren.
2. Bestaande nummers met prefix+jaar vers uit BC: `$filter=startswith(No,'SL126')` (direct OData, geen cache).
3. **Eerste vrije**: laagste ongebruikte volgnummer vanaf 1 in dat jaar (gaten worden opgevuld). Omzetten naar max+1: `THOTH_NUMBER_STRATEGY_DEFAULT` in `web/lib/config.php` (één regel) of per config `"autoIncrementStrategie": "max+1"`.
4. Vóór de POST wordt het nummer bij het verzoek bewaard (`bc_reserved_json`).
5. Eén POST met alle velden. Geeft BC een duplicate/conflict (409, "already exists", "bestaat al"), dan het volgende vrije nummer, maximaal 4 pogingen. Andere fouten: geen retry, melding naar de goedkeurder.
6. **Time-outveilig:** bij een time-out, verbindingsfout, 408 of 5xx zoekt Thoth direct in BC (zelfde auth, geen cache) naar het gereserveerde nummer. Bestaat het record en komen de tekstvelden overeen, dan wordt het verzoek Goedgekeurd met dat nummer, zonder tweede POST. Zo niet, dan een fout zonder nieuwe poging. Bij opnieuw goedkeuren controleert Thoth eerst de eerder gereserveerde nummers, voor het geval BC het record later toch heeft aangemaakt. Een record van iemand anders op dat nummer (andere velden) telt niet als het onze.

## Bedrijf en environments

- Dropdown met de BC-bedrijvenlijst via Mímir `companies.php` over alle environments (terugval: per environment in `$auth_list` direct `…/ODataV4/Company`), 24 uur gecachet in `web/data/companies.json`.
- Mímir kent alleen de live-environments; environments uit `$auth_list` die Mímir niet teruggeeft (`kvtfat_aad`, `kvtfat2_aad`) haalt Thoth direct op. Testomgevingen staan in de lijst met "(test: …)" erachter; live (`kvtmdlive_aad`, `kvtgermanylive_aad`) bovenaan.
- De keuze wordt per gebruiker onthouden; elk verzoek slaat **bedrijf én environment** op. BC-auth komt uit `$auth_list[<environment van het verzoek>]`, nooit uit de volgorde van `$auth_list`.
- Lezen gaat via Mímir. Geeft Mímir voor een bedrijf een ander environment terug dan het verzoek (testbedrijf met dezelfde naam), dan leest Thoth direct via OData.
- Schrijven gaat direct via BC OData, volgens het patroon van Calculus (`BcAutomation::requestJson`): `POST {baseUrl}{environment}/ODataV4/Company('{bedrijf}')/{webservice}` met `Accept`/`Content-Type: application/json`, basic of NTLM.
- Geen BC-polling op de achtergrond: BC wordt alleen aangeroepen bij paginagebruik (bedrijvenlijst, opties, zoeken, inzenden) en bij goedkeuren.

## Datamodel (`web/data/thoth.sqlite`)

- `requests`: `id, type, owner, company, environment, status, data_json, labels_json, bc_number, bc_error, reject_reason, created_at, updated_at, submitted_at, decided_by, decided_at, bc_reserved_json` (schemaversie in `PRAGMA user_version`, migratie in één `BEGIN IMMEDIATE`)
- `status_history`: `id, request_id, actor, from_status, to_status, reason, at`
- `user_prefs`: `email, company, environment`

`web/data/` staat in `.gitignore` en is afgeschermd met `.htaccess` (Thoth zet die zelf neer als hij ontbreekt, omdat de deploy `data/` overslaat).

## auth.php

Kopieer `web/auth.example.php` naar `web/auth.php` en vul in: `$allowedUsers`, `$approvers`, `$mimirApi` (optioneel `$mimirBase`), `$baseUrl` (`https://kvtmd365.kvt.nl:7148/`) en `$auth_list` per environment. `web/auth.php` en `web/cfg.php` staan in `.gitignore` en worden nooit gecommit of door de deploy overschreven.

## Lokaal draaien en testen

```sh
cp web/auth.example.php web/auth.php   # en invullen
php -S 127.0.0.1:8080 -t web
for f in tests/*_test.php; do php "$f" || exit 1; done
```

Tests (PHP CLI, zoals bij Consus): `numbering_test.php` (padding, jaar, eerste vrije, max+1), `config_test.php` (configvalidatie, restrictiehook), `workflow_test.php` (statusovergangen, rechten, inzendvoorwaarde, strikte lookup, historie), `bc_insert_test.php` (BC-insert met gemockte HTTP-client: URL/headers/auth, conflict-retry, lock, Mímir-terugval, zoeken), `page_smoke_test.php` (pagina's via `php -S`, CSRF, escaping, autosave), `derived_test.php` (automatische velden, maxLengte, afhankelijke suggesties, echte component-config), `lookups_test.php` (strikte BC-keuzelijsten, model per producent, Omschrijving 2 uit de modeltabel), `geo_test.php` (Nominatim-proxy: headers, cache, throttle; kaartKiezer-config, coördinaatcontrole, overschrijven).

## Deploy

`.github/workflows/deploy-ftp.yml` (patroon Consus/Mímir/Asclepius): bij elke push op `master` spiegelt lftp `web/` naar de repo-secret `FTP_REMOTE_DIR` (bewaakt: alleen `/var/www/html/<map>`), met `FTP_HOST`, `FTP_USERNAME` en `FTP_PASSWORD`.

- Bestanden krijgen op de runner 644 (mappen 755) en de mirror draait zonder `--no-perms`, zodat Apache de PHP kan lezen.
- **Nooit aangeraakt** (niet geüpload, niet verwijderd): `auth.php`, `cfg.php`, `.htpasswd`, de root-`.htaccess` en de runtime-map `data/` (SQLite, `companies.json`, locks). `data` staat er als mapnode én als `data/` met slash én als `data/**` (les van Mímir PR #10: zonder de slash ruimt `--delete` de map alsnog op). Lokaal getest met `lftp file://`.
- `lib/.htaccess` en `config/.htaccess` gaan wél mee; Thoth zet `data/.htaccess` zelf neer.
- Daarna best-effort `chmod 777` op `data/` en de sqlite-bestanden (een 550 maakt de job niet rood) en een rooktest op `https://sleutels.kvt.nl/thoth/` en `data/thoth.sqlite` (moet 403/404 zijn).

Eerste keer op de server:
1. `web/auth.php` plaatsen (zie boven), met `kvtfat_aad` in `$auth_list` voor de eerste test.
2. Eventueel de root-`.htaccess` uit `web/.htaccess` handmatig plaatsen (de deploy laat de root-`.htaccess` van de server ongemoeid).
3. Controleren dat `data/` schrijfbaar is voor PHP en dat `https://sleutels.kvt.nl/thoth/data/thoth.sqlite` een 403 geeft.
