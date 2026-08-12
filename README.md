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
4. Seite aufrufen – die Rezeptliste lädt sich selbst über `GET /api/recipes.php`.

Für Änderungen (Rezept anlegen/bearbeiten/löschen, Bild-Upload) fragt das Frontend beim ersten
Schreibversuch per `prompt()` nach dem API-Token und merkt es sich in `localStorage`.

### GD unter XAMPP aktivieren

Testdatei:
```php
<?php
// Nur zur manuellen Prüfung der PHP-Konfiguration (z. B. GD-Verfügbarkeit) nach dem Deploy.
// Danach wieder löschen - phpinfo() gibt Server-Interna preis.
phpinfo();
```

`php_gd.dll` liegt XAMPP standardmäßig bei, ist aber in `php.ini` auskommentiert. So aktivieren:

1. `C:\xampp\php\php.ini` öffnen und die Zeile `;extension=gd` zu `extension=gd` ändern.
2. Apache neu starten, damit die Änderung greift.

Auf dem finalen Hetzner-Webspace ist GD standardmäßig aktiviert. Falls nicht, kann dies per KonsoleH 
eingerichtet werden.

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
    └── lib/
        ├── crud.php         Read/Write/Rename/Delete-Logik + Validierung
        ├── slug.php         slugify() + Eindeutigkeits-Check
        ├── thumbnail.php    Thumbnail-Erzeugung per GD (Center-Crop) + Lazy-Fallback
        └── http.php         JSON-Response- und Token-Check-Helper
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

## Stand / Roadmap

- ✅ Datenmigration aus dem OneNote-Export (100 Rezepte)
- ✅ JSON-API (CRUD + Bild-Upload mit automatischem Thumbnail)
- ✅ Frontend auf die API umgestellt, inkl. Anlegen/Bearbeiten/Löschen
- ⏳ Druckansicht (eine A4-Seite pro Rezept, `@media print`)
- ⏳ Rezept-Import per URL (chefkoch.de u. Ä. über `schema.org/Recipe`-JSON-LD)

## Lizenz

Dieses Projekt steht unter der [GPL-3.0-Lizenz](LICENSE).

Das bedeutet: Du darfst diesen Code nutzen, ändern und verbreiten. Wenn du die Software (oder eine modifizierte Version
davon) jedoch weitergibst, musst du deinen Quellcode ebenfalls unter derselben Lizenz offenlegen.
