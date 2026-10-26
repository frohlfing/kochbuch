<?php

// Vorlage für config.php

/**
 * Daten-Ordner. Der Zugriff läuft ausschließlich über die API.
 * @var string Absoluter Pfad, ohne abschließenden Slash
 */
const DATA_DIR = __DIR__ . '/data';

/**
 * Unterordner für Rezepte (ein Unterordner pro Rezept, benannt nach dem Slug).
 * @var string Absoluter Pfad, ohne abschließenden Slash
 */
const RECIPES_DIR = DATA_DIR . '/recipes';

/**
 * Muss im Header X-API-Token mitgeschickt werden, wenn Daten geändert werden.
 * @var string Geheimes Token (z. B. per bin2hex(random_bytes(32)) erzeugt)
 */
const API_TOKEN = 'CHANGE_ME';

/**
 * Zielbreite automatisch generierter Thumbnails (Center-Crop, siehe api/lib/thumbnail.php).
 * @var int Pixel
 */
const THUMB_WIDTH = 480;

/**
 * Zielhöhe automatisch generierter Thumbnails (Center-Crop, siehe api/lib/thumbnail.php).
 * @var int Pixel
 */
const THUMB_HEIGHT = 360;

/**
 * Erlaubte Datei-Endung => erwarteter MIME-Type, geprüft beim Bild-Upload.
 * @var array<string, string>
 */
const ALLOWED_IMAGE_TYPES = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
];

/**
 * Maximal erlaubte Upload-Größe eines Rezeptbilds.
 * @var int Bytes
 */
const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;
