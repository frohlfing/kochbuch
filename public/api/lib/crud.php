<?php

require_once __DIR__ . '/slug.php';
require_once __DIR__ . '/thumbnail.php';

class ValidationException extends RuntimeException
{
    /** @param string[] $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode('; ', $errors));
    }
}

/** Rezept-Slug existiert nicht. */
class NotFoundException extends RuntimeException
{
}

/** Ziel-Slug beim Rename ist bereits belegt. */
class ConflictException extends RuntimeException
{
}

/** Absoluter Pfad zum Ordner eines Rezepts (existiert nicht zwingend). */
function recipe_dir(string $slug): string
{
    return RECIPES_DIR . '/' . $slug;
}

/** Liest und dekodiert recipe.json eines Rezepts. Null, wenn Ordner/Datei fehlt oder JSON ungültig ist. */
function read_recipe_json(string $slug): ?array
{
    $path = recipe_dir($slug) . '/recipe.json';
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

/** Schreibt $recipe als recipe.json (überschreibt eine vorhandene Datei vollständig). */
function write_recipe_json(string $slug, array $recipe): void
{
    file_put_contents(
        recipe_dir($slug) . '/recipe.json',
        json_encode($recipe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n"
    );
}

/**
 * Alle Unterordner von RECIPES_DIR (= alle Slugs), unsortiert. Leeres Array, falls RECIPES_DIR
 * noch gar nicht existiert (frische Installation, bevor das erste Rezept angelegt wurde).
 * @return string[]
 */
function list_recipe_slugs(): array
{
    if (!is_dir(RECIPES_DIR)) {
        return [];
    }
    $slugs = [];
    foreach (scandir(RECIPES_DIR) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..' && is_dir(RECIPES_DIR . '/' . $entry)) {
            $slugs[] = $entry;
        }
    }
    return $slugs;
}

/** Liest ein einzelnes Rezept und stellt dabei sicher, dass sein Thumbnail existiert (Lazy-Fallback). Null, wenn nicht gefunden. */
function get_recipe(string $slug): ?array
{
    $recipe = read_recipe_json($slug);
    if ($recipe === null) {
        return null;
    }
    return ensure_thumbnail(recipe_dir($slug), $recipe);
}

/** @return array[] Alphabetisch nach Titel sortiert (das Frontend sortiert clientseitig ggf. um, siehe index.html). */
function list_recipes(): array
{
    $recipes = [];
    foreach (list_recipe_slugs() as $slug) {
        $recipe = get_recipe($slug);
        if ($recipe !== null) {
            $recipes[] = $recipe;
        }
    }
    usort($recipes, fn(array $a, array $b) => strcasecmp((string) $a['title'], (string) $b['title']));
    return $recipes;
}

/** Case-insensitiver Titel-Vergleich über alle Rezepte. $excludeSlug schließt das eigene Rezept beim Update aus. */
function title_exists(string $title, ?string $excludeSlug = null): bool
{
    $needle = mb_strtolower(trim($title));
    foreach (list_recipe_slugs() as $slug) {
        if ($slug === $excludeSlug) {
            continue;
        }
        $r = read_recipe_json($slug);
        if ($r !== null && mb_strtolower(trim((string) $r['title'])) === $needle) {
            return true;
        }
    }
    return false;
}

/** @return string[] Fehlermeldungen, leer wenn valide. */
function validate_recipe_input(array $data): array
{
    $errors = [];

    if (trim((string) ($data['title'] ?? '')) === '') {
        $errors[] = 'title ist erforderlich';
    }
    foreach (['ingredients', 'steps'] as $field) {
        if (isset($data[$field]) && !is_array($data[$field])) {
            $errors[] = "$field muss ein Array sein";
        }
    }
    if (isset($data['notes']) && $data['notes'] !== null && !is_string($data['notes'])) {
        $errors[] = 'notes muss ein Text sein';
    }
    foreach ($data['ingredients'] ?? [] as $group) {
        if (!is_array($group) || !array_key_exists('group', $group) || !isset($group['text']) || !is_array($group['text'])) {
            $errors[] = 'ingredients: jede Gruppe braucht die Felder group (nullable) und text (Array)';
            break;
        }
    }

    return $errors;
}

/** Baut aus rohen Request-Daten die vom Store verwalteten Felder (title/category/servings/ingredients/steps/notes/twoColumnPrint), mit Defaults/Typumwandlung. */
function normalize_recipe_fields(array $data): array
{
    $notes = trim((string) ($data['notes'] ?? ''));

    return [
        'title' => trim((string) ($data['title'] ?? '')),
        'category' => !empty($data['category']) ? (string) $data['category'] : null,
        'servings' => !empty($data['servings']) ? (string) $data['servings'] : null,
        'ingredients' => array_map(
            fn(array $g) => [
                'group' => !empty($g['group']) ? (string) $g['group'] : null,
                'text' => array_values(array_map('strval', $g['text'])),
            ],
            $data['ingredients'] ?? []
        ),
        'steps' => array_values(array_map('strval', $data['steps'] ?? [])),
        'notes' => $notes !== '' ? $notes : null,
        // Manueller Override fürs Frontend, falls die automatische Zeilen-Schätzung beim Drucken
        // danebenliegt (siehe estimatePrintLines() in index.html). Default false, kein Migrationsbedarf
        // für Altdaten ohne dieses Feld.
        'twoColumnPrint' => !empty($data['twoColumnPrint']),
    ];
}

/** Legt ein neues Rezept an: validiert, vergibt Slug/created, erstellt den Ordner und schreibt recipe.json. @throws ValidationException */
function create_recipe(array $data): array
{
    $errors = validate_recipe_input($data);
    if ($errors) {
        throw new ValidationException($errors);
    }

    $fields = normalize_recipe_fields($data);
    if (title_exists($fields['title'])) {
        throw new ValidationException(['Titel bereits vergeben']);
    }

    $baseSlug = slugify($fields['title']);
    if ($baseSlug === '') {
        throw new ValidationException(['Titel ergibt keinen gültigen Ordnernamen']);
    }
    $slug = unique_slug($baseSlug);

    if (!mkdir(recipe_dir($slug), 0775, true) && !is_dir(recipe_dir($slug))) {
        throw new RuntimeException('Rezeptordner konnte nicht angelegt werden');
    }

    $recipe = array_merge($fields, [
        'slug' => $slug,
        'created' => date('c'),
        'image' => null,
        'thumb' => null,
    ]);

    write_recipe_json($slug, $recipe);
    return $recipe;
}

/**
 * Aktualisiert ein Rezept. Ändert sich der Titel so, dass der Slug wechselt, wird der
 * Ordner atomar umbenannt (siehe Abschnitt 4 der Arbeitsübergabe) – bei Fehlschlag bleibt
 * der alte Zustand vollständig erhalten.
 *
 * @throws NotFoundException|ValidationException|ConflictException
 */
function update_recipe(string $slug, array $data): array
{
    $existing = read_recipe_json($slug);
    if ($existing === null) {
        throw new NotFoundException("Rezept '$slug' nicht gefunden");
    }

    $errors = validate_recipe_input($data);
    if ($errors) {
        throw new ValidationException($errors);
    }

    $fields = normalize_recipe_fields($data);
    if (title_exists($fields['title'], $slug)) {
        throw new ValidationException(['Titel bereits vergeben']);
    }

    $newBaseSlug = slugify($fields['title']);
    if ($newBaseSlug === '') {
        throw new ValidationException(['Titel ergibt keinen gültigen Ordnernamen']);
    }

    $newSlug = $newBaseSlug === $slug ? $slug : unique_slug($newBaseSlug, $slug);

    if ($newSlug !== $slug) {
        $oldDir = recipe_dir($slug);
        $newDir = recipe_dir($newSlug);
        if (is_dir($newDir)) {
            throw new ConflictException("Zielordner '$newSlug' existiert bereits");
        }
        if (!rename($oldDir, $newDir)) {
            throw new RuntimeException('Umbenennen des Rezeptordners fehlgeschlagen');
        }
    }

    $recipe = array_merge($existing, $fields, ['slug' => $newSlug]);
    write_recipe_json($newSlug, $recipe);
    return $recipe;
}

/** Entfernt den kompletten Rezeptordner (recipe.json + Bilder verschwinden automatisch mit). @throws NotFoundException|RuntimeException */
function delete_recipe(string $slug): void
{
    $dir = recipe_dir($slug);
    if (!is_dir($dir)) {
        throw new NotFoundException("Rezept '$slug' nicht gefunden");
    }
    remove_directory_recursive($dir);
}

/**
 * Löscht einen Ordner samt Inhalt rekursiv (PHP hat dafür keine eingebaute Funktion).
 * Prüft die Rückgabewerte von unlink()/rmdir() bewusst: ohne diese Prüfung blieb der Ordner unter
 * Windows bei einem kurzzeitigen Datei-Lock (z. B. Windows Search/Virenscanner öffnet gerade
 * thumb.jpg) real bestehen, während die API trotzdem Erfolg meldete – Rezepte "verschwanden" aus
 * der Liste beim nächsten Request nicht wirklich, sondern tauchten wieder auf.
 *
 * @throws RuntimeException wenn eine Datei oder der Ordner selbst nicht gelöscht werden konnte
 */
function remove_directory_recursive(string $dir): void
{
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            remove_directory_recursive($path);
        } elseif (!unlink($path)) {
            throw new RuntimeException("Datei konnte nicht gelöscht werden: $path");
        }
    }
    if (!rmdir($dir)) {
        throw new RuntimeException("Ordner konnte nicht gelöscht werden: $dir");
    }
}
