<?php
/**
 * Route prefix helpers for running Numok behind a path prefix (e.g., /affiliates).
 *
 * Overrides header() in controller and middleware namespaces so that
 * Location redirects automatically include ROUTE_PREFIX.
 * Must be loaded after ROUTE_PREFIX constant is defined in public/index.php.
 */

namespace Numok\Controllers {
    function header(string $header, bool $replace = true, int $response_code = 0): void {
        $header = \_numok_prefix_location($header);
        if ($response_code) {
            \header($header, $replace, $response_code);
        } else {
            \header($header, $replace);
        }
    }
}

namespace Numok\Middleware {
    function header(string $header, bool $replace = true, int $response_code = 0): void {
        $header = \_numok_prefix_location($header);
        if ($response_code) {
            \header($header, $replace, $response_code);
        } else {
            \header($header, $replace);
        }
    }
}

namespace {
    /**
     * Rewrite Location headers to include the route prefix.
     * e.g., "Location: /login" becomes "Location: /affiliates/login"
     */
    function _numok_prefix_location(string $header): string {
        if (defined('ROUTE_PREFIX') && ROUTE_PREFIX !== '') {
            // Check if this is a Location header with an absolute path
            if (preg_match('/^Location:\s*\/(.*)$/i', $header, $m)) {
                $path = '/' . $m[1];
                // Don't double-prefix
                if (strpos($path, ROUTE_PREFIX . '/') !== 0 && $path !== ROUTE_PREFIX) {
                    return 'Location: ' . ROUTE_PREFIX . $path;
                }
            }
        }
        return $header;
    }
}
