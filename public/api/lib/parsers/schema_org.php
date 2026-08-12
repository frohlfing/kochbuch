<?php

/**
 * Sucht in allen <script type="application/ld+json">-Blöcken der Seite nach einem
 * schema.org/Recipe-Knoten (auch verschachtelt in einer @graph-Struktur, wie chefkoch.de sie
 * verwendet) und übersetzt ihn in unser internes Rohformat. Null, wenn kein Recipe gefunden wurde.
 *
 * @return array{title:string, category:?string, servings:?string, image_url:?string,
 *               ingredients:string[], steps:string[], notes:?string}|null
 */
function extract_schema_org_recipe(string $html): ?array
{
    if (!preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches)) {
        return null;
    }

    $nodesById = [];
    $recipeNode = null;

    foreach ($matches[1] as $json) {
        $decoded = json_decode(trim($json), true);
        if ($decoded === null) {
            continue;
        }
        foreach (schema_org_flatten_nodes($decoded) as $node) {
            if (isset($node['@id']) && is_string($node['@id'])) {
                $nodesById[$node['@id']] = $node;
            }
            if ($recipeNode === null && schema_org_is_recipe($node)) {
                $recipeNode = $node;
            }
        }
    }

    if ($recipeNode === null) {
        return null;
    }

    $title = schema_org_text($recipeNode['name'] ?? null) ?? '';
    // Manche Portale (u. a. chefkoch.de) haengen an den Titel " von <Autor>" an. Nur entfernen,
    // wenn es exakt mit dem ueber "author" verlinkten Namen uebereinstimmt (kein Raten anhand
    // von Textmustern), damit echte Titel wie "Involtini von Huhn" unangetastet bleiben.
    $authorName = schema_org_resolve_author_name($recipeNode['author'] ?? null, $nodesById);
    if ($authorName !== null && str_ends_with($title, ' von ' . $authorName)) {
        $title = substr($title, 0, -strlen(' von ' . $authorName));
    }

    return [
        'title' => $title,
        'category' => schema_org_first_string($recipeNode['recipeCategory'] ?? null),
        'servings' => schema_org_best_yield($recipeNode['recipeYield'] ?? null),
        'image_url' => schema_org_resolve_image($recipeNode['image'] ?? null, $nodesById),
        'ingredients' => schema_org_string_list($recipeNode['recipeIngredient'] ?? $recipeNode['ingredients'] ?? []),
        'steps' => schema_org_flatten_instructions($recipeNode['recipeInstructions'] ?? []),
        // Bewusst nicht aus "description" befüllt: bei chefkoch.de u.a. steht dort SEO-Marketingtext,
        // kein echter Rezept-Hinweis. Notizen müssen nach dem Import ggf. manuell ergänzt werden.
        'notes' => null,
    ];
}

/** Liefert $decoded und – rekursiv – alle Einträge aus dessen @graph bzw. Listen-Elementen als flache Liste von Knoten. */
function schema_org_flatten_nodes(mixed $decoded): array
{
    if (is_array($decoded) && array_is_list($decoded)) {
        $out = [];
        foreach ($decoded as $item) {
            $out = array_merge($out, schema_org_flatten_nodes($item));
        }
        return $out;
    }
    if (!is_array($decoded)) {
        return [];
    }
    $out = [$decoded];
    if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
        foreach ($decoded['@graph'] as $item) {
            $out = array_merge($out, schema_org_flatten_nodes($item));
        }
    }
    return $out;
}

function schema_org_is_recipe(array $node): bool
{
    $type = $node['@type'] ?? null;
    if (is_array($type)) {
        return in_array('Recipe', $type, true);
    }
    return $type === 'Recipe';
}

function schema_org_text(mixed $value): ?string
{
    if (is_string($value)) {
        $t = trim($value);
        return $t !== '' ? $t : null;
    }
    return null;
}

/** Erste nicht-leere Zeichenkette aus einem String oder (verschachtelten) Array von Strings. */
function schema_org_first_string(mixed $value): ?string
{
    if (is_string($value)) {
        return schema_org_text($value);
    }
    if (is_array($value)) {
        foreach ($value as $v) {
            $t = schema_org_first_string($v);
            if ($t !== null) {
                return $t;
            }
        }
    }
    return null;
}

/** recipeYield ist oft z. B. ["4", "4 Portionen"] – die aussagekräftigere (nicht rein numerische) Variante bevorzugen. */
function schema_org_best_yield(mixed $value): ?string
{
    $candidates = is_array($value) ? $value : [$value];
    $best = null;
    foreach ($candidates as $c) {
        $t = is_scalar($c) ? schema_org_text((string) $c) : null;
        if ($t === null) {
            continue;
        }
        if (!ctype_digit($t)) {
            return $t;
        }
        $best ??= $t;
    }
    return $best;
}

/** @return string[] */
function schema_org_string_list(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $out = [];
    foreach ($value as $item) {
        if (is_string($item)) {
            $t = trim($item);
            if ($t !== '') {
                $out[] = $t;
            }
        } elseif (is_array($item) && isset($item['name']) && is_string($item['name'])) {
            $t = trim($item['name']);
            if ($t !== '') {
                $out[] = $t;
            }
        }
    }
    return $out;
}

/**
 * recipeInstructions kommt in der Praxis als String, Array von Strings, Array von HowToStep
 * ({"@type":"HowToStep","text":...}) oder verschachtelt als HowToSection mit itemListElement vor
 * (so bei chefkoch.de) – wird rekursiv zu einer flachen Liste von Schritt-Texten aufgelöst.
 *
 * @return string[]
 */
function schema_org_flatten_instructions(mixed $value): array
{
    if (is_string($value)) {
        return array_values(array_filter(array_map('trim', preg_split('/\r?\n+/', $value))));
    }
    if (!is_array($value)) {
        return [];
    }
    $out = [];
    foreach ($value as $item) {
        if (is_string($item)) {
            $t = trim($item);
            if ($t !== '') {
                $out[] = $t;
            }
        } elseif (is_array($item)) {
            if (isset($item['itemListElement'])) {
                $out = array_merge($out, schema_org_flatten_instructions($item['itemListElement']));
            } elseif (isset($item['text']) && is_string($item['text'])) {
                $t = trim($item['text']);
                if ($t !== '') {
                    $out[] = $t;
                }
            }
        }
    }
    return $out;
}

/** author kann String, Person-Objekt (inline oder @id-Referenz ins @graph) sein. */
function schema_org_resolve_author_name(mixed $value, array $nodesById): ?string
{
    if (is_string($value)) {
        return schema_org_text($value);
    }
    if (is_array($value)) {
        if (isset($value['name'])) {
            return schema_org_text($value['name']);
        }
        if (isset($value['@id']) && isset($nodesById[$value['@id']])) {
            return schema_org_resolve_author_name($nodesById[$value['@id']], $nodesById);
        }
    }
    return null;
}

/** image kann String, Array von Strings, ImageObject (inline oder @id-Referenz ins @graph) sein. */
function schema_org_resolve_image(mixed $value, array $nodesById): ?string
{
    if (is_string($value)) {
        return schema_org_text($value);
    }
    if (is_array($value)) {
        if (array_is_list($value)) {
            foreach ($value as $item) {
                $url = schema_org_resolve_image($item, $nodesById);
                if ($url !== null) {
                    return $url;
                }
            }
            return null;
        }
        if (isset($value['url'])) {
            return schema_org_text($value['url']);
        }
        if (isset($value['contentUrl'])) {
            return schema_org_text($value['contentUrl']);
        }
        if (isset($value['@id']) && isset($nodesById[$value['@id']])) {
            return schema_org_resolve_image($nodesById[$value['@id']], $nodesById);
        }
    }
    return null;
}
