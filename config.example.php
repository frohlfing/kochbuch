<?php

// Vorlage für config.php

// Ordner, in dem alle Rezeptdaten liegen. Bewusst außerhalb von public/ (= außerhalb des
// Document Root), damit der Browser nicht direkt darauf zugreifen kann. Der Zugriff läuft
// ausschließlich über die API (api/recipes.php, api/image.php).
const DATA_DIR = __DIR__ . '/data';

// Ein Unterordner pro Rezept: RECIPES_DIR/<slug>/recipe.json, image.<ext>, thumb.<ext>
const RECIPES_DIR = DATA_DIR . '/recipes';

// Muss im Header X-API-Token mitgeschickt werden, damit POST/PUT/DELETE auf recipes.php
// und der Upload auf upload.php akzeptiert werden. GET bleibt ohne Token offen.
const API_TOKEN = 'CHANGE_ME';

// Zielmaße für automatisch generierte Thumbnails (Center-Crop, siehe api/lib/thumbnail.php)
const THUMB_WIDTH = 480;
const THUMB_HEIGHT = 360;

// Erlaubte Datei-Endung => erwarteter MIME-Type, geprüft beim Bild-Upload
const ALLOWED_IMAGE_TYPES = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
];

// Maximal erlaubte Upload-Größe eines Rezeptbilds in Bytes
const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;
