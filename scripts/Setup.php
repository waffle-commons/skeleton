<?php

declare(strict_types=1);

namespace Scripts;

/**
 * Script de mise en place du skeleton Waffle.
 *
 * Gère les tâches post-create-project et post-install. PHP pur, sans dépendance :
 * Composer passe son événement en argument, mais le script n'en lit rien et écrit
 * directement sur la sortie standard (les erreurs sur la sortie d'erreur). Typer
 * l'IO de Composer exigerait composer/composer dans chaque projet créé.
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
     */
    public static function postCreateProject(): void
    {
        $rootDir = dirname(__DIR__);

        self::info('🦁 Configuration du skeleton Waffle Commons...');

        self::createEnvFile($rootDir);
        self::generateSecrets($rootDir);
        self::createVarDirectories($rootDir);
        self::trackComposerLock($rootDir);

        self::info('✅ L\'écosystème Waffle est prêt !');
    }

    /**
     * Déclenché après "composer install" et "composer update".
     */
    public static function postInstall(): void
    {
        $rootDir = dirname(__DIR__);

        self::createVarDirectories($rootDir);

        // S'assurer que .env existe (sans écraser un fichier existant) : un clone neuf
        // reçoit ses propres secrets, comme un projet créé.
        if (!file_exists($rootDir . '/.env')) {
            self::createEnvFile($rootDir);
            self::generateSecrets($rootDir);
        }
    }

    private static function createEnvFile(string $rootDir): void
    {
        $envFile = $rootDir . '/.env';
        $envExample = $rootDir . '/.env.example';

        if (file_exists($envFile)) {
            return;
        }

        if (!file_exists($envExample)) {
            self::error('Fichier .env.example introuvable !');
            return;
        }

        if (!copy($envExample, $envFile)) {
            self::error('Impossible de créer .env depuis .env.example.');
            return;
        }

        self::info(' -> .env créé depuis le modèle.');
    }

    /**
     * Remplace chaque secret resté à sa valeur publique par 32 octets aléatoires
     * (64 caractères hexadécimaux). Seule la ligne entière `CLÉ=ChangeMeInProduction`
     * est visée : un secret déjà personnalisé est conservé.
     */
    private static function generateSecrets(string $rootDir): void
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
            self::error('Impossible d\'écrire les secrets dans .env.');
            return;
        }

        self::info(' -> Secrets générés : ' . implode(', ', $generated) . '.');
    }

    private static function createVarDirectories(string $rootDir): void
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
                self::error('Impossible de créer ' . $dir . '.');
                continue;
            }

            // Inscriptible par l'utilisateur du conteneur, quel qu'il soit (contourne l'umask).
            chmod($path, 0o777);
        }

        self::info(' -> Dossiers var/ garantis présents.');
    }

    /**
     * Le dépôt du squelette ignore son composer.lock ; un projet créé doit versionner
     * le sien pour des installations reproductibles. Retire le bloc balisé du .gitignore.
     */
    private static function trackComposerLock(string $rootDir): void
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
            self::error('Impossible de mettre à jour .gitignore.');
            return;
        }

        self::info(' -> composer.lock versionné (retiré du .gitignore).');
    }

    private static function info(string $message): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    private static function error(string $message): void
    {
        fwrite(STDERR, 'Erreur : ' . $message . PHP_EOL);
    }
}
