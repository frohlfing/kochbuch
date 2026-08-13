<?php

require_once __DIR__ . '/thumbnail.php';

/** Die Bilddatei entspricht nicht ALLOWED_IMAGE_TYPES (geprüft am tatsächlichen Dateiinhalt, nicht an Endung/Content-Type). */
class ImageValidationException extends RuntimeException
{
}

/**
 * Installiert die lokale Bilddatei unter $localPath als Titelbild von Rezept $slug: validiert
 * den tatsächlichen Bildtyp, entfernt alte Bild- und Thumbnail-Dateien (auch bei Formatwechsel),
 * kopiert das Bild als image.<ext>, generiert ein Thumbnail und aktualisiert recipe.json. $localPath wird
 * dabei nur gelesen (kopiert), nicht verschoben oder gelöscht – Aufräumen bleibt Sache des Aufrufers.
 * Gemeinsam genutzt von upload.php (Browser-Upload) und import.php (heruntergeladenes Bild).
 *
 * @throws ImageValidationException|NotFoundException
 */
function store_recipe_image(string $slug, string $localPath): array
{
    $recipe = read_recipe_json($slug);
    if ($recipe === null) {
        throw new NotFoundException("Rezept '$slug' nicht gefunden");
    }

    $imageInfo = @getimagesize($localPath);
    $extByImageType = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];
    $ext = $imageInfo !== false ? ($extByImageType[$imageInfo[2]] ?? null) : null;
    if ($ext === null || !isset(ALLOWED_IMAGE_TYPES[$ext])) {
        throw new ImageValidationException('Nicht unterstützter Bildtyp (erlaubt: ' . implode(', ', array_keys(ALLOWED_IMAGE_TYPES)) . ')');
    }

    $dir = recipe_dir($slug);

    foreach (glob($dir . '/image.*') ?: [] as $old) {
        unlink($old);
    }
    foreach (glob($dir . '/thumb.*') ?: [] as $old) {
        unlink($old);
    }

    $imageName = 'image.' . $ext;
    if (!copy($localPath, $dir . '/' . $imageName)) {
        throw new RuntimeException('Bild konnte nicht gespeichert werden');
    }

    $thumbName = 'thumb.' . $ext;
    $thumbOk = generate_thumbnail($dir . '/' . $imageName, $dir . '/' . $thumbName, $ext, THUMB_WIDTH, THUMB_HEIGHT);

    $recipe['image'] = $imageName;
    $recipe['thumb'] = $thumbOk ? $thumbName : null;
    write_recipe_json($slug, $recipe);

    return $recipe;
}
