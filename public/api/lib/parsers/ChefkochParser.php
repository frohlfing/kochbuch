<?php

/** Erkennt chefkoch.de und liest dessen schema.org/Recipe-JSON-LD aus. */
class ChefkochParser implements RecipeParserInterface
{
    public function supports(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return str_contains($host, 'chefkoch.de');
    }

    public function parse(string $url): array
    {
        $html = http_get($url);
        $recipe = extract_schema_org_recipe($html);
        if ($recipe === null) {
            throw new RuntimeException('Kein schema.org/Recipe auf der Chefkoch-Seite gefunden');
        }
        return $recipe;
    }
}
