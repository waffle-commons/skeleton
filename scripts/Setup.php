<?php

declare(strict_types=1);

namespace Scripts;

/**
 * Script de mise en place du skeleton Waffle.
 *
 * Gère les tâches post-create-project et post-install. Sans dépendance externe
 * (PHP pur) : l'événement Composer est reçu en `object` (typage duck), pour ne
 * pas exiger composer/composer.
 */
final class Setup
{
    /**
     * Secrets remplacés à la création du `.env` : la valeur de `.env.example` est
     * publique. WAFFLE_CSRF_SECRET et WAFFLE_AUTH_SECRET exigent 32 octets ou plus ;
     * en deçà, la production refuse de démarrer et le développement tire un secret
     * différent dans chaque worker, si bien qu'un jeton émis par l'un échoue sur l'autre.
     */
    private const array SECRET_KEYS = ['APP_SECRET', 'WAFFLE_CSRF_SECRET', 'WAFFLE_AUTH_SECRET'];

    private const string SECRET_PLACEHOLDER = 'ChangeMeInProduction';

    /**
     * Bloc du .gitignore propre au dépôt du squelette (il ne versionne pas son
     * composer.lock) : un projet créé versionne le sien, le bloc est donc retiré.
     */
    private const string SKELETON_ONLY_BLOCK = '/^###> waffle-commons\/skeleton ###$.*?^###< waffle-commons\/skeleton ###$\R?/ms';

    /**
     * Déclenché après "composer create-project".
     *
     * @param object $event Composer\Script\Event (typage duck pour éviter la dépendance)
     */
    public static function postCreateProject(object $event): void
    {
        $io = $event->getIO();
        $rootDir = dirname(__DIR__);

        $io->write('<info>🦁 Configuration du skeleton Waffle Commons...</info>');

        self::createEnvFile($rootDir, $io);
        self::generateSecrets($rootDir, $io);
        self::createVarDirectories($rootDir, $io);
        self::trackComposerLock($rootDir, $io);

        $io->write('<info>✅ L\'écosystème Waffle est prêt !</info>');
    }

    /**
     * Déclenché après "composer install" et "composer update".
     *
     * @param object $event Composer\Script\Event
     */
    public static function postInstall(object $event): void
    {
        $io = $event->getIO();
        $rootDir = dirname(__DIR__);

        self::createVarDirectories($rootDir, $io);

        // S'assurer que .env existe (sans écraser un fichier existant) : un clone neuf
        // reçoit ses propres secrets, comme un projet créé.
        if (!file_exists($rootDir . '/.env')) {
            self::createEnvFile($rootDir, $io);
            self::generateSecrets($rootDir, $io);
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

        if (!copy($envExample, $envFile)) {
            $io->writeError('<error>Impossible de créer .env depuis .env.example.</error>');
            return;
        }

        $io->write(' -> <comment>.env</comment> créé depuis le modèle.');
    }

    /**
     * Remplace chaque secret resté à sa valeur publique par 32 octets aléatoires
     * (64 caractères hexadécimaux). Seule la ligne entière `CLÉ=ChangeMeInProduction`
     * est visée : un secret déjà personnalisé est conservé.
     */
    private static function generateSecrets(string $rootDir, object $io): void
    {
        $envFile = $rootDir . '/.env';
        $content = is_file($envFile) ? file_get_contents($envFile) : false;

        if ($content === false) {
            return;
        }

        $generated = [];
        foreach (self::SECRET_KEYS as $key) {
            $pattern = '/^' . preg_quote($key . '=' . self::SECRET_PLACEHOLDER, '/') . '$/m';
            $updated = preg_replace($pattern, $key . '=' . bin2hex(random_bytes(32)), $content, 1);

            if ($updated !== null && $updated !== $content) {
                $content = $updated;
                $generated[] = $key;
            }
        }

        if ($generated === []) {
            return;
        }

        if (file_put_contents($envFile, $content) === false) {
            $io->writeError('<error>Impossible d\'écrire les secrets dans .env.</error>');
            return;
        }

        $io->write(' -> Secrets générés : <comment>' . implode('</comment>, <comment>', $generated) . '</comment>.');
    }

    private static function createVarDirectories(string $rootDir, object $io): void
    {
        $dirs = [
            'var/cache/prod',
            'var/log',
        ];

        foreach ($dirs as $dir) {
            $path = $rootDir . '/' . $dir;

            if (is_dir($path)) {
                continue;
            }

            if (!mkdir($path, 0o777, true) && !is_dir($path)) {
                $io->writeError('<error>Impossible de créer ' . $dir . '.</error>');
                continue;
            }

            // Inscriptible par l'utilisateur du conteneur, quel qu'il soit (contourne l'umask).
            chmod($path, 0o777);
        }

        $io->write(' -> Dossiers <comment>var/</comment> garantis présents.');
    }

    /**
     * Le dépôt du squelette ignore son composer.lock ; un projet créé doit versionner
     * le sien pour des installations reproductibles. Retire le bloc balisé du .gitignore.
     */
    private static function trackComposerLock(string $rootDir, object $io): void
    {
        $gitignore = $rootDir . '/.gitignore';
        $content = is_file($gitignore) ? file_get_contents($gitignore) : false;

        if ($content === false) {
            return;
        }

        $updated = preg_replace(self::SKELETON_ONLY_BLOCK, '', $content);

        if ($updated === null || $updated === $content) {
            return;
        }

        if (file_put_contents($gitignore, $updated) === false) {
            $io->writeError('<error>Impossible de mettre à jour .gitignore.</error>');
            return;
        }

        $io->write(' -> <comment>composer.lock</comment> versionné (retiré du .gitignore).');
    }
}
