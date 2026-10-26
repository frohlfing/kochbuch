<?php

/**
 * Fallback für jede Seite mit schema.org/Recipe-JSON-LD (z. B. wie nextcloud/cookbook es nutzt).
 * Muss in der Parser-Registry als letztes stehen, da supports() immer true liefert.
 */
class GenericSchemaOrgParser implements RecipeParserInterface
{
    /**
     * @param string $url Adresse der Rezeptseite
     * @return bool immer true
     */
    public function supports(string $url): bool
    {
        return true;
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
            throw new RuntimeException('Auf dieser Seite wurde kein schema.org/Recipe gefunden');
        }
        return $recipe;
    }
}
