<?php

namespace Scripts;

/**
 * Script de mise en place du skeleton Waffle.
 *
 * Gère les tâches post-create-project et post-install. Sans dépendance externe
 * (PHP pur).
 */
class Setup
{
    /**
     * Déclenché après "composer create-project".
     * * @param object $event Composer\Script\Event (typage duck pour éviter la dépendance)
     */
    public static function postCreateProject(object $event): void
    {
        $io = $event->getIO();
        $rootDir = dirname(__DIR__);

        $io->write('<info>🦁 Configuration du skeleton Waffle Commons...</info>');

        self::createEnvFile($rootDir, $io);
        self::generateAppSecret($rootDir, $io);
        self::createVarDirectories($rootDir, $io);

        $io->write('<info>✅ L\'écosystème Waffle est prêt !</info>');
    }

    /**
     * Déclenché après "composer install".
     * * @param object $event Composer\Script\Event
     */
    public static function postInstall(object $event): void
    {
        $io = $event->getIO();
        $rootDir = dirname(__DIR__);

        self::createVarDirectories($rootDir, $io);

        // S'assurer que .env existe (sans écraser un fichier existant).
        if (!file_exists($rootDir . '/.env')) {
            self::createEnvFile($rootDir, $io);
        }
    }

    private static function createEnvFile(string $rootDir, object $io): void
    {
        $envFile = $rootDir . '/.env';
        $envExample = $rootDir . '/.env.example';

        if (file_exists($envFile)) {
            return;
        }

        if (!file_exists($envExample)) {
            $io->writeError('<error>Fichier .env.example introuvable !</error>');
            return;
        }

        copy($envExample, $envFile);
        $io->write(' -> <comment>.env</comment> créé depuis le modèle.');
    }

    private static function generateAppSecret(string $rootDir, object $io): void
    {
        $envFile = $rootDir . '/.env';

        if (!file_exists($envFile)) {
            return;
        }

        $content = file_get_contents($envFile);

        // Vérification : le secret a-t-il déjà été modifié ou n'est-il pas présent ?
        if (!str_contains($content, 'APP_SECRET=ChangeMeInProduction')) {
            return;
        }

        // Génération d'une chaîne hexadécimale aléatoire de 32 octets (64 caractères).
        $secret = bin2hex(random_bytes(32));

        $content = str_replace(
            'APP_SECRET=ChangeMeInProduction',
            'APP_SECRET=' . $secret,
            $content
        );

        file_put_contents($envFile, $content);
        $io->write(' -> <comment>APP_SECRET</comment> sécurisé généré.');
    }

    private static function createVarDirectories(string $rootDir, object $io): void
    {
        $dirs = [
            'var/cache/prod',
            'var/log',
        ];

        foreach ($dirs as $dir) {
            $path = $rootDir . '/' . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
                // Tentative de permissions adaptées à Docker (si supportées).
                @chmod($path, 0777);
            }
        }

        $io->write(' -> Dossiers <comment>var/</comment> garantis présents.');
    }
}
