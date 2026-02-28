<?php

declare(strict_types=1);

/**
 * PHPStan Doctrine object manager loader.
 *
 * Requis par phpstan/phpstan-doctrine pour résoudre les métadonnées ORM :
 * associations, custom generators, types Doctrine, etc.
 *
 * Le kernel Symfony est booté en mode test (sans debug) pour que
 * Doctrine puisse lire les métadonnées d'entités sans toucher la DB.
 * La connexion est lazy — aucune requête SQL n'est exécutée ici.
 *
 * Usage dans phpstan.dist.neon :
 *   doctrine:
 *     objectManagerLoader: tests/phpstan-doctrine-object-manager.php
 */

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = '0';

if (file_exists(dirname(__DIR__) . '/.env.test')) {
    (new Dotenv())->bootEnv(dirname(__DIR__) . '/.env.test');
}

$kernel = new Kernel('test', false);
$kernel->boot();

return $kernel->getContainer()->get('doctrine.orm.entity_manager');
