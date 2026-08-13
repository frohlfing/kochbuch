<?php

/**
 * Liefert das Original- oder Thumbnail-Bild eines Rezepts aus.
 * Nötig, weil DATA_DIR bewusst außerhalb des Document Root liegt und daher nicht
 * direkt per statischer URL erreichbar ist (siehe config.php).
 *
 * GET /api/image.php?slug=xyz&type=image|thumb - kein Token nötig (wie GET auf recipes.php).
 * Antwort: die Bilddatei mit passendem Content-Type, 404 falls Rezept/Bild fehlt.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/crud.php';

$slug = (string) ($_GET['slug'] ?? '');
$type = (string) ($_GET['type'] ?? 'image');

if (!in_array($type, ['image', 'thumb'], true)) {
    json_error(400, 'type muss image oder thumb sein');
}

$recipe = get_recipe($slug);
if ($recipe === null || empty($recipe[$type])) {
    http_response_code(404);
    exit;
}

$path = recipe_dir($slug) . '/' . $recipe[$type];
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = ALLOWED_IMAGE_TYPES[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=604800'); // 1 Woche; ändert sich nur bei erneutem Upload
header('Content-Length: ' . filesize($path));
readfile($path);
