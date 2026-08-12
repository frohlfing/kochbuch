<?php

/**
 * Nimmt das Foto für ein bestehendes Rezept entgegen und generiert daraus automatisch
 * ein Thumbnail (siehe api/lib/thumbnail.php).
 *
 * POST /api/upload.php (multipart/form-data)
 *   Felder: slug (Pflicht, muss ein existierendes Rezept referenzieren), image (Pflicht, Datei).
 *   Token nötig (Header X-API-Token).
 *
 * Ablauf: Bildtyp wird aus dem tatsächlichen Dateiinhalt bestimmt (nicht aus der vom Client
 * behaupteten Endung), auf ALLOWED_IMAGE_TYPES/MAX_UPLOAD_BYTES aus config.php geprüft,
 * alte Bild- und Thumbnail-Dateien im Rezeptordner entfernt (auch bei Formatwechsel), das
 * neue Bild als image.<ext> gespeichert, ein thumb.<ext> generiert und beides in recipe.json vermerkt.
 * Antwort: das komplette, aktualisierte Rezept-Objekt.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/crud.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error(405, 'Nur POST erlaubt');
}

require_token();

$slug = (string) ($_POST['slug'] ?? '');
if ($slug === '') {
    json_error(400, 'slug ist erforderlich');
}

$recipe = read_recipe_json($slug);
if ($recipe === null) {
    json_error(404, "Rezept '$slug' nicht gefunden");
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    json_error(400, 'Kein gültiges Bild im Feld "image" übermittelt');
}

$file = $_FILES['image'];
if ($file['size'] > MAX_UPLOAD_BYTES) {
    json_error(400, 'Bild ist zu groß (Limit: ' . (int) (MAX_UPLOAD_BYTES / 1024 / 1024) . ' MB)');
}

// Tatsächlichen Bildtyp aus dem Dateiinhalt bestimmen, nicht der vom Client behaupteten Endung vertrauen.
$imageInfo = @getimagesize($file['tmp_name']);
$extByImageType = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG => 'png',
    IMAGETYPE_WEBP => 'webp',
];
$ext = $imageInfo !== false ? ($extByImageType[$imageInfo[2]] ?? null) : null;
if ($ext === null || !isset(ALLOWED_IMAGE_TYPES[$ext])) {
    json_error(400, 'Nicht unterstützter Bildtyp (erlaubt: ' . implode(', ', array_keys(ALLOWED_IMAGE_TYPES)) . ')');
}

$dir = recipe_dir($slug);

// Alte image.* / thumb.* entfernen (auch bei Formatwechsel, z. B. png -> jpg)
foreach (glob($dir . '/image.*') ?: [] as $old) {
    unlink($old);
}
foreach (glob($dir . '/thumb.*') ?: [] as $old) {
    unlink($old);
}

$imageName = 'image.' . $ext;
if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $imageName)) {
    json_error(500, 'Bild konnte nicht gespeichert werden');
}

$thumbName = 'thumb.' . $ext;
$thumbOk = generate_thumbnail($dir . '/' . $imageName, $dir . '/' . $thumbName, $ext, THUMB_WIDTH, THUMB_HEIGHT);

$recipe['image'] = $imageName;
$recipe['thumb'] = $thumbOk ? $thumbName : null;
write_recipe_json($slug, $recipe);

json_response(200, $recipe);
