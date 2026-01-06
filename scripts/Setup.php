<?php

namespace Scripts;

/**
 * Waffle Skeleton Setup Script.
 *
 * Handles post-create-project and post-install tasks.
 * No external dependencies required (Pure PHP).
 */
class Setup
{
    /**
     * Triggered after "composer create-project"
     * * @param object $event Composer\Script\Event (duck typed to avoid dependency)
     */
    public static function postCreateProject(object $event): void
    {
        $io = $event->getIO();
        $rootDir = dirname(__DIR__);

        $io->write('<info>🦁 Configuring Waffle Commons Skeleton...</info>');

        self::createEnvFile($rootDir, $io);
        self::generateAppSecret($rootDir, $io);
        self::createVarDirectories($rootDir, $io);

        $io->write('<info>✅ Waffle Ecosystem is ready!</info>');
    }

    /**
     * Triggered after "composer install"
     * * @param object $event Composer\Script\Event
     */
    public static function postInstall(object $event): void
    {
        $io = $event->getIO();
        $rootDir = dirname(__DIR__);

        self::createVarDirectories($rootDir, $io);

        // Ensure .env exists (but don't overwrite if it exists)
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
            $io->writeError('<error>.env.example file not found!</error>');
            return;
        }

        copy($envExample, $envFile);
        $io->write(' -> Created <comment>.env</comment> from template.');
    }

    private static function generateAppSecret(string $rootDir, object $io): void
    {
        $envFile = $rootDir . '/.env';

        if (!file_exists($envFile)) {
            return;
        }

        $content = file_get_contents($envFile);

        // Check if secret is already changed or not present
        if (!str_contains($content, 'APP_SECRET=ChangeMeInProduction')) {
            return;
        }

        // Generate a 32-byte random hex string (64 chars)
        $secret = bin2hex(random_bytes(32));

        $content = str_replace(
            'APP_SECRET=ChangeMeInProduction',
            'APP_SECRET=' . $secret,
            $content
        );

        file_put_contents($envFile, $content);
        $io->write(' -> Generated secure <comment>APP_SECRET</comment>.');
    }

    private static function createVarDirectories(string $rootDir, object $io): void
    {
        $dirs = [
            'var/cache',
            'var/log'
        ];

        foreach ($dirs as $dir) {
            $path = $rootDir . '/' . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
                // Try to set permissions for Docker compatibility (if supported)
                @chmod($path, 0777);
            }
        }

        $io->write(' -> Ensured <comment>var/</comment> directories exist.');
    }
}
