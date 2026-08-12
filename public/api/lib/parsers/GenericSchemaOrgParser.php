<?php

/**
 * Fallback für jede Seite mit schema.org/Recipe-JSON-LD (z. B. wie nextcloud/cookbook es nutzt).
 * Muss in der Parser-Registry als letztes stehen, da supports() immer true liefert.
 */
class GenericSchemaOrgParser implements RecipeParserInterface
{
    public function supports(string $url): bool
    {
        return true;
    }

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
