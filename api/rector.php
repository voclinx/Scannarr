<?php

/**
 * Rector configuration — basé sur CODING_STANDARDS.md V2.0.
 *
 * Enforce automatiquement :
 *   §9.1  — declare(strict_types=1) sur chaque fichier PHP
 *   §9.3  — classes `final` (services), propriétés `readonly` (DTOs)
 *   §9.2  — typage complet (paramètres, retours, propriétés)
 *   §2    — early return dans les controllers (méthodes courtes)
 *   §9    — suppression du code mort, qualité générale
 *
 * Usage :
 *   # Dry-run (voir ce qui serait modifié) :
 *   docker exec scanarr-api vendor/bin/rector process --dry-run
 *
 *   # Appliquer les modifications :
 *   docker exec scanarr-api vendor/bin/rector process
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Privatization\Rector\Class_\FinalizeClassesWithoutChildrenRector;
use Rector\TypeDeclaration\Rector\Declare_\DeclareStrictTypesRector;

return RectorConfig::configure()

    // ================================================================
    // CHEMINS ANALYSÉS
    // ================================================================
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])

    // ================================================================
    // CHEMINS EXCLUS
    // ================================================================
    ->withSkip([
        // Code auto-généré — ne jamais toucher
        __DIR__ . '/var',
        __DIR__ . '/vendor',
        __DIR__ . '/migrations',

        // Config Symfony — YAML/PHP de configuration, pas de logique métier
        __DIR__ . '/config',

        // ============================================================
        // Règles spécifiques à exclure sur certaines classes
        // ============================================================

        /**
         * §9.3 — FinalizeClassesWithoutChildrenRector.
         *
         * Les Entités Doctrine NE PEUVENT PAS être `final` :
         * Doctrine génère des classes proxy qui ÉTENDENT les entités.
         * Rendre une entité `final` casse le lazy loading de Doctrine.
         *
         * Les AbstractController de Symfony ne doivent pas être finalisés.
         */
        FinalizeClassesWithoutChildrenRector::class => [
            __DIR__ . '/src/Entity',
        ],
    ])

    // ================================================================
    // §9.1 — declare(strict_types=1) OBLIGATOIRE sur chaque fichier.
    // Voir CODING_STANDARDS.md §9.1 : "OBLIGATOIRE en haut de chaque fichier PHP"
    // ================================================================
    ->withRules([
        DeclareStrictTypesRector::class,
    ])

    // ================================================================
    // VERSION PHP — PHP 8.3 (voir composer.json "php": ">=8.3")
    //
    // Inclut automatiquement :
    //   - ReadOnlyPropertyRector (PHP 8.1+) → §9.3 readonly DTOs
    //   - EnumTypeRector (PHP 8.1+) → §9.6 Enums
    //   - ConstructorPromotionRector (PHP 8.0+) → DI concise
    //   - NullsafeOperatorRector (PHP 8.0+)
    //   - FiberRector (PHP 8.1+)
    // ================================================================
    ->withPhpSets(php83: true)

    // ================================================================
    // ENSEMBLES PRÉPARÉS
    // ================================================================
    ->withPreparedSets(
        /**
         * Suppression du code mort.
         * §9 : pas de code mort.
         * Inclut : méthodes privées non utilisées, variables inutilisées,
         * branches mortes, return statements inutiles, etc.
         */
        deadCode: true,

        /**
         * Qualité générale du code.
         * Inclut : simplification des conditions booléennes,
         * utilisation de `match` vs `switch`, etc.
         */
        codeQuality: true,

        /**
         * Déclarations de types.
         * §9.2 — Tout est typé. Pas de `mixed`, return types obligatoires.
         * Inclut : AddReturnTypeDeclarationRector, TypedPropertyRector,
         * AddParamTypeDeclarationRector, etc.
         */
        typeDeclarations: true,

        /**
         * Privatisation / Final.
         * §9.3 — "Les services sont `final` sauf besoin explicite d'héritage."
         * §9.3 — "Les DTOs sont `final readonly`."
         *
         * FinalizeClassesWithoutChildrenRector : rend `final` toute classe
         * qui n'a pas de classe enfant dans le code analysé.
         * Les Entities sont exclues via withSkip() ci-dessus (proxies Doctrine).
         */
        privatization: true,

        /**
         * Early return.
         * §2.3 — Controllers max ~15 lignes. Le pattern "early return" (guard clauses)
         * permet de réduire l'imbrication et de raccourcir les méthodes.
         *
         * Ex: remplace `if ($x) { ... long block ... }` par
         *     `if (!$x) { return; } ... flat code ...`
         */
        earlyReturn: true,
    )

    // ================================================================
    // IMPORTS — gère automatiquement les `use` statements.
    // Supprime les imports inutilisés (aligné avec PHPMD MissingImport exclu).
    // ================================================================
    ->withImportNames(removeUnusedImports: true);
