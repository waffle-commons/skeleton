<?php

/**
 * Script de préchargement Waffle.
 *
 * Exécuté une seule fois au démarrage du serveur. Il charge les classes du
 * framework en mémoire partagée (Opcache) pour accélérer l'exécution.
 */

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}

(function () {
    // Racine de l'application (/app dans Docker).
    $projectDir = dirname(__DIR__);

    // Liste des dossiers critiques à précharger.
    // On cible vendor/waffle-commons pour charger l'ensemble du framework.
    $paths = [
        $projectDir . '/vendor/waffle-commons',
        // D'autres bibliothèques critiques peuvent être ajoutées ici.
    ];

    // Fonction récursive de parcours et de compilation.
    $preload = function (string $dir) use (&$preload) {
        if (!is_dir($dir)) {
            return;
        }

        $handle = opendir($dir);
        while ($file = readdir($handle)) {
            if (in_array($file, ['.', '..'])) {
                continue;
            }

            $path = $dir . '/' . $file;

            if (is_dir($path)) {
                $preload($path);
            } elseif (str_ends_with($path, '.php')) {
                // Exclusion des fichiers de tests et des binaires.
                if (str_contains($path, '/tests/') || str_contains($path, '/bin/')) {
                    continue;
                }

                try {
                    // C'est ici que la magie opère : compilation du fichier en mémoire.
                    opcache_compile_file($path);
                } catch (Throwable $t) {
                    // Les erreurs de préchargement sont volontairement silencieuses
                    // (p. ex. des classes qui ne peuvent pas être chargées hors contexte).
                    error_log("Échec du préchargement pour $path : " . $t->getMessage());
                }
            }
        }
        closedir($handle);
    };

    // Exécution du préchargement.
    foreach ($paths as $path) {
        $preload($path);
    }
})();
