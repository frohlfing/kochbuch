# Kochbuch

Ein privates Familien-Kochbuch, digitalisiert als kleine PHP/JSON-API mit einer statischen One-Page-Frontend-App.

## Voraussetzungen

- PHP 8.5+ mit aktivierter **GD**-Extension (für Thumbnails)
- Ein Webserver mit `public/` als Document Root
- Kein Datenbankserver nötig – alle Daten liegen als Dateien unter `data/`, bewusst außerhalb
  des Document Root (Zugriff nur über `api/recipes.php` bzw. `api/image.php`, nicht direkt per URL)

## Setup

1. `config.example.php` nach `config.php` kopieren (liegt im Projekt-Root, **nicht** unter `public/`,
   damit es außerhalb des Document Root und damit nicht direkt aufrufbar ist).
2. In `config.php` ein echtes `API_TOKEN` eintragen, z. B.:
   ```
   php -r "echo bin2hex(random_bytes(32));"
   ```
3. Webserver mit Document Root `public/` auf ein Verzeichnis zeigen lassen (z. B. XAMPP-vHost).
4. GD-Extension für PHP aktivieren. Unter XAMPP geht das so:

    Testdatei:
    ```php
    <?php
    // Nur zur manuellen Prüfung der PHP-Konfiguration (z. B. GD-Verfügbarkeit) nach dem Deploy.
    // Danach wieder löschen - phpinfo() gibt Server-Interna preis.
    phpinfo();
    ```

   Unter XAMPP:  
   - `C:\xampp\php\php.ini` öffnen und die Zeile `;extension=gd` zu `extension=gd` ändern.
   - Apache neu starten.

   Unter Hetzner-Webspace: 
   - GD ist standardmäßig aktiviert. Falls nicht, kann dies per KonsoleH eingerichtet werden.

4. Seite aufrufen – die Rezeptliste lädt sich selbst über `GET /api/recipes.php`.

## Projektstruktur

```
config.php                  Zentrale Konstanten (Pfade, API_TOKEN, Thumbnail-Maße, ...). Gitignored.
config.example.php          Vorlage dafür, versioniert.
data/                        Außerhalb des Document Root, kein direkter Browser-Zugriff möglich
└── recipes/
    └── <slug>/               Ein Ordner pro Rezept
        ├── recipe.json
        ├── image.<ext>       Original-Upload
        └── thumb.<ext>       automatisch generierte Miniatur
public/                     Document Root
├── index.html               Frontend (One-Page-App, lädt/schreibt ausschließlich über /api/*)
└── api/
    ├── recipes.php          GET/POST/PUT/DELETE – Rezepte lesen, anlegen, bearbeiten, löschen
    ├── upload.php           POST – Bild-Upload für ein Rezept inkl. Thumbnail-Generierung
    ├── image.php            GET – liefert image.<ext>/thumb.<ext> aus (siehe data/, oben)
    ├── import.php           POST – Rezept von externer URL importieren (chefkoch.de u. Ä.)
    └── lib/
        ├── crud.php         Read/Write/Rename/Delete-Logik + Validierung
        ├── slug.php         slugify() + Eindeutigkeits-Check
        ├── thumbnail.php    Thumbnail-Erzeugung per GD (Center-Crop) + Lazy-Fallback
        ├── image_store.php  Bild validieren/speichern + Thumbnail (gemeinsam für upload.php und import.php)
        ├── http.php         JSON-Response- und Token-Check-Helper
        └── parsers/
            ├── RecipeParserInterface.php    Vertrag: supports(url), parse(url)
            ├── ChefkochParser.php           erkennt/parst chefkoch.de
            ├── GenericSchemaOrgParser.php   Fallback für jede Seite mit schema.org/Recipe
            ├── schema_org.php               gemeinsame JSON-LD-Extraktion (auch @graph-Strukturen)
            └── fetch.php                    http_get(): curl, sonst file_get_contents()-Fallback
```

## API

Alle schreibenden Endpunkte (`POST`/`PUT`/`DELETE`) erwarten den Header `X-API-Token: <token>`,
sonst `401`. Lesende Endpunkte (`GET`) sind offen.

| Methode | Endpunkt                                    | Beschreibung                                                                                                                |
|---------|---------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------|
| GET     | `/api/recipes.php`                          | Alle Rezepte, sortiert nach `order`                                                                                         |
| GET     | `/api/recipes.php?slug=xyz`                 | Einzelnes Rezept                                                                                                            |
| POST    | `/api/recipes.php`                          | Neues Rezept anlegen (JSON-Body)                                                                                            |
| PUT     | `/api/recipes.php?slug=xyz`                 | Rezept bearbeiten (JSON-Body). Ändert sich der Titel so, dass sich der Slug ändert, wird der Rezeptordner atomar umbenannt. |
| DELETE  | `/api/recipes.php?slug=xyz`                 | Rezept samt Ordner (Bilder inklusive) löschen                                                                               |
| POST    | `/api/upload.php`                           | Bild für ein Rezept hochladen (`multipart/form-data`: `slug`, `image`)                                                      |
| GET     | `/api/image.php?slug=xyz&type=image\|thumb` | Original- bzw. Thumbnail-Bild ausliefern                                                                                    |
| POST    | `/api/import.php`                           | Rezept von einer externen URL importieren (Body: `{"url": "..."}`)                                                          |

`recipe.json`-Schema pro Rezept:

```json
{
  "title": "Leberknödelsuppe",
  "slug": "leberknoedelsuppe",
  "category": "Suppen",
  "servings": "für 4 Personen",
  "order": 4,
  "created": "2026-08-12T17:04:58+02:00",
  "image": "image.jpg",
  "thumb": "thumb.jpg",
  "ingredients": [
    { "group": null, "text": ["..."] },
    { "group": "Für die Knödel", "text": ["...", "..."] }
  ],
  "steps": ["..."],
  "notes": []
}
```

## Frontend / Arbeitsweise

### Token-Authentifizierung

Für Änderungen (Rezept anlegen/bearbeiten/löschen, Bild-Upload) fragt das Frontend beim ersten
Schreibversuch per `prompt()` nach dem API-Token und merkt es sich in `localStorage`.

Beim ersten Schreibversuch (Anlegen/Bearbeiten/Löschen/Upload) fragt das Frontend per prompt() nach dem Token und legt 
es danach in `localStorage` unter dem Schlüssel `kochbuch_api_token` ab. Jeder weitere Schreibversuch liest das 
gespeicherte Token wieder aus und fragt nicht erneut nach.

Es wird nur dann wieder nachgefragt, wenn entweder:
- der Server das gespeicherte Token ablehnt (401) – dann wird es automatisch aus `localStorage` gelöscht, oder
- du es manuell löschst, z. B. in den Browser-DevTools mit `localStorage.removeItem('kochbuch_api_token')` oder über 
  "Website-Daten löschen".

### Import-Funktion

Der "Importieren"-Button neben "+ Neues Rezept" öffnet ein Formular für eine URL und ruft
`POST /api/import.php` auf (`{"url": "..."}`, Token nötig). Der Endpunkt nutzt eine kleine
Parser-Registry (`api/lib/parsers/`): anhand der URL wird ein zuständiger `RecipeParserInterface`
gesucht (`supports(url): bool`) und mit `parse(url): array` ausgelesen.

- **`ChefkochParser`**: zuständig für `chefkoch.de`.
- **`GenericSchemaOrgParser`**: Fallback für jede andere Seite (`supports()` liefert immer
  `true`, muss daher als letztes in der Registry stehen) – funktioniert überall dort, wo
  `schema.org/Recipe`-JSON-LD eingebettet ist (z. B. wie bei nextcloud/cookbook üblich).

Beide Parser nutzen dieselbe Extraktion (`schema_org.php`): sie durchsuchen alle
`<script type="application/ld+json">`-Blöcke der Seite, auch verschachtelt in einer
`@graph`-Struktur (so bei chefkoch.de), lösen `@id`-Referenzen auf (Bild, Autor) auf und wandeln
`recipeInstructions` unabhängig von der Form (String, HowToStep-Liste, verschachtelte
HowToSection wie bei chefkoch.de) in eine flache Schritt-Liste um. Ein an den Titel angehängter
Autorenname (z. B. chefkoch.de: `"... von PicassosWelt"`) wird nur entfernt, wenn er exakt mit
dem über `author` verlinkten Namen übereinstimmt (kein Raten anhand von Textmustern) – Titel wie
"Involtini von Huhn" bleiben also unangetastet. Die `description` wird bewusst **nicht** als
Notiz übernommen, da dort bei chefkoch.de u. a. reiner SEO-Marketingtext steht.

Neue Portale: einfach `RecipeParserInterface` implementieren und in der `$parsers`-Registry in
`import.php` eintragen (vor dem `GenericSchemaOrgParser`-Fallback) – an `import.php` selbst
ändert sich dabei nichts.

Gespeichert wird über dieselbe Validierungs-/Anlege-Funktion wie `POST /api/recipes.php`
(`create_recipe()` aus `crud.php`) – es gibt also nur einen Validierungsweg, keinen separaten
für Importe. Das Titelbild wird danach herunterergeladen und über `store_recipe_image()`
gespeichert (dieselbe Funktion wie beim manuellen Bild-Upload); schlägt nur das fehl, bleibt das
Rezept trotzdem gespeichert (Antwort enthält dann zusätzlich `"import_warning"`).

`http_get()` (`fetch.php`) nutzt die curl-Extension, falls vorhanden, sonst `file_get_contents()`
mit Stream-Context als Fallback. Das ist mehr als reine Portabilität: manche Seiten blocken
PHPs `file_get_contents()`-Stream-Wrapper als Bot, akzeptieren curl-Anfragen mit realistischeren
Headern aber anstandslos.

### Druckfunktion

Rein clientseitig über `@media print`-CSS, kein PHP-PDF-Generator. Der "Drucken"-Button in der
Rezept-Detailansicht ruft `printRecipe()` auf; die Print-Styles blenden Header, Suchleiste,
Kartenraster und alle Formular-/Aktions-Buttons aus und lassen nur das offene Rezept übrig
(Foto oben links, Titel/Portionen oben rechts, Zutaten als Liste, Schritte nummeriert –
angelehnt an die ursprüngliche PDF-Vorlage). Der Browser-Dialog "Drucken → Als PDF speichern"
reicht für einen PDF-Export.

**Zweispaltige Zutaten bei langen Rezepten:** `printRecipe()` rendert den Rezeptinhalt vor dem
eigentlichen Druck unsichtbar in der ungefähren Breite/Höhe einer A4-Druckseite
(`measurePrintHeight()`) und schätzt so ab, ob es auf eine Seite passt. Falls nicht, wird die
Zutatenliste für den Ausdruck zweispaltig dargestellt (`.ingredients-wrap.two-col`, nur unter
`@media print` wirksam), um mehr auf die erste Seite zu bekommen – z. B. bei "Peking Ente".
Das ist eine Annäherung (Messung im Bildschirm-DOM, nicht die tatsächliche Browser-Paginierung),
kein Garant für exakt eine Seite bei sehr langen Rezepten.

## Stand / Roadmap

- ✅ Datenmigration aus dem OneNote-Export (100 Rezepte)
- ✅ JSON-API (CRUD + Bild-Upload mit automatischem Thumbnail)
- ✅ Frontend auf die API umgestellt, inkl. Anlegen/Bearbeiten/Löschen
- ✅ Druckansicht (eine A4-Seite pro Rezept, `@media print`)
- ✅ Rezept-Import per URL (chefkoch.de u. Ä. über `schema.org/Recipe`-JSON-LD)

## Lizenz

Dieses Projekt steht unter der [GPL-3.0-Lizenz](LICENSE).

Das bedeutet: Du darfst diesen Code nutzen, ändern und verbreiten. Wenn du die Software (oder eine modifizierte Version
davon) jedoch weitergibst, musst du deinen Quellcode ebenfalls unter derselben Lizenz offenlegen.
