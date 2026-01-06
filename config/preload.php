<?php

/**
 * Waffle Preloader Script
 *
 * This script is executed once when the server starts.
 * It loads the framework classes into shared memory (Opcache) to speed up execution.
 */

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}

(function () {
    // The application root (/app in Docker)
    $projectDir = dirname(__DIR__);

    // List of critical directories to preload
    // Targeting the vendor/waffle-commons directory to load the entire framework
    $paths = [
        $projectDir . '/vendor/waffle-commons',
        // Additional critical libraries can be added here
    ];

    // Recursive function to scan and compile
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
                // Ignore test files or binaries
                if (str_contains($path, '/tests/') || str_contains($path, '/bin/')) {
                    continue;
                }

                try {
                    // This is where the magic happens: compiling the file into memory
                    opcache_compile_file($path);
                } catch (Throwable $t) {
                    // Silently ignore preloading errors
                    // (e.g., classes that cannot be loaded without context)
                    error_log("Preloading failed for $path: " . $t->getMessage());
                }
            }
        }
        closedir($handle);
    };

    // Execute preloading
    foreach ($paths as $path) {
        $preload($path);
    }
})();
