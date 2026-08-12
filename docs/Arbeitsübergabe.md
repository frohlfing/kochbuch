# Arbeitsübergabe: Digitales Kochbuch

Stand: Schritt 1 (Datenextraktion + Bereinigung) abgeschlossen.
Schritt 2 (Web-App mit PHP-Backend auf Hetzner-Webspace) steht bevor und wird mit in PHPStorm umgesetzt.

## 1. Worum es geht

Ein privates Familien-Kochbuch (ursprünglich in OneNote geführt) wird digitalisiert:

1. **Schritt 1 (fertig):** Rezepte + Fotos aus OneNote-Export (PDF, `.onepkg`, `.mht`) extrahiert, strukturiert, manuell bereinigt (ein Foto pro Rezept). Ergebnis: `recipes.jsonl` + `images/`-Ordner, angezeigt über eine statische `index.html`.
2. **Schritt 2 (bevorstehend):** Daraus eine echte Web-App bauen — Rezepte anlegen/bearbeiten/löschen, Bilder hochladen, A4-Druckansicht pro Rezept, Import von Rezepten per URL (z. B. chefkoch.de).

Hosting: Hetzner Shared-Webspace, **PHP** verfügbar (kein Node/Python serverseitig), MariaDB wäre verfügbar, wird aber bewusst **nicht** genutzt.

## 2. Aktueller Datenstand

Aus dem letzten Export:

```
kochbuch_export/
├── recipes.jsonl      ← 100 Zeilen, ein JSON-Objekt pro Zeile
└── images/             ← 100 Bilddateien, unterschiedliche Auflösungen/Formate (.jpg/.png)
```

### Aktuelles JSON-Schema (pro Rezept, eine Zeile in `recipes.jsonl`)

```json
{
  "title": "Kartoffelcremesuppe",
  "category": "Suppen",
  "image": "images/Kartoffelcremesuppe.jpg",
  "persons": "für 4 Personen",
  "ingredients": [
    { "group": null, "text": "8 Kartoffeln (800 g), gewürfelt" },
    { "group": "Für die Soße", "text": "..." }
  ],
  "steps": ["Gemüse mit Kräutern aufkochen ...", "..."],
  "notes": "Das ist lecker."
}
```

## 3. Zielarchitektur

### Kernkonzept

- **Ein Ordner pro Rezept:** `data/<slug>/`
- Darin: `recipe.json`, `image.<ext>` (Original-Upload), `thumb.<ext>` (automatisch generierte Miniatur)
- `slug` wird aus dem Titel abgeleitet. Wird der Titel geändert, ändert sich der Slug **intern mit** (Ordner wird umbenannt) — siehe Abschnitt 4.
- **PHP liefert ausschließlich eine JSON-API**, kein serverseitig gerendertes HTML. Das Frontend bleibt eine One-Page-App (HTML/JS), die Daten per `fetch()` gegen die API holt und schreibt.
- **Schreibende Endpunkte brauchen ein API-Token** (siehe Abschnitt 6). Lesende Endpunkte bleiben offen.
- Globale Konstanten (Pfade, Token, Limits) liegen zentral in `config.php`.

### Geplante Ordnerstruktur

```
/
├── config.php                  ← Konstanten: DATA_DIR, API_TOKEN, THUMB_WIDTH/HEIGHT, erlaubte Bild-Typen, ...
├── api/
│   ├── recipes.php             ← GET (Liste + Einzelabruf), POST (create), PUT (update), DELETE
│   ├── upload.php              ← Bild-Upload für ein Rezept (multipart/form-data)
│   ├── import.php              ← nimmt URL entgegen, wählt passenden Parser, validiert, speichert
│   └── lib/
│       ├── slug.php            ← slugify(), Uniqueness-Check
│       ├── thumbnail.php       ← Thumbnail-Erzeugung via GD
│       ├── crud.php            ← Read/Write/Rename/Delete-Logik
│       └── parsers/
│           ├── ParserInterface.php         ← Vertrag: supports(url): bool, parse(url): array
│           ├── ChefkochParser.php          ← erkennt/parst chefkoch.de
│           └── GenericSchemaOrgParser.php  ← Fallback für alle Seiten mit schema.org/Recipe JSON-LD
├── data/
│   ├── kartoffelcremesuppe/
│   │   ├── recipe.json
│   │   ├── image.jpg
│   │   └── thumb.jpg
│   ├── leberknoedelsuppe/
│   │   └── ...
│   └── ...
└── index.html                  ← Frontend, lädt/schreibt ausschließlich über /api/*
```

### Neues `recipe.json`-Schema (pro Rezept, eine Datei)

```json
{
  "title": "Leberknödelsuppe",
  "slug": "leberknoedelsuppe",
  "category": "Suppen",
  "servings": "für 4 Personen",
  "order": 4,
  "created": null,
  "image": "image.jpg",
  "thumb": "thumb.jpg",
  "ingredients": [
    { "group": null, "text": ["ca. 1 Liter Wasser", "..."] },
    { "group": "Für die Knödel", "text": ["500 g Rinderleber", "6 altbackene Brötchen, geraspelt", "..."] }
  ],
  "steps": ["Gemüse mit Kräutern aufkochen ...", "..."],
  "notes": "Das ist lecker."
}
```

### Änderungen gegenüber dem alten Schema

| Feld | Alt | Neu |
|---|---|---|
| Identifikation | Titel direkt als Dateiname-Basis | `title` **und** `slug` getrennt; `slug` = Ordnername, wird bei Titeländerung automatisch neu berechnet |
| `persons` | String, pro Rezept | umbenannt in **`servings`**|
| `ingredients` | flache Liste, `group` bei jedem einzelnen Eintrag wiederholt | **gruppiert**: eine Zeile pro Gruppe, `text` ist jetzt eine Liste. Reihenfolge der Gruppen bleibt erhalten, `group: null` für Zutaten ohne Überschrift (steht i. d. R. als erster Block) |
| `image` | Pfad relativ zum globalen `images/`-Ordner | nur noch Dateiname innerhalb des Rezept-Ordners (z. B. `"image.jpg"`) |
| `thumb` | — (gab es nicht) | neues Feld, Dateiname der Miniatur im selben Ordner |
| `order` | — | **neu**, siehe Abschnitt 5 |
| `created` | — | **neu**, siehe Abschnitt 5 — aktuell für alle Rezepte `null`, da keine echten Zeitstempel vorliegen |

### Entscheidung: Gruppennamen ohne Doppelpunkt

Gruppen werden **ohne** Doppelpunkt gespeichert (`"Für die Knödel"`, nicht `"Für die Knödel:"`) — konsistent mit dem bisherigen Datenbestand, kein Migrationsaufwand nötig. Der Doppelpunkt ist reine Präsentation und wird, falls gewünscht, im Frontend beim Anzeigen angehängt (z. B. `group + ':'` vor der Zutatenliste).

## 4. Slug-Erzeugung und Rename-Logik

```php
function slugify(string $title): string {
    $map = [
        'ä'=>'ae','ö'=>'oe','ü'=>'ue','Ä'=>'Ae','Ö'=>'Oe','Ü'=>'Ue','ß'=>'ss',
        'é'=>'e','è'=>'e','ê'=>'e','à'=>'a','â'=>'a','ô'=>'o','î'=>'i','ç'=>'c','ñ'=>'n',
    ];
    $s = strtr(trim($title), $map);
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return $s;
}
```
(Gleiche Umlaut-Transliteration wie zuvor, jetzt zusätzlich kleingeschrieben und mit Bindestrichen statt Leerzeichen/Klammern — passend für einen Verzeichnisnamen statt einen für Menschen lesbaren Dateinamen.)

**Uniqueness:** Titel muss weiterhin eindeutig sein (case-insensitive prüfen). Da mehrere unterschiedliche Titel theoretisch auf denselben Slug abbilden könnten (z. B. durch Sonderzeichen-Unterschiede), zusätzlich **Slug-Eindeutigkeit separat prüfen** — bei Kollision z. B. `-2`, `-3` anhängen.

**Titel ändern (Update mit geändertem Titel) — atomare Operation:**
1. Neuen Slug berechnen
2. Wenn Slug unverändert → nur `recipe.json` überschreiben, fertig
3. Wenn Slug sich ändert:
   a. Prüfen, ob `data/<neuer-slug>/` bereits existiert → falls ja, Fehler zurückgeben, nichts ändern
   b. Ordner `data/<alter-slug>/` → `data/<neuer-slug>/` umbenennen (`rename()`, ein Dateisystem-Aufruf, damit `recipe.json`, `image.*`, `thumb.*` automatisch mitwandern)
   c. `title` und `slug` in `recipe.json` aktualisieren, Datei im neuen Ordner überschreiben
4. Schlägt Schritt 3b fehl → gesamte Operation abbrechen, keine Teilstände

**Löschen:** kompletten Ordner `data/<slug>/` rekursiv entfernen (`recipe.json` + Bilder verschwinden automatisch zusammen — das strukturell unmögliche Gegenstück zum „verwaisten Bild"-Bug aus Schritt 1).

## 5. `order` und `created`

- **`created`**: Wir haben **keine verlässlichen Erstellungsdaten** pro Rezept (nur ein Änderungsdatum pro OneNote-Kategorie-Datei, nicht pro Rezept — zu grob). Feld bleibt vorerst `null` bei der Migration der Bestandsdaten; ab jetzt beim `POST` (Neuanlage) mit `date('c')` befüllen.
- **`order`**: Fortlaufende Nummer 1–100, aus der ursprünglichen Seitenreihenfolge im PDF-Export übernommen (entspricht der Reihenfolge, in der die Rezepte im Original-Kochbuch standen). Bei neu angelegten Rezepten: `max(order) + 1`.

## 6. API-Token

- Konstante `API_TOKEN` in `config.php` (langer zufälliger String)
- Schreibende Endpunkte (`POST`/`PUT`/`DELETE` auf `recipes.php`, `upload.php`) prüfen einen Header, z. B. `X-API-Token: <token>` — bei Fehlen/Falschwert → HTTP 401, keine weitere Verarbeitung
- Lesende Endpunkte (`GET`) bleiben ohne Token erreichbar
- Frontend: Token einmalig abfragen (z. B. `prompt()` beim ersten Schreibversuch) und in `localStorage` ablegen, danach automatisch mitschicken
- Das ist **kein** vollwertiges Auth-System (keine einzelnen Nutzerkonten, kein Ablauf) — passend zum Anforderungsprofil „max. 5 Nutzer, meist lesend". Reicht, um zu verhindern, dass zufällige Besucher der URL schreibend zugreifen.

## 7. Thumbnail-Erzeugung

- Bei Bild-Upload (`upload.php`): `image.<ext>` speichern, danach **sofort** `thumb.<ext>` per PHP-GD generieren (Center-Crop auf festes Seitenverhältnis, z. B. 4:3, passend zum bisherigen Karten-Layout — siehe Abschnitt 8)
- Zusätzlich **Lazy-Fallback**: Falls beim Lesen eines Rezepts `thumb.*` fehlt (z. B. bei Altdaten-Migration), einmalig aus `image.*` nachgenerieren und ablegen
- GD-Extension ist auf praktisch jedem PHP-Shared-Hosting vorhanden — vor dem Bauen trotzdem kurz auf dem Hetzner-Webspace verifizieren (`extension_loaded('gd')`)

## 8. Bekannte Fallstricke aus Schritt 1 (weiterhin relevant)

- **Server-seitig zugeschnittene Thumbnails lösen ein altes Problem elegant:** In Schritt 1 gab es mehrfach CSS-Bugs, weil Bild-Boxen im Frontend per CSS auf ein festes Seitenverhältnis gezwungen wurden (`aspect-ratio` wird nicht überall unterstützt, Padding-Hack-Fallback war fehleranfällig bei fester Pixelbreite). Wenn `thumb.*` jetzt **serverseitig bereits im richtigen Seitenverhältnis** zugeschnitten wird, braucht die Kartenansicht im Frontend gar keine CSS-Ratio-Tricks mehr — einfach `width:100%; height:auto;` reicht. Nur für frei skalierbare Vorschau-Elemente (z. B. Bildvorschau im Bearbeiten-Formular vor dem Hochladen) ist der Padding-Hack ggf. weiterhin nötig.
- **Verwaistes Bild bei „Peking Ente":** Grund, warum Rename/Delete jetzt so genau spezifiziert sind (Abschnitt 4). Mit „ein Ordner = ein Rezept" ist dieses Problem strukturell ausgeschlossen, solange Rename/Delete tatsächlich den ganzen Ordner anfassen und nicht Datei-für-Datei arbeiten.
- **Unicode-Dateinamen + ZIP:** falls es einen Export/Backup-Download gibt, UTF-8-Flag beim Zippen setzen bzw. mit den (bereits transliterierten) Slug-Namen arbeiten.

## 9. Geplanter Funktionsumfang

1. **CRUD** (`recipes.php`): Rezept anlegen, bearbeiten, löschen (Titel, Kategorie, Personen, Zutaten inkl. Gruppen, Schritte, Notizen)
2. **Bild-Upload** (`upload.php`): einzelnes Bild pro Rezept, beim Speichern serverseitig entgegennehmen (`move_uploaded_file`)
3. **Drucken**: eine A4-Seite pro Rezept, Layout angelehnt an die ursprüngliche PDF-Vorlage (Foto oben links, Titel oben rechts, Zutaten als Liste, Schritte nummeriert). Umsetzung rein clientseitig über `@media print`-CSS — kein PHP-PDF-Generator nötig, Browser-„Drucken → Als PDF speichern“ reicht.
4. **Import von chefkoch.de (oder ähnliche Portale) per URL** (`import.php`): chefkoch.de bettet Rezepte als `schema.org/Recipe` JSON-LD ein. Serverseitig (PHP, `file_get_contents`/cURL) die Zielseite laden, `<script type="application/ld+json">` mit `"@type":"Recipe"` extrahieren, geparste Felder als Entwurf ins Bearbeitungsformular vorbefüllen. Generischer Ansatz — funktioniert nicht nur bei Chefkoch, sondern bei jeder Seite mit demselben Schema (wie bei nextcloud/cookbook).
4. **Import per URL** (`import.php`): Rezepte von chefkoch.de importieren. Erweiterbar für ähnliche Koch-Portale.

### Import-Funktion

Die Import-Funktion ist von Anfang an auf mehrere Quell-Portale ausgelegt, nicht fest an chefkoch.de gebunden.
Dazu gibt es eine kleine Parser-Registry: jeder Parser implementiert ein gemeinsames ParserInterface (supports(string $url): bool, parse(string $url): array).
Anhand der übergebenen URL wird der passende Parser ermittelt — enthält die URL z. B. chefkoch, wird der ChefkochParser gewählt, der die Seite lädt und die relevanten Felder extrahiert.
Findet sich kein spezifischer Parser, greift als Fallback ein GenericSchemaOrgParser, der versucht, schema.org/Recipe-JSON-LD generisch auszulesen (deckt viele weitere Rezeptseiten ab, ohne dass pro Portal eigener Code nötig ist).

`import.php` übernimmt danach den kompletten Ablauf selbst: Parser aufrufen → Daten ins interne Schema (Abschnitt 3) übersetzen → validieren (Pflichtfelder vorhanden, Titel eindeutig — dieselbe Prüfung wie bei POST auf recipes.php, idealerweise über dieselbe interne Funktion aus recipe_store.php aufgerufen, damit keine zwei unterschiedlichen Validierungswege entstehen) → Ordner anlegen, Bild herunterladen/speichern, recipe.json schreiben. Es ist kein separater Bestätigungsschritt über den normalen POST-Endpunkt mehr nötig — Import ist ein direkter, vollständiger Schreibvorgang und braucht daher ebenfalls das API-Token (Abschnitt 6).

Neue Portale ergänzen sich später, indem einfach ein weiterer Parser die ParserInterface implementiert und in der Registry eingetragen wird — an import.php selbst ändert sich dabei nichts.

### Parser für Chefkoch-Quelle

`chefkoch.de` bettet Rezepte als `schema.org/Recipe` JSON-LD ein. Serverseitig (PHP, `file_get_contents`/cURL) die Zielseite laden, `<script type="application/ld+json">` mit `"@type":"Recipe"` extrahieren, geparste Felder als Entwurf ins Bearbeitungsformular vorbefüllen.

## 10. Migration der Bestandsdaten (100 Rezepte)

Einmaliges Migrationsskript nötig:
1. Für jede Zeile in der alten `recipes.jsonl`: Slug berechnen, Ordner `data/<slug>/` anlegen
2. Bilddatei aus dem alten `images/`-Ordner nach `data/<slug>/image.<ext>` verschieben
3. Thumbnail generieren → `data/<slug>/thumb.<ext>`
4. `ingredients` von flacher Liste auf gruppierte Struktur umbauen (siehe Abschnitt 3 — aufeinanderfolgende Einträge mit gleichem `group`-Wert zusammenfassen)
5. `persons` → `servings` umbenennen
6. `order` = ursprüngliche Position in der `recipes.jsonl` (1–100)
7. `created` = `null`
8. `recipe.json` schreiben

## 11. Offene Punkte für Claude Code

- [ ] Migration der 100 Bestandsrezepte ins neue Format durchführen (Abschnitt 10)
- [ ] GD-Verfügbarkeit auf dem Ziel-Webspace prüfen
- [ ] Thumbnail-Zielformat/-maße festlegen (Vorschlag: 4:3, Breite orientiert an bisherigem Karten-Layout, z. B. 480×360)
- [ ] API-Token-Übergabe im Frontend UX-mäßig festlegen (Prompt beim ersten Schreibversuch vs. eigene „Anmelden"-Maske)
- [ ] Reihenfolge der Umsetzung weiterhin: CRUD + Upload zuerst, danach Druckansicht, danach Chefkoch-Import
