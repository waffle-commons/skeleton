<?php

declare(strict_types=1);

namespace App\Factory;

use RuntimeException;
use Waffle\Commons\Contracts\Auth\Constant as AuthConstant;
use Waffle\Commons\Contracts\Config\ConfigInterface;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Contracts\Security\Csrf\Constant as CsrfConstant;

/**
 * Secrets de l'application, résolus une fois au démarrage du worker.
 *
 * APP_SECRET est le secret racine de l'application : la clé CSRF en dérive
 * (HKDF-SHA256) quand WAFFLE_CSRF_SECRET n'est pas renseigné. Chaque worker, et
 * chaque réplique qui reçoit le même APP_SECRET, signe donc avec la même clé. Le
 * secret du pont d'authentification reste explicite : il est partagé avec d'autres
 * services (la passerelle signe, les backends vérifient), il ne peut donc pas
 * dériver d'un secret propre à cette application.
 *
 * Chaque valeur vient de sa clé de configuration (interpolée depuis
 * l'environnement) ou, à défaut, de l'environnement du processus ; une valeur vide
 * vaut absente. En production, un secret manquant ou plus court que 32 octets
 * refuse le démarrage (fail-closed). Hors production, un secret aléatoire propre
 * au processus permet de démarrer.
 *
 * Extraite d'AppKernelFactory, dont la complexité est au plafond CPLX-04.
 */
final class SecretFactory
{
    /** Variable d'environnement du secret racine. */
    public const string APP_SECRET_ENV_KEY = 'APP_SECRET';

    /**
     * Contexte HKDF de la clé CSRF : une clé dérivée par usage, jamais APP_SECRET
     * lui-même. Changer la version renouvelle la clé et invalide les jetons en cours.
     */
    public const string CSRF_KEY_INFO = 'waffle-commons/csrf/v1';

    private const string APP_SECRET_CONFIG_KEY = 'waffle.secret';

    private const string CSRF_SECRET_CONFIG_KEY = 'waffle.security.csrf.secret';

    private const string AUTH_SECRET_CONFIG_KEY = 'waffle.auth.secret';

    /**
     * Clé de signature des jetons CSRF : WAFFLE_CSRF_SECRET quand il est renseigné,
     * sinon 32 octets dérivés d'APP_SECRET.
     */
    public static function csrf(ConfigInterface $config, string $env): string
    {
        $isProd = $env === Constant::ENV_PROD;
        $explicit = self::read($config, self::CSRF_SECRET_CONFIG_KEY, CsrfConstant::SECRET_ENV_KEY);

        if ($explicit !== null && strlen($explicit) >= CsrfConstant::MIN_SECRET_BYTES) {
            return $explicit;
        }

        // Renseigné mais trop court : une erreur de configuration, que la production
        // ne contourne pas en dérivant la clé d'APP_SECRET.
        if ($explicit !== null && $isProd) {
            throw new RuntimeException(sprintf(
                'Secret CSRF plus court que %d octets en production. Corrigez "%s" ou la variable '
                . 'd\'environnement %s, ou videz-la pour que la clé dérive d\'%s.',
                CsrfConstant::MIN_SECRET_BYTES,
                self::CSRF_SECRET_CONFIG_KEY,
                CsrfConstant::SECRET_ENV_KEY,
                self::APP_SECRET_ENV_KEY,
            ));
        }

        $root = self::read($config, self::APP_SECRET_CONFIG_KEY, self::APP_SECRET_ENV_KEY);

        if ($root !== null && strlen($root) >= CsrfConstant::MIN_SECRET_BYTES) {
            return hash_hkdf('sha256', $root, CsrfConstant::MIN_SECRET_BYTES, self::CSRF_KEY_INFO);
        }

        if ($isProd) {
            throw new RuntimeException(sprintf(
                'Secret CSRF manquant en production : renseignez %s (%d octets ou plus), dont la clé '
                . 'CSRF dérive, ou %s.',
                self::APP_SECRET_ENV_KEY,
                CsrfConstant::MIN_SECRET_BYTES,
                CsrfConstant::SECRET_ENV_KEY,
            ));
        }

        // Hors production : une clé éphémère, propre au processus. Un jeton émis par
        // un worker échoue alors sur un autre ; renseigner APP_SECRET l'évite.
        return random_bytes(CsrfConstant::MIN_SECRET_BYTES);
    }

    /**
     * Secret partagé du pont d'authentification (RFC-021 §4.2), toujours explicite.
     */
    public static function auth(ConfigInterface $config, string $env): string
    {
        $candidate = self::read($config, self::AUTH_SECRET_CONFIG_KEY, AuthConstant::SECRET_ENV_KEY);

        if ($candidate !== null && strlen($candidate) >= AuthConstant::MIN_SECRET_BYTES) {
            return $candidate;
        }

        if ($env === Constant::ENV_PROD) {
            throw new RuntimeException(sprintf(
                'Secret du pont d\'authentification manquant ou plus court que %d octets en production. '
                . 'Renseignez "%s" ou la variable d\'environnement %s.',
                AuthConstant::MIN_SECRET_BYTES,
                self::AUTH_SECRET_CONFIG_KEY,
                AuthConstant::SECRET_ENV_KEY,
            ));
        }

        // Hors production : les assertions émises ne sont vérifiables que par ce processus.
        return random_bytes(AuthConstant::MIN_SECRET_BYTES);
    }

    /**
     * La clé de configuration l'emporte, sinon l'environnement du processus ; vide vaut absent.
     */
    private static function read(ConfigInterface $config, string $key, string $envKey): ?string
    {
        $fromConfig = $config->getString($key);

        if ($fromConfig !== null && $fromConfig !== '') {
            return $fromConfig;
        }

        $fromEnv = getenv($envKey);

        return $fromEnv !== false && $fromEnv !== '' ? $fromEnv : null;
    }
}
