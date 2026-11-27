<?php

/**
 * Lädt eine Bilddatei als GD-Image passend zur Endung $ext (jpg/jpeg/png/webp).
 * @param string $path Pfad der Bilddatei
 * @param string $ext Dateiendung in Kleinbuchstaben
 * @return GdImage|false GD-Image; false bei nicht unterstütztem Format oder nicht lesbarer Datei
 */
function gd_load(string $path, string $ext): GdImage|false
{
    return match ($ext) {
        'jpg', 'jpeg' => @imagecreatefromjpeg($path),
        'png' => @imagecreatefrompng($path),
        'webp' => @imagecreatefromwebp($path),
        default => false,
    };
}

/**
 * Schreibt ein GD-Image passend zur Endung $ext (jpg/jpeg/png/webp) nach $path.
 * @param GdImage $image Zu speicherndes Bild
 * @param string $path Zielpfad
 * @param string $ext Dateiendung in Kleinbuchstaben (bestimmt das Format)
 * @return bool true bei Erfolg
 */
function gd_save(GdImage $image, string $path, string $ext): bool
{
    return match ($ext) {
        'jpg', 'jpeg' => imagejpeg($image, $path, 85),
        'png' => imagepng($image, $path, 6),
        'webp' => imagewebp($image, $path, 85),
        default => false,
    };
}

/**
 * Erzeugt aus $srcPath ein auf $width x $height zentriert zugeschnittenes Thumbnail
 * unter $destPath. Behält die Bildendung (und damit das Format) von $srcPath bei.
 * @param string $srcPath Pfad des Originalbilds
 * @param string $destPath Zielpfad des Thumbnails
 * @param string $ext Dateiendung in Kleinbuchstaben (jpg/jpeg/png/webp)
 * @param int $width Zielbreite in Pixeln
 * @param int $height Zielhöhe in Pixeln
 * @return bool true bei Erfolg; false, wenn das Original nicht geladen oder das Thumbnail nicht gespeichert werden konnte
 */
function generate_thumbnail(string $srcPath, string $destPath, string $ext, int $width, int $height): bool
{
    $src = gd_load($srcPath, $ext);
    if ($src === false) {
        return false;
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    $srcRatio = $srcW / $srcH;
    $dstRatio = $width / $height;

    if ($srcRatio > $dstRatio) {
        $cropH = $srcH;
        $cropW = (int)round($srcH * $dstRatio);
    } else {
        $cropW = $srcW;
        $cropH = (int)round($srcW / $dstRatio);
    }
    $srcX = (int)round(($srcW - $cropW) / 2);
    $srcY = (int)round(($srcH - $cropH) / 2);

    $dst = imagecreatetruecolor($width, $height);
    if ($ext === 'png') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $width, $height, $transparent);
    }

    imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $width, $height, $cropW, $cropH);

    // Kein imagedestroy(): GdImage-Objekte (seit PHP 8.0) werden automatisch per GC freigegeben,
    // der Aufruf ist seit PHP 8.5 deprecated.
    return gd_save($dst, $destPath, $ext);
}

/**
 * Stellt sicher, dass für das Rezept in $recipeDir ein Thumbnail existiert.
 * Fehlt es (z. B. Altdaten-Migration), wird es aus dem Originalbild nachgeneriert
 * und recipe.json aktualisiert.
 * @param string $recipeDir Absoluter Pfad des Rezeptordners
 * @param array $recipe Rezeptdaten aus recipe.json
 * @return array Das (ggf. um thumb ergänzte) Rezept
 */
function ensure_thumbnail(string $recipeDir, array $recipe): array
{
    if (empty($recipe['image'])) {
        return $recipe;
    }
    if (!empty($recipe['thumb']) && is_file($recipeDir . '/' . $recipe['thumb'])) {
        return $recipe;
    }

    $ext = strtolower(pathinfo($recipe['image'], PATHINFO_EXTENSION));
    $thumbName = 'thumb.' . $ext;
    $ok = generate_thumbnail(
        $recipeDir . '/' . $recipe['image'],
        $recipeDir . '/' . $thumbName,
        $ext,
        THUMB_WIDTH,
        THUMB_HEIGHT
    );

    if ($ok) {
        $recipe['thumb'] = $thumbName;
        file_put_contents(
            $recipeDir . '/recipe.json',
            json_encode($recipe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n"
        );
    }

    return $recipe;
}
