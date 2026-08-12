<?php

/**
 * Vertrag für Rezept-Import-Parser. Neue Quell-Portale: einfach implementieren und in der
 * Parser-Registry in api/import.php eintragen (vor dem GenericSchemaOrgParser-Fallback) –
 * an import.php selbst ändert sich dabei nichts.
 */
interface RecipeParserInterface
{
    /** Erkennt anhand der URL, ob dieser Parser zuständig ist. */
    public function supports(string $url): bool;

    /**
     * Lädt die Seite und extrahiert die Rezeptdaten.
     *
     * @return array{title:string, category:?string, servings:?string, image_url:?string,
     *               ingredients:string[], steps:string[], notes:?string}
     * @throws RuntimeException wenn die Seite nicht geladen werden konnte oder kein Rezept gefunden wurde
     */
    public function parse(string $url): array;
}
