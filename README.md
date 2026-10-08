# Thoth

sleutels.kvt.nl-app om **Servicelocaties (Main Entity)** en **Componenten** via een formulier aan te vragen, te laten goedkeuren en bij goedkeuring direct in Business Central aan te maken.

Pagina-root: `web/`. PHP 8.4, SQLite, geen externe dependencies.

## Werking

| Stap | Wie | Wat |
|---|---|---|
| Indienen | gebruiker in `$allowedUsers` | Opent een formulier (Servicelocatie of Component). Wijzigingen worden debounced (1 s) server-side als **Concept** opgeslagen, met de melding "Opgeslagen hh:mm:ss". **Inzenden** kan pas als alle verplichte velden gevuld zijn en aan de restricties is voldaan (de server controleert opnieuw). Status wordt **Ingediend**. |
| Goedkeuren | gebruiker in `$approvers` (en `$allowedUsers`) | Ziet alle ingediende verzoeken. **Goedkeuren** maakt het record direct in BC aan via OData (POST); status **Goedgekeurd** en het BC-nummer wordt bewaard. Mislukt de insert, dan blijft het **Ingediend** met de BC-foutmelding zichtbaar voor de goedkeurder. **Afwijzen** kan met een optionele reden. |
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

> **Let op:** de meegeleverde configs zijn **voorbeelden** met verzonnen webservices (`VOORBEELD_…`). Tim levert de echte configs.

### Nummering bij goedkeuren

1. Lock (bestandslock per environment/bedrijf/tabel) zodat twee goedkeuringen tegelijk niet hetzelfde nummer pakken; daarnaast een lock per verzoek tegen dubbel goedkeuren.
2. Bestaande nummers met prefix+jaar vers uit BC: `$filter=startswith(No,'SL126')` (direct OData, geen cache).
3. **Eerste vrije**: laagste ongebruikte volgnummer vanaf 1 in dat jaar (gaten worden opgevuld). Omzetten naar max+1: `THOTH_NUMBER_STRATEGY_DEFAULT` in `web/lib/config.php` (één regel) of per config `"autoIncrementStrategie": "max+1"`.
4. Eén POST met alle velden. Geeft BC een duplicate/conflict (409, "already exists", "bestaat al"), dan het volgende vrije nummer, maximaal 4 pogingen. Andere fouten: geen retry, melding naar de goedkeurder.

## Bedrijf en environments

- Dropdown met de BC-bedrijvenlijst via Mímir `companies.php` over alle environments (terugval: per environment in `$auth_list` direct `…/ODataV4/Company`), 24 uur gecachet in `web/data/companies.json`.
- Testomgevingen (`kvtfat_aad`, `kvtfat2_aad`) staan in de lijst met "(test: …)" erachter; live (`kvtmdlive_aad`, `kvtgermanylive_aad`) bovenaan.
- De keuze wordt per gebruiker onthouden; elk verzoek slaat **bedrijf én environment** op. BC-auth komt uit `$auth_list[<environment van het verzoek>]`, nooit uit de volgorde van `$auth_list`.
- Lezen gaat via Mímir. Geeft Mímir voor een bedrijf een ander environment terug dan het verzoek (testbedrijf met dezelfde naam), dan leest Thoth direct via OData.
- Schrijven gaat direct via BC OData, volgens het patroon van Calculus (`BcAutomation::requestJson`): `POST {baseUrl}{environment}/ODataV4/Company('{bedrijf}')/{webservice}` met `Accept`/`Content-Type: application/json`, basic of NTLM.
- Geen BC-polling op de achtergrond: BC wordt alleen aangeroepen bij paginagebruik (bedrijvenlijst, opties, zoeken, inzenden) en bij goedkeuren.

## Datamodel (`web/data/thoth.sqlite`)

- `requests`: `id, type, owner, company, environment, status, data_json, labels_json, bc_number, bc_error, reject_reason, created_at, updated_at, submitted_at, decided_by, decided_at`
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

Tests (PHP CLI, zoals bij Consus): `numbering_test.php` (padding, jaar, eerste vrije, max+1), `config_test.php` (configvalidatie, restrictiehook), `workflow_test.php` (statusovergangen, rechten, inzendvoorwaarde, strikte lookup, historie), `bc_insert_test.php` (BC-insert met gemockte HTTP-client: URL/headers/auth, conflict-retry, lock, Mímir-terugval, zoeken), `page_smoke_test.php` (pagina's via `php -S`, CSRF, escaping, autosave).

## Deploy

Nog **geen** workflow in de repo. Eerst moet de repo-secret `FTP_REMOTE_DIR` bestaan (bv. `/var/www/html/thoth`), naast `FTP_HOST`, `FTP_USERNAME` en `FTP_PASSWORD`. Daarna `.github/workflows/deploy-ftp.yml` toevoegen (gebaseerd op Consus): bij push op `master` spiegelt lftp `web/` naar `FTP_REMOTE_DIR`, met uitzondering van `.htaccess`, `auth.php`, `cfg.php` en de runtime-map `data/`; daarna best-effort `chmod 777 data`.

Eerste keer op de server:
1. `web/auth.php` plaatsen (zie boven).
2. Controleren dat `data/` schrijfbaar is voor PHP en dat `https://sleutels.kvt.nl/thoth/data/thoth.sqlite` een 403 geeft.
3. De echte configs in `web/config/` zetten (via git).
