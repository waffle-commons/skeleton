<?php

declare(strict_types=1);

namespace App\Discovery;

use Waffle\Commons\Contracts\EventDispatcher\EventListenerInterface;
use Waffle\Commons\EventDispatcher\Provider\ListenerProvider;

/**
 * Découverte des écouteurs d'événements : scanne un répertoire à la recherche
 * de classes portant `#[AsEventListener]` et les enregistre auprès du
 * `ListenerProvider`.
 *
 * Extrait de `AppKernelFactory` (refactorisation Beta-4) pour garder la fabrique
 * du kernel concentrée sur le câblage : le scan de fichiers + l'extraction de
 * FQCN via `token_get_all` sont une responsabilité distincte.
 */
final class EventListenerDiscovery
{
    /**
     * Scanne `$directory` et enregistre chaque écouteur découvert.
     */
    public static function discover(ListenerProvider $provider, string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $directory,
            \RecursiveDirectoryIterator::SKIP_DOTS,
        ));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo) {
                self::registerFile($provider, $file);
            }
        }
    }

    /**
     * Enregistre un unique fichier s'il déclare bien un écouteur.
     */
    private static function registerFile(ListenerProvider $provider, \SplFileInfo $file): void
    {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            return;
        }

        $content = file_get_contents($file->getPathname());
        if ($content === false || !str_contains($content, 'AsEventListener')) {
            return;
        }

        $fqcn = self::extractClassName($file->getPathname());
        if ($fqcn === '' || !class_exists($fqcn)) {
            return;
        }

        $instance = new $fqcn();
        if ($instance instanceof EventListenerInterface) {
            $provider->register($instance);
        }
    }

    /**
     * Extrait le FQCN d'un fichier PHP via `token_get_all`, en composant le
     * namespace et le nom de classe lus séparément.
     */
    private static function extractClassName(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            return '';
        }

        $tokens = token_get_all($contents);
        $class = self::readClassName($tokens);
        if ($class === '') {
            return '';
        }

        $namespace = self::readNamespace($tokens);

        return $namespace !== '' ? $namespace . '\\' . $class : $class;
    }

    /**
     * Lit le namespace déclaré (chaîne vide si aucun).
     *
     * @param list<string|array{0: int, 1: string, 2: int}> $tokens
     */
    private static function readNamespace(array $tokens): string
    {
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_NAMESPACE) {
                continue;
            }

            $namespace = '';
            while (++$i < $count) {
                $part = $tokens[$i];
                if ($part === ';' || $part === '{') {
                    break;
                }
                if (is_array($part)) {
                    $namespace .= $part[1];
                }
            }

            return $namespace;
        }

        return '';
    }

    /**
     * Lit le premier nom de classe/interface/trait/enum déclaré (chaîne vide
     * si aucun).
     *
     * @param list<string|array{0: int, 1: string, 2: int}> $tokens
     */
    private static function readClassName(array $tokens): string
    {
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || !in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                continue;
            }

            while (++$i < $count) {
                $part = $tokens[$i];
                if (is_array($part) && $part[0] === T_STRING) {
                    return $part[1];
                }
                if ($part === '{') {
                    break;
                }
            }
        }

        return '';
    }
}
