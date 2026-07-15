# Time Manual

WordPress plugin pro **interní manuály pro editory webu**. Administrátor píše návody jako klasické články, editoři si je otevřou přímo z nástěnky administrace. Články nejsou zvenčí dostupné — ani s přesnou URL, ani pro přihlášeného návštěvníka bez oprávnění.

- **Verze:** 1.0.4
- **Autor:** Radomír Panna ([timesoft.cz](https://timesoft.cz))
- **Licence:** GPL-2.0+

---

## Obsah

- [Co plugin dělá](#co-plugin-dělá)
- [Požadavky](#požadavky)
- [Instalace](#instalace)
- [Přístupový model](#přístupový-model)
- [Použití](#použití)
- [Export a import](#export-a-import)
- [Média manuálů](#média-manuálů)
- [Lokalizace](#lokalizace)
- [Struktura souborů](#struktura-souborů)
- [Reference](#reference)
- [Známá omezení](#známá-omezení)
- [Changelog](#changelog)

---

## Co plugin dělá

- CPT `time_manual` (**Manuály**), který smí psát a editovat uživatel s právy **administrátor**.
- CPT je **veřejně nepřístupný** — `public=false`, `publicly_queryable=false`, `exclude_from_search=true`.
- Na úvodní stránce administrace přidává **widget nástěnky** se seznamem manuálů; klik otevře obsah v modálním okně (AJAX).
- Přístup ke čtení řídí **dvouvrstvý model** (capability + per-článek role).
- **export do ZIP** a **import z jiného webu** včetně obrázků a PDF.
- **Skrýtí médií manuálů** z knihovny médií před neadminy.

---

## Požadavky

| Položka | Verze / poznámka |
|---|---|
| WordPress | 5.6+ (kvůli `wp_add_dashboard_widget` s parametry `context`/`priority`) |
| PHP | 7.0+ |
| PHP rozšíření `ZipArchive` | nutné pro **export**; bez něj plugin funguje, jen export zahlásí chybu |

Import používá jádrovou funkci `unzip_file()`, která si vystačí i s PL Zip fallbackem — ZipArchive tedy potřebuje jen export.

---

## Instalace

1. Nakopírovat složku `time-manual/` do `wp-content/plugins/`.
2. Aktivovat plugin v administraci.

Při aktivaci se zaregistruje CPT a capability `read_time_manual` se přiřadí rolím podle nastavení (nebo defaultům). Při **deaktivaci** se capability ze všech rolí zase odebere.

---

## Přístupový model

Čtení manuálů řídí dvě nezávislé vrstvy. Administrátor (`manage_options`) obchází obě a vidí vždy všechno.

### Vrstva 1 — capability `read_time_manual`

Globální brána k celé funkci. V *Manuály → Nastavení* zaškrtneš role, které smí manuály číst; po uložení se capability synchronizuje napříč rolemi (`tman_sync_capabilities()`).

Kdo capability nemá:
- widget na nástěnce se mu vůbec nevykreslí,
- AJAX endpoint mu vrátí **403**.

Model je **allow-list** — co není zaškrtnuté, nevidí nic.

**Výchozí role:** `editor`, `sefredaktor`, `chief_editor` — ale jen ty, které na webu reálně existují. Když není ani jedna, použije se aspoň `editor`.

### Vrstva 2 — per-článek viditelnost

Metabox **Role visibility** u každého manuálu nabízí zaškrtávátka **jen těch rolí, které mají capability** (tj. jsou v allow-listu) — je jich pár, ne stovky. Slouží k *zúžení* okruhu: komu z oprávněných konkrétní článek skrýt.

- U nového článku jsou předzaškrtnuté defaulty z nastavení.
- Uloženo jako post meta `_tman_allowed_roles` (pole slugů rolí).
- **Prázdný seznam = článek vidí každý, kdo projde vrstvou 1.**
- Uloží se jen role, které jsou reálně v allow-listu (ostatní se zahodí).

### Shrnutí

```
uživatel vidí manuál  ⟺  (má read_time_manual  ∨  je admin)
                          ∧ (allow-list článku je prázdný
                             ∨ jedna z jeho rolí je v allow-listu článku)
                          ∧ článek je publikovaný
```

---

## Použití

### Nastavení rolí

*Manuály → Nastavení* — zaškrtni role, které smí manuály číst. Administrátor se v seznamu neuvádí, capability dostává vždy.

### Psaní manuálu

*Manuály → Přidat* — klasický editor (titulek + obsah). V pravém sloupci metabox **Role visibility** pro per-článek omezení.

### Čtení manuálu

Na nastěnce - úvodní stránka administrace → klikne na název → obsah se načte AJAXem do modálního okna.

---

## Export a import

**ZIP balík** je plně samostatný - přenese v JSON texty a nastavení, v samostatném adresáři pak všechny média.

### Struktura balíku

```
manualy-export-2026-07-15-103000.zip
├── manual.json
└── media/
    └── 2026/07/screenshot.png     # relativní cesta z uploads (zabrání kolizi jmen)
```

`manual.json`:

```json
{
  "version": "1.0",
  "site": "https://zdrojovy-web.cz",
  "generated": "2026-07-15 10:30:00",
  "manuals": [
    {
      "title": "Jak založit životní situaci",
      "content": "<!-- wp:paragraph -->…",
      "allowed_roles": ["editor"],
      "media": [
        {
          "original_url": "https://zdrojovy-web.cz/wp-content/uploads/2026/07/screenshot.png",
          "file": "media/2026/07/screenshot.png"
        }
      ]
    }
  ]
}
```

### Import

**Manuály → Import** — nahraj ZIP. Pro každý manuál:

1. média z `media/` se nahrají do knihovny cílového webu,
2. URL se přepíše na nové (podle manifestu),
3. založí se článek ve stavu `publish`,
4. povolené role se nastaví na **defaulty cílového webu** — cizí slugy rolí se záměrně nepřenášejí.

Naimportovaná média se rovnou označí jako média manuálu (viz níž).

---

## Média manuálů

Obrázky a PDF vložené do manuálu končí ve společné knihovně médií. Přílohy nahrané v kontextu manuálu se značí metou `_tman_media` a:

1. **filtrují se ze všech dotazů na přílohy** — mřížka (`ajax_query_attachments_args`), seznam `upload.php` (`pre_get_posts`), blokový editor (`rest_attachment_query`),
2. **`map_meta_cap`** neadminům zakáže `edit_post` / `delete_post` / `read_post` nad takovou přílohou.

Administrátor média vidí bez omezení.

**Značkování** probíhá třemi cestami:
- hook `add_attachment` přes `post_parent` (chytá blokový i klasický editor),
- sideload při importu,
- `tman_backfill_media()` — jednorázově na `admin_init`, doznačkuje média nahraná před verzí 1.0.3 (guard option `tman_media_backfilled`).

---

## Lokalizace

Zdrojové řetězce jsou **anglicky** (konvence WordPressu), překlady ve složce `languages/`:

| Locale | Poznámka |
|---|---|
| `cs_CZ` | čeština |
| `de_DE` | němčina |
| `es_ES` | španělština |


### Workflow při přidání nového řetězce

```bash
wp i18n make-pot . languages/time-manual.pot
wp i18n update-po languages/time-manual.pot languages/
wp i18n make-mo languages/
wp i18n make-php languages/
```

---

## Struktura souborů

```
time-manual/
├── time-manual.php          # hlavička, konstanty, načtení překladů, aktivace/deaktivace
├── includes/
│   ├── access.php           # dvouvrstvý přístupový model, capability, výběr čitelných článků
│   ├── cpt.php              # registrace CPT time_manual + menu „Manuály"
│   ├── metabox.php          # per-článek role (vrstva 2)
│   ├── settings.php         # stránka nastavení — allow-list rolí (vrstva 1)
│   ├── media.php            # značkování a skrytí médií manuálů z knihovny
│   ├── dashboard.php        # widget nástěnky + enqueue assetů
│   ├── ajax.php             # načtení obsahu manuálu do modalu
│   ├── export.php           # ZIP export (submenu + bulk akce)
│   └── import.php           # ZIP import (submenu)
├── assets/
│   ├── dashboard.css        # styly widgetu a modalu
│   └── dashboard.js         # modal + fetch obsahu
└── languages/               # .pot, .po, .mo, .l10n.php
```

---

## Reference

### Konstanty

| Konstanta | Hodnota | Význam |
|---|---|---|
| `TMAN_VERSION` | `1.0.4` | verze pluginu (cache-busting assetů) |
| `TMAN_CAP` | `read_time_manual` | capability ke čtení manuálů |
| `TMAN_OPTION` | `tman_settings` | option s nastavením (klíč `read_roles`) |
| `TMAN_META_ROLES` | `_tman_allowed_roles` | post meta — per-článek allow-list rolí |
| `TMAN_META_MEDIA` | `_tman_media` | meta příznak přílohy patřící manuálu |

### Klíčové funkce

| Funkce | Účel |
|---|---|
| `tman_user_can_read($user)` | vrstva 1 — smí uživatel manuály obecně vidět? |
| `tman_user_can_read_post($post_id, $user)` | vrstva 1 ∧ vrstva 2 pro konkrétní článek |
| `tman_readable_posts($user)` | publikované manuály viditelné danému uživateli |
| `tman_get_read_roles()` | allow-list rolí z nastavení (nebo defaulty) |
| `tman_sync_capabilities()` | přiřadí/odebere capability napříč rolemi dle allow-listu |
| `tman_remove_all_capabilities()` | odebere capability ze všech rolí (deaktivace) |
| `tman_stream_export_zip($post_ids)` | sestaví ZIP a odešle ke stažení; vrací se jen při chybě |
| `tman_backfill_media()` | doznačkuje média manuálů nahraná před v1.0.3 |

### Databázové stopy

| Klíč | Typ | Úklid při deaktivaci |
|---|---|---|
| `tman_settings` | option | zůstává |
| `tman_media_backfilled` | option | zůstává |
| `_tman_allowed_roles` | post meta | zůstává |
| `_tman_media` | post meta | zůstává |
| `read_time_manual` | capability rolí | **odebere se** |

---

## Známá omezení

- **Sdílená média:** značka `_tman_media` je na příloze, ne na vazbě. Když je tentýž soubor použitý v manuálu i v běžném příspěvku, zmizí neadminům z knihovny i tam. Řešení: nahrát kopii.
- **Přímý přístup na URL souboru:** skrytí z knihovny ≠ skrytí z internetu. Kdo zná URL obrázku/PDF, otevře si ho. Ošetření by vyžadovalo servírování uploadů přes PHP + pravidlo v `.htaccess`/nginx.
- **Klonované role:** allow-list se plní ručně v nastavení. Weby se stovkami klonů řeš jednorázovým skriptem (viz [Vrstva 1](#vrstva-1--capability-read_time_manual)).
- **Import vždy zakládá nový článek** — neexistuje párování s už existujícím manuálem, opakovaný import vytvoří duplicity.

---

## Changelog

### 1.0.4
- Internacionalizace: zdrojové řetězce přepsány do angličtiny, přidány překlady `cs_CZ`, `de_DE`, `es_ES` (`.pot`/`.po`/`.mo`/`.l10n.php`).
- `Domain Path: /languages`, `load_plugin_textdomain()` na `init` s prioritou 0.

### 1.0.3
- Média manuálů se skrývají z knihovny médií neadminům (`includes/media.php`) — meta `_tman_media`, filtry na mřížku, seznam i REST, `map_meta_cap` zákaz manipulace.
- `tman_backfill_media()` doznačkuje média nahraná dřív; normalizace URL zmenšenin kvůli `attachment_url_to_postid()`.

### 1.0.1 – 1.0.2
- Přidáno submenu **Manuály → Export** (dřív byl export jen bulk akcí a nešel v menu najít). Jádro vytaženo do `tman_stream_export_zip()`, formulář se zpracovává na `admin_init`.
- Widget nástěnky přesunut do pravého sloupce (`context: 'side'`, `priority: 'high'`).
- Doladěna typografie modalu (selektory na potomky `.tman-modal__body`).

### 1.0.0
- První verze: CPT `time_manual`, dvouvrstvý přístupový model, widget nástěnky s AJAX modalem, ZIP export/import, stránka nastavení.

---

## Licence

GPL-2.0+ — viz [gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)
