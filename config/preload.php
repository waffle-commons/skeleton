<?php

/**
 * Script de préchargement Waffle (opcache.preload).
 *
 * Exécuté une seule fois au démarrage du serveur : les classes du framework sont
 * chargées en mémoire partagée (OPcache) pour toute la durée de vie des workers.
 *
 * Les classes passent par l’autoloader Composer (class_exists) plutôt que par
 * opcache_compile_file() : une classe n’est préchargée que si ses parents et ses
 * interfaces sont déjà liés, et seul l’autoloader les charge dans le bon ordre,
 * interfaces PSR comprises (elles vivent hors de vendor/waffle-commons). Une classe
 * dont une dépendance optionnelle n’est pas installée est simplement ignorée.
 */

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    return;
}

/** @var Composer\Autoload\ClassLoader $loader */
$loader = require $autoload;

(static function (Composer\Autoload\ClassLoader $loader): void {
    // La carte de classes est lue sur le chargeur, jamais en incluant
    // vendor/composer/autoload_classmap.php : un fichier inclus pendant le
    // préchargement reste figé en mémoire pour tout le processus, et Composer
    // relirait cette copie périmée s’il tournait sous préchargement.
    // En production, la carte est complète (dump-autoload --classmap-authoritative).
    foreach ($loader->getClassMap() as $class => $file) {
        // Seul le framework est ciblé ; ses dépendances (PSR, etc.) suivent via l’autoloader.
        if (
            !str_contains($file, '/waffle-commons/')
            || str_contains($file, '/tests/')
            || str_contains($file, '/bin/')
        ) {
            continue;
        }

        try {
            // Déclenche l’autoloader, qui charge d’abord parents et interfaces. Une
            // interface ou un trait est chargé, mais class_exists() rend false : seul
            // un symbole que son fichier ne déclare pas est signalé.
            if (!class_exists($class) && !interface_exists($class, false) && !trait_exists($class, false)) {
                error_log(sprintf('Préchargement ignoré pour %s : %s ne le déclare pas', $class, $file));
            }
        } catch (Throwable $t) {
            // Dépendance optionnelle absente (p. ex. un pont vers un SDK non installé) : on passe.
            error_log(sprintf('Préchargement ignoré pour %s : %s', $class, $t->getMessage()));
        }
    }
})($loader);
