<?php

/**
 * JSON-API für Rezepte: Listen/Lesen, Anlegen, Bearbeiten (inkl. Rename bei Titeländerung)
 * und Löschen. Bild-Upload läuft separat über upload.php.
 *
 * GET    /api/recipes.php            -> {"recipes": [...]}, alle Rezepte, alphabetisch nach Titel sortiert. Kein Token nötig.
 * GET    /api/recipes.php?slug=xyz   -> einzelnes Rezept. Kein Token nötig. 404, falls unbekannt.
 * POST   /api/recipes.php            -> legt ein neues Rezept an. Body: JSON-Objekt mit title (Pflicht),
 *                                        category, servings, ingredients, steps, notes. Token nötig. 201 bei Erfolg.
 * PUT    /api/recipes.php?slug=xyz   -> aktualisiert ein Rezept (gleicher Body wie POST). Ändert sich der Titel
 *                                        so, dass sich der Slug ändert, wird der Ordner umbenannt. Token nötig.
 * DELETE /api/recipes.php?slug=xyz   -> löscht das Rezept samt Ordner (Bilder inklusive). Token nötig.
 *
 * Token wird per Header X-API-Token erwartet (siehe api/lib/http.php: require_token()).
 * Die eigentliche Lese-/Schreib-/Rename-/Delete-Logik steckt in api/lib/crud.php.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/crud.php';

$method = $_SERVER['REQUEST_METHOD'];
$slug = isset($_GET['slug']) ? (string) $_GET['slug'] : null;

try {
    switch ($method) {
        case 'GET':
            if ($slug !== null) {
                $recipe = get_recipe($slug);
                if ($recipe === null) {
                    json_error(404, "Rezept '$slug' nicht gefunden");
                }
                json_response(200, $recipe);
            }
            json_response(200, ['recipes' => list_recipes()]);

        case 'POST':
            require_token();
            $data = read_json_body();
            $recipe = create_recipe($data);
            json_response(201, $recipe);

        case 'PUT':
            require_token();
            if ($slug === null) {
                json_error(400, 'slug-Parameter erforderlich');
            }
            $data = read_json_body();
            $recipe = update_recipe($slug, $data);
            json_response(200, $recipe);

        case 'DELETE':
            require_token();
            if ($slug === null) {
                json_error(400, 'slug-Parameter erforderlich');
            }
            delete_recipe($slug);
            json_response(200, ['deleted' => $slug]);

        default:
            json_error(405, "Methode '$method' nicht erlaubt");
    }
} catch (ValidationException $e) {
    json_response(400, ['error' => 'Validierung fehlgeschlagen', 'details' => $e->errors]);
} catch (NotFoundException $e) {
    json_error(404, $e->getMessage());
} catch (ConflictException $e) {
    json_error(409, $e->getMessage());
} catch (RuntimeException $e) {
    // Fängt u. a. fehlgeschlagenes mkdir()/rename() (create_recipe()/update_recipe()) und
    // fehlgeschlagenes unlink()/rmdir() (delete_recipe()) ab, statt eines rohen PHP-Fatals mit
    // kaputtem JSON-Body (siehe Kommentar an remove_directory_recursive() in crud.php).
    json_error(500, $e->getMessage());
}
