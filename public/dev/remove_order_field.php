<?php

/**
 * Entfernt "order" aus allen recipe.json.
 *
 * Einfach im Browser aufrufen:
 *
 *   https://<domain>/remove_order_field.php?token=<API_TOKEN>
 *
 * Mehrfaches Aufrufen ist unbedenklich (siehe run_remove_order_field()), ein zweiter Aufruf
 * meldet einfach 0 Änderungen.
 */

require_once __DIR__ . '/../../config.php';

function run_remove_order_field(): array
{
    $removed = [];
    $alreadyClean = 0;

    foreach (scandir(RECIPES_DIR) ?: [] as $slug) {
        if ($slug === '.' || $slug === '..' || !is_dir(RECIPES_DIR . '/' . $slug)) {
            continue;
        }

        $path = RECIPES_DIR . '/' . $slug . '/recipe.json';
        $recipe = json_decode(file_get_contents($path), true);
        if (!is_array($recipe)) {
            continue;
        }

        if (!array_key_exists('order', $recipe)) {
            $alreadyClean++;
            continue;
        }

        unset($recipe['order']);

        file_put_contents(
            $path,
            json_encode($recipe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n"
        );
        $removed[] = $recipe['title'] ?? "($slug)";
    }

    sort($removed, SORT_STRING | SORT_FLAG_CASE);

    return ['removed' => $removed, 'alreadyClean' => $alreadyClean];
}

header('Content-Type: text/plain; charset=utf-8');

$token = (string) ($_GET['token'] ?? '');
if ($token === '' || !hash_equals(API_TOKEN, $token)) {
    http_response_code(401);
    echo 'Ungültiges oder fehlendes Token. Aufruf mit ?token=<API_TOKEN>.';
    exit;
}

$report = run_remove_order_field();

echo '== Entfernt (' . count($report['removed']) . ') ==' . PHP_EOL;
foreach ($report['removed'] as $title) {
    echo "  $title" . PHP_EOL;
}

echo PHP_EOL . $report['alreadyClean'] . ' Rezept(e) hatten bereits kein "order"-Feld.' . PHP_EOL;
echo count($report['removed']) . ' Rezept(e) aktualisiert.' . PHP_EOL;
