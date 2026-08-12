<?php

/**
 * Importiert ein Rezept von einer externen URL. Sucht anhand der URL in einer kleinen
 * Parser-Registry den zuständigen Parser (aktuell: ChefkochParser, Fallback für alle anderen
 * Seiten: GenericSchemaOrgParser, siehe api/lib/parsers/). Die gefundenen Felder werden über
 * dieselbe Validierungs-/Anlege-Logik wie POST /api/recipes.php gespeichert (create_recipe()
 * aus crud.php) – es gibt also nur einen Validierungsweg, keinen separaten für Importe. Das
 * Titelbild wird danach, falls gefunden, automatisch heruntergeladen; schlägt nur das fehl,
 * bleibt das Rezept trotzdem gespeichert (Antwort enthält dann zusätzlich "import_warning").
 *
 * POST /api/import.php
 *   Body: {"url": "https://www.chefkoch.de/rezepte/..."}. Token nötig (Header X-API-Token).
 *   201 mit dem neu angelegten Rezept bei Erfolg.
 *
 * Neue Portale: einfach RecipeParserInterface implementieren und unten in $parsers eintragen
 * (vor dem GenericSchemaOrgParser-Fallback) – an diesem Endpunkt selbst ändert sich nichts.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/crud.php';
require_once __DIR__ . '/lib/image_store.php';
require_once __DIR__ . '/lib/parsers/RecipeParserInterface.php';
require_once __DIR__ . '/lib/parsers/fetch.php';
require_once __DIR__ . '/lib/parsers/schema_org.php';
require_once __DIR__ . '/lib/parsers/ChefkochParser.php';
require_once __DIR__ . '/lib/parsers/GenericSchemaOrgParser.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error(405, 'Nur POST erlaubt');
}

require_token();

$body = read_json_body();
$url = trim((string) ($body['url'] ?? ''));
if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
    json_error(400, 'Gültige url ist erforderlich');
}

$parsers = [
    new ChefkochParser(),
    new GenericSchemaOrgParser(), // Fallback, muss zuletzt stehen (supports() ist immer true)
];

$parser = null;
foreach ($parsers as $candidate) {
    if ($candidate->supports($url)) {
        $parser = $candidate;
        break;
    }
}
if ($parser === null) {
    json_error(422, 'Kein passender Parser gefunden');
}

try {
    $parsed = $parser->parse($url);
} catch (Throwable $e) {
    json_error(422, 'Import fehlgeschlagen: ' . $e->getMessage());
}

$data = [
    'title' => $parsed['title'],
    'category' => $parsed['category'],
    'servings' => $parsed['servings'],
    // schema.org kennt keine Zutaten-Gruppen -> alles in eine ungruppierte Liste (group: null)
    'ingredients' => $parsed['ingredients'] ? [['group' => null, 'text' => $parsed['ingredients']]] : [],
    'steps' => $parsed['steps'],
    'notes' => $parsed['notes'],
];

try {
    $recipe = create_recipe($data);
} catch (ValidationException $e) {
    json_response(400, ['error' => 'Validierung fehlgeschlagen', 'details' => $e->errors]);
}

$importWarning = null;
if (!empty($parsed['image_url'])) {
    $tmpFile = tempnam(sys_get_temp_dir(), 'kbimport_');
    try {
        file_put_contents($tmpFile, http_get($parsed['image_url']));
        $recipe = store_recipe_image($recipe['slug'], $tmpFile);
    } catch (Throwable $e) {
        $importWarning = 'Rezept importiert, aber Bild konnte nicht geladen werden: ' . $e->getMessage();
    }
    @unlink($tmpFile);
}

json_response(201, $importWarning !== null ? array_merge($recipe, ['import_warning' => $importWarning]) : $recipe);
