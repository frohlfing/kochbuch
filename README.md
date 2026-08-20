# Kochbuch

Ein privates Familien-Kochbuch, digitalisiert als kleine PHP/JSON-API mit einer statischen One-Page-Frontend-App.

## Voraussetzungen

- PHP 8.5+ mit aktivierter **GD**-Extension (für Thumbnails)
- Ein Webserver mit `public/` als Document Root
- Kein Datenbankserver nötig – alle Daten liegen als Dateien unter `data/`, bewusst außerhalb des Document Root 
  (Zugriff nur über `api/recipes.php` bzw. `api/image.php`, nicht direkt per URL)

## Setup

1. `config.example.php` nach `config.php` kopieren (liegt im Projekt-Root, **nicht** unter `public/`, damit es 
   außerhalb des Document Root und damit nicht direkt aufrufbar ist).

2. In `config.php` ein echtes `API_TOKEN` eintragen, z. B.:
   ```
   php -r "echo bin2hex(random_bytes(32));"
   ```
3. Webserver mit Document Root `public/` auf ein Verzeichnis zeigen lassen (z. B. XAMPP-vHost).

4. GD-Extension für PHP aktivieren. Zur Prüfung gibt es `public/dev/info.php` (ruft `phpinfo()` auf, im Browser 
   aufrufen und nach "gd" suchen) – siehe auch Abschnitt "Admin-/Dev-Skripte" unter Projektstruktur.

   Unter XAMPP:
   - `C:\xampp\php\php.ini` öffnen und die Zeile `;extension=gd` zu `extension=gd` ändern.
   - Apache neu starten.

   Unter Hetzner-Webspace:
   - GD ist standardmäßig aktiviert. Falls nicht, kann dies per KonsoleH eingerichtet werden.

5. **HTTP Basic Auth einrichten** (schützt zusätzlich zum API-Token auch die statische `index.html` selbst – ohne 
   diese Auth wäre die leere Seiten-Hülle trotz Token-Schutz der API weiterhin für jeden abrufbar):
   ```
   htpasswd -bcB .htpasswd <benutzername> <passwort>
   ```
   Die `.htpasswd`-Datei liegt im Projekt-Root (außerhalb des Document Root, neben `config.php`). Danach 
  `public/.htaccess.example` nach `public/.htaccess` kopieren und den `AuthUserFile`-Pfad auf den tatsächlichen 
   absoluten Pfad zur `.htpasswd` auf diesem Server anpassen. Beide Dateien (`.htpasswd`, `public/.htaccess`) sind 
   gitignored und müssen auf jedem Rechner/Server separat eingerichtet werden.

6. Seite aufrufen – die Rezeptliste lädt sich selbst über `GET /api/recipes.php`.

## Lokalen Testserver unter XAMPP (Windows) einrichten

1. URL in die Hosts-Datei eintragen

    `C:\Windows\System32\drivers\etc\hosts` als Administrator öffnen:
    
    ```
    127.0.0.1      kochbuch.test
    ```

2. SSL-Zertifikat installieren
    ```shell
    cd C:\xampp\apache\bin
    
    .\openssl.exe req -x509 -nodes -newkey rsa:2048 -sha256 `
     -keyout "C:\xampp\apache\conf\ssl.key\kochbuch.test.key" `
    -out "C:\xampp\apache\conf\ssl.crt\kochbuch.test.crt" `
     -days 3650 `
    -config "C:\xampp\apache\conf\openssl.cnf" `
     -subj "/CN=kochbuch.test" `
    -addext "basicConstraints=CA:FALSE" `
    -addext "subjectAltName=DNS:kochbuch.test"
    ```

    Apache neu starten!

3. VirtualHost hinzufügen

    Datei `C:\xampp\apache\conf\extra\httpd-vhosts.conf` bearbeiten:
    
    ```
    <VirtualHost *:80>
        ServerName kochbuch.test
        ServerAlias 192.168.178.21
    
        DocumentRoot "C:/Users/frank/Source/PhpStorm/kochbuch/public"
    
        <Directory "C:/Users/frank/Source/PhpStorm/kochbuch/public">
            AllowOverride All
            Require all granted
        </Directory>
    </VirtualHost>
    
    <VirtualHost *:443>
        ServerName kochbuch.test
    
        DocumentRoot "C:/Users/frank/Source/PhpStorm/kochbuch/public"
    
        SSLEngine on
        SSLCertificateFile "C:/xampp/apache/conf/ssl.crt/kochbuch.test.crt"
        SSLCertificateKeyFile "C:/xampp/apache/conf/ssl.key/kochbuch.test.key"
    
        <Directory "C:/Users/frank/Source/PhpStorm/kochbuch/public">
            AllowOverride All
            Require all granted
        </Directory>
    </VirtualHost>
    ```
    
    Apache neu starten!

4. SSL-Zertifikat in den Browser-Zertifikatsspeicher importieren:

    Damit der Browser dem selbstsignierten Zertifikat vertraut, muss es in den Browser-Zertifikatsspeicher importiert 
    werden:
    - Doppelklick auf `C:\xampp\apache\conf\ssl.crt\kochbuch.test.crt`
        - Zertifikat installieren...,
            - Lokaler Computer
            - Zertifikatsspeicher: Vertrauenswürdige Stammzertifizierungsstellen
    
    Browser neu starten!
    
    Die Homepage ist jetzt erreichbar unter: https://kochbuch.test/

## Projektstruktur

```
kochbuch/                                             # Projektroot
 ├── data/                                            # Außerhalb des Document Root, kein direkter Browser-Zugriff möglich
 │    └── recipes/                                    # Ein Unterordner pro Rezept
 │         └── <slug>/                                # Ein Ordner pro Rezept
 │              ├── recipe.json                       # Rezeptdaten (Titel, Zutaten, Schritte, ...) – Schema siehe Abschnitt "API"
 │              ├── image.<ext>                       # Original-Upload
 │              └── thumb.<ext>                       # automatisch generierte Miniatur
 ├── docs/                                            # Projektdokumentation (Markdown)
 ├── public/                                          # Document Root
 │    ├── api/                                        # API-Endpunkte
 │    │    └── lib/                                   # Gemeinsam genutzte Hilfsfunktionen der API-Endpunkte
 │    │    │    ├── crud.php                          # Read/Write/Rename/Delete-Logik + Validierung
 │    │    │    ├── http.php                          # JSON-Response- und Token-Check-Helper
 │    │    │    ├── image_store.php                   # Bild validieren/speichern + Thumbnail (gemeinsam für upload.php und import.php)
 │    │    │    ├── parsers/                          # Parser-Registry für den Rezept-Import (siehe Abschnitt "Import-Funktion")
 │    │    │    │    ├── ChefkochParser.php           # erkennt/parst chefkoch.de
 │    │    │    │    ├── fetch.php                    # http_get(): curl, sonst file_get_contents()-Fallback
 │    │    │    │    ├── GenericSchemaOrgParser.php   # Fallback für jede Seite mit schema.org/Recipe
 │    │    │    │    ├── RecipeParserInterface.php    # Vertrag: supports(url), parse(url)
 │    │    │    │    └── schema_org.php               # Gemeinsame JSON-LD-Extraktion (auch @graph-Strukturen)
 │    │    │    ├── slug.php                          # slugify() + Eindeutigkeits-Check
 │    │    │    └── thumbnail.php                     # Thumbnail-Erzeugung per GD (Center-Crop) + Lazy-Fallback
 │    │    ├── image.php                              # Liefert die Bilddatei
 │    │    ├── import.php                             # Rezept von externer URL importieren
 │    │    ├── recipes.php                            # Rezepte lesen, anlegen, bearbeiten, löschen
 │    │    └── upload.php                             # Bild-Upload für ein Rezept inkl. Thumbnail-Generierung
 │    ├── dev/                                        # Admin-/Wartungsskripte – NICHT dauerhaft auf dem Produktivserver lassen (siehe unten)
 │    ├── .htaccess                                   # Apache Zugriffsschutz
 │    ├── .htaccess.example                           # Vorlage für Apache Zugriffsschutz
 │    ├── app.css                                     # CSS-Styles für das Frontend
 │    ├── app.js                                      # JavaScript für das Frontend
 │    ├── favicon.svg                                 # Browser-Tab-Icon
 │    └── index.html                                  # Frontend (lädt/schreibt ausschließlich über /api/*)
 ├── .gitignore                                       # Vom Git-Repository auszuschließende Dateien
 ├── .htpasswd                                        # Apache Passwortdatei (nicht im Git-Repository)
 ├── config.example.php                               # Vorlage für config.php
 ├── config.php                                       # Zentrale Konfigurationsdatei (nicht im Git-Repository)
 ├── LICENSE                                          # Lizenzhinweis   
 └── README.md                                        # Landingpage für das Git-Repository
```

### Admin-/Dev-Skripte (`public/dev/`)

Einmalige Wartungs- und Diagnose-Skripte, die (anders als die eigentliche App) direkt im Browser aufgerufen werden.
Auf Hetzner-Shared-Webspace gibt es keinen Terminalzugriff, ein CLI-Pendant entfällt daher bewusst. 

**Wichtig:** `public/dev/` gehört **nicht dauerhaft** auf den Produktivserver. Nach Gebrauch dort löschen – ein 
Server-Interna preisgebender `phpinfo()`-Endpunkt und ein Token im URL-Query (landet in Server-Logs/Browser-Verlauf) 
sollen keine Dauereinrichtung sein.

## API

Alle schreibenden Endpunkte (`POST`/`PUT`/`DELETE`) erwarten den Header `X-API-Token: <token>`, sonst `401`. 
Lesende Endpunkte (`GET`) sind ohne eigenes Token offen — Schutz vor öffentlichem Zugriff übernimmt stattdessen 
die HTTP Basic Auth vor der gesamten `public/`-Domain (siehe Setup, Schritt 5), die auch die statische `index.html` 
selbst mit abdeckt.

| Methode | Endpunkt                                     | Beschreibung                                                                                                                 |
|---------|----------------------------------------------|------------------------------------------------------------------------------------------------------------------------------|
| GET     | `/api/recipes.php`                           | Alle Rezepte, alphabetisch nach Titel sortiert (das Frontend sortiert clientseitig ggf. um)                                  |
| GET     | `/api/recipes.php?slug=xyz`                  | Einzelnes Rezept                                                                                                             |
| POST    | `/api/recipes.php`                           | Neues Rezept anlegen (JSON-Body)                                                                                             |
| PUT     | `/api/recipes.php?slug=xyz`                  | Rezept bearbeiten (JSON-Body). Ändert sich der Titel so, dass sich der Slug ändert, wird der Rezeptordner atomar umbenannt.  |
| DELETE  | `/api/recipes.php?slug=xyz`                  | Rezept samt Ordner (Bilder inklusive) löschen                                                                                |
| POST    | `/api/upload.php`                            | Bild für ein Rezept hochladen (`multipart/form-data`: `slug`, `image`)                                                       |
| GET     | `/api/image.php?slug=xyz&type=image\|thumb`  | Original- bzw. Thumbnail-Bild ausliefern                                                                                     |
| POST    | `/api/import.php`                            | Rezept von einer externen URL importieren (Body: `{"url": "..."}`)                                                           |

`recipe.json`-Schema pro Rezept:

```json
{
  "title": "Leberknödelsuppe",
  "slug": "leberknoedelsuppe",
  "category": "Suppen",
  "servings": "für 4 Personen",
  "created": "2026-08-12T17:04:58+02:00",
  "image": "image.jpg",
  "thumb": "thumb.jpg",
  "ingredients": [
    { "group": null, "text": ["..."] },
    { "group": "Für die Knödel", "text": ["...", "..."] }
  ],
  "steps": ["..."],
  "notes": null,
  "twoColumnPrint": false
}
```

## Frontend / Arbeitsweise

### Request-Ablauf beim Seitenaufruf

Beim Aufruf von `index.html` laufen die Requests in dieser Reihenfolge:

1. **Statische Assets** (durch den Browser beim HTML-Parsing): `favicon.svg`, `app.css`, danach `app.js` (steht am 
   Ende von `<body>`, lädt/läuft also erst nach dem übrigen HTML).
2. Sobald `app.js` ausgeführt wird, startet sofort `init()` (kein Warten auf `DOMContentLoaded` nötig, da das Script 
   ohnehin erst am Body-Ende geladen wird):
   - **`GET api/recipes.php`** lädt die komplette Rezeptliste als JSON. Das ist der einzige zwingende Request beim 
     Start; schlägt er fehl, wird eine Fehlermeldung angezeigt und die Initialisierung bricht ab.
   - Beim Rendern des Kartenrasters bekommt jedes Rezept mit Bild ein
     `<img src="api/image.php?slug=…&type=thumb" loading="lazy">`. Dadurch fordert der Browser die Thumbnails an, 
     aber wegen `loading="lazy"` nur die Thumbnails, die tatsächlich (nahe) im sichtbaren Viewport liegen; der Rest 
     folgt erst beim Scrollen. Die Originalbilder (`type=image`) werden beim Öffnen eines einzelnen Rezepts 
     nachgeladen.

Kein Token-Request/Prompt beim Laden: `requireToken()` wird ausschließlich bei schreibenden Aktionen (Speichern/
Löschen/Importieren) aufgerufen, siehe nächsten Abschnitt.

### Token-Authentifizierung

Für Änderungen (Rezept anlegen/bearbeiten/löschen, Bild-Upload) fragt das Frontend beim ersten Schreibversuch per 
`prompt()` nach dem API-Token und legt es danach in `localStorage` unter dem Schlüssel `kochbuch_api_token` ab. 
Jeder weitere Schreibversuch liest das gespeicherte Token wieder aus und fragt nicht erneut nach.

Es wird nur dann wieder nachgefragt, wenn 
a) der Server das gespeicherte Token ablehnt (401) – dann wird es automatisch aus `localStorage` gelöscht, oder 
b) du es manuell löschst, z. B. in den Browser-DevTools mit `localStorage.removeItem('kochbuch_api_token')` oder über 
   "Website-Daten löschen".

Lesezugriffe (Rezeptliste, Bilder) brauchen kein App-Token — Schutz vor öffentlichem Zugriff übernimmt die 
HTTP Basic Auth vor der gesamten `public/`-Domain (siehe Setup, Schritt 5).

### Import-Funktion

Der "Importieren"-Button neben "+ Neues Rezept" öffnet ein Formular für eine URL und ruft `POST /api/import.php` auf 
(`{"url": "..."}`, Token nötig). Der Endpunkt nutzt eine kleine Parser-Registry (`api/lib/parsers/`): anhand der URL 
wird ein zuständiger `RecipeParserInterface` gesucht (`supports(url): bool`) und mit `parse(url): array` ausgelesen.

- **`ChefkochParser`**: zuständig für `chefkoch.de`.
- **`GenericSchemaOrgParser`**: Fallback für jede andere Seite (`supports()` liefert immer `true`, muss daher als
  letztes in der Registry stehen) – funktioniert überall dort, wo `schema.org/Recipe`-JSON-LD eingebettet ist (z. B.
  wie bei nextcloud/cookbook üblich).

Beide Parser nutzen dieselbe Extraktion (`schema_org.php`): sie durchsuchen alle `<script
type="application/ld+json">`-Blöcke der Seite, auch verschachtelt in einer `@graph`-Struktur (so bei chefkoch.de),
lösen `@id`-Referenzen auf (Bild, Autor) auf und wandeln `recipeInstructions` unabhängig von der Form (String,
HowToStep-Liste, verschachtelte HowToSection wie bei chefkoch.de) in eine flache Schritt-Liste um. Ein an den Titel
angehängter Autorenname (z. B. chefkoch.de: `"... von PicassosWelt"`) wird nur entfernt, wenn er exakt mit dem über
`author` verlinkten Namen übereinstimmt (kein Raten anhand von Textmustern) – Titel wie "Involtini von Huhn" bleiben
also unangetastet. Die `description` wird bewusst **nicht** als Notiz übernommen, da dort bei chefkoch.de u. a. reiner
SEO-Marketingtext steht.

Neue Portale: einfach `RecipeParserInterface` implementieren und in der `$parsers`-Registry in `import.php` eintragen
(vor dem `GenericSchemaOrgParser`-Fallback) – an `import.php` selbst ändert sich dabei nichts.

Gespeichert wird über dieselbe Validierungs-/Anlege-Funktion wie `POST /api/recipes.php` (`create_recipe()` aus
`crud.php`) – es gibt also nur einen Validierungsweg, keinen separaten für Importe. Das Titelbild wird danach
herunterergeladen und über `store_recipe_image()` gespeichert (dieselbe Funktion wie beim manuellen Bild-Upload);
schlägt nur das fehl, bleibt das Rezept trotzdem gespeichert (Antwort enthält dann zusätzlich `"import_warning"`).

`http_get()` (`fetch.php`) nutzt die curl-Extension, falls vorhanden, sonst `file_get_contents()` mit Stream-Context
als Fallback. Das ist mehr als reine Portabilität: Manche Seiten blocken PHPs `file_get_contents()`-Stream-Wrapper als
Bot, akzeptieren curl-Anfragen mit realistischeren Headern aber anstandslos.

### Druckfunktion

Rein clientseitig über `@media print`-CSS, kein PHP-PDF-Generator. Der "Drucken"-Button in der Rezept-Detailansicht
ruft `printRecipe()` auf; die Print-Styles blenden Header, Suchleiste, Kartenraster und alle Formular-/Aktions-Buttons
aus und lassen nur das offene Rezept übrig (Foto oben links, Titel/Portionen oben rechts, Zutaten als Liste, Schritte
nummeriert – angelehnt an die ursprüngliche PDF-Vorlage). Der Browser-Dialog "Drucken → Als PDF speichern" reicht für
einen PDF-Export.

**Zweispaltige Zutaten bei langen Rezepten:** Im Bearbeitungsformular gibt es die Checkbox "Zutaten beim Drucken immer
zweispaltig darstellen" (Feld `twoColumnPrint` in `recipe.json`, Default `false`). Ist sie gesetzt, bekommt
`#ingredientsWrap` beim Drucken die Klasse `two-col` (`.ingredients-wrap.two-col{columns:2}`, nur unter `@media print`
wirksam) – reine manuelle Entscheidung, keine Automatik mehr. Zwei automatische Varianten (eine Pixel-Messung im
unsichtbaren DOM, danach eine Zeilen-/Zeichen-Schätzung) wurden ausprobiert und wieder verworfen: Ohne echten Browser
ließ sich keine davon zuverlässig verifizieren, und in der Praxis wurde z. B. "Peking Ente" nicht zuverlässig erkannt.
Bei Bedarf lässt sich anhand des bestehenden Datenbestands leicht eine Liste von Kandidaten ermitteln (Zutaten- +
Schritt-Umfang zählen) und manuell durchgehen, statt sich auf eine unsichere Schätzung zu verlassen.

## Stand / Roadmap

- ✅ Datenmigration aus dem OneNote-Export (100 Rezepte)
- ✅ JSON-API (CRUD + Bild-Upload mit automatischem Thumbnail)
- ✅ Frontend auf die API umgestellt, inkl. Anlegen/Bearbeiten/Löschen
- ✅ Druckansicht (eine A4-Seite pro Rezept, `@media print`)
- ✅ Rezept-Import per URL (chefkoch.de u. Ä. über `schema.org/Recipe`-JSON-LD)

## Lizenz

Dieses Projekt steht unter der [GPL-3.0-Lizenz](LICENSE).

Das bedeutet: Du darfst diesen Code nutzen, ändern und verbreiten. Wenn du die Software (oder eine modifizierte
Version davon) jedoch weitergibst, musst du deinen Quellcode ebenfalls unter derselben Lizenz offenlegen.
