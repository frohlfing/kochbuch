<?php

/** Erkennt chefkoch.de und liest dessen schema.org/Recipe-JSON-LD aus. */
class ChefkochParser implements RecipeParserInterface
{
    /**
     * @param string $url Adresse der Rezeptseite
     * @return bool true, wenn der Host chefkoch.de enthält
     */
    public function supports(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return str_contains($host, 'chefkoch.de');
    }

    /**
     * @param string $url Adresse der Rezeptseite
     * @return array{title: string, category: ?string, servings: ?string, image_url: ?string, ingredients: string[], steps: string[], notes: ?string}
     * @throws RuntimeException wenn die Seite nicht geladen werden konnte oder kein Rezept enthält
     */
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
