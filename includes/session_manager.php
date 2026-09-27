<?php
/**
 * Centralized Session Manager
 * PrintFlow - Printing Shop PWA
 *
 * Handles the full secure session lifecycle:
 *   start()      — configure secure cookies, detect fingerprint/timeout, call once per request
 *   regenerate() — bind fingerprint + rotate ID after login/register
 *   destroy()    — full logout including cookie deletion
 *   setNoCacheHeaders() — prevent browser caching of protected pages
 *   wasTimedOut()       — true if this request's session was just expired
 *
 * PRODUCTION NOTE: Change SESSION_HMAC_SECRET to a strong random value unique per deployment.
 */

require_once __DIR__ . '/env.php';
printflow_load_project_env();

$printflowSessionEnvironment = strtolower(trim((string)(printflow_env('PRINTFLOW_APP_ENV') ?: '')));
$printflowSessionHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$printflowSessionIsProduction = $printflowSessionEnvironment === 'production'
    || ($printflowSessionEnvironment === '' && str_contains($printflowSessionHost, 'mrandmrsprintflow.com'));
$printflowSessionSecret = trim((string)(printflow_env('PRINTFLOW_SESSION_HMAC_SECRET') ?: ''));

/**
 * Inactivity timeout for sessions without "Remember me" (seconds).
 * 8h default — 1h was too aggressive and caused repeat logins / stale CSRF on long-open tabs.
 */
if (!defined('SESSION_LIFETIME')) {
    define('SESSION_LIFETIME', 8 * 3600);
}

/**
 * When true, a User-Agent change invalidates the session (can false-positive on browser updates / mobile).
 * Default false: rely on HTTPS + HttpOnly cookies; set true only if you need stricter binding.
 */
if (!defined('PRINTFLOW_ENFORCE_FINGERPRINT')) {
    define('PRINTFLOW_ENFORCE_FINGERPRINT', printflow_env_bool('PRINTFLOW_ENFORCE_FINGERPRINT', false));
}

if (!defined('SESSION_HMAC_SECRET')) {
    if ($printflowSessionSecret === '' && $printflowSessionIsProduction) {
        error_log('[session] CRITICAL: PRINTFLOW_SESSION_HMAC_SECRET is not configured in production. Using legacy default so the site remains available; set PRINTFLOW_SESSION_HMAC_SECRET in the server environment.');
        $printflowSessionSecret = 'PrintFlow-Session-HMAC-Secret-Change-In-Production-2024';
    }
    if ($printflowSessionSecret === '') {
        $printflowSessionSecret = 'PrintFlow-Development-Only-Session-Secret';
        error_log('[session] Development HMAC secret is in use; configure PRINTFLOW_SESSION_HMAC_SECRET before production.');
    }
    define('SESSION_HMAC_SECRET', $printflowSessionSecret);
}

if (!defined('REMEMBER_ME_STAFF_DAYS')) {
    define('REMEMBER_ME_STAFF_DAYS', 30);
}

if (!defined('REMEMBER_ME_CUSTOMER_DAYS')) {
    define('REMEMBER_ME_CUSTOMER_DAYS', 90);
}

if (!defined('PRINTFLOW_SESSION_NAME')) {
    define('PRINTFLOW_SESSION_NAME', 'PRINTFLOWSESSID');
}

if (!defined('REMEMBER_ME_SESSION_LIFETIME')) {
    define('REMEMBER_ME_SESSION_LIFETIME', 90 * 86400);
}

if (!defined('PRINTFLOW_REMEMBER_COOKIE')) {
    define('PRINTFLOW_REMEMBER_COOKIE', 'PRINTFLOWREMEMBER');
}

class SessionManager
{
    /** True when this request destroyed a session due to inactivity timeout. */
    private static bool $timed_out = false;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Start and validate the session.
     * Must be called before any output and before accessing $_SESSION.
     * Idempotent — safe to call even if session is already active.
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            // Session already active — just refresh activity timer for logged-in users
            if (isset($_SESSION['user_id'])) {
                $_SESSION['_last_activity'] = time();
                if (!empty($_SESSION['_remember_me'])) {
                    self::refreshRememberCookies();
                }
            }
            return;
        }

        $remembered = self::hasRememberCookie();
        session_name(PRINTFLOW_SESSION_NAME);
        self::cleanupLegacyCookies();
        $cookieLifetime = $remembered ? REMEMBER_ME_SESSION_LIFETIME : 0;

        // Secure session cookie parameters (must be set before session_start)
        session_set_cookie_params([
            'lifetime' => $cookieLifetime,
            'path'     => '/',
            'domain'   => self::cookieDomain(),
            'secure'   => self::isHttps(),         // HTTPS-only in production
            'httponly' => true,                    // Inaccessible to JavaScript
            'samesite' => 'Lax',                   // Allow cookies on top-level nav (e.g. after login redirect)
        ]);

        // Prefer longer, harder-to-guess session IDs
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) REMEMBER_ME_SESSION_LIFETIME);

        session_start();

        // Validate existing authenticated session integrity
        if (isset($_SESSION['user_id'])) {
            if ($remembered) {
                $_SESSION['_remember_me'] = true;
            }

            if (!self::validateFingerprint()) {
                // Fingerprint mismatch — possible session hijacking; destroy and restart clean
                error_log(sprintf(
                    '[PrintFlow] Session fingerprint mismatch — user_id=%s IP=%s UA=%s',
                    $_SESSION['user_id'],
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 80)
                ));
                self::destroyAndRestart();
                return;
            }

            if (self::isTimedOut()) {
                self::$timed_out = true;
                self::destroyAndRestart();
                return;
            }

            // Valid session — update last activity
            $_SESSION['_last_activity'] = time();
            if (!empty($_SESSION['_remember_me'])) {
                self::refreshRememberCookies();
            }
        }
    }

    /**
     * Call immediately after a successful login or registration.
     * Rotates the session ID (prevents session fixation) and binds the session
     * to the current client fingerprint (IP + User-Agent).
     */
    public static function regenerate(): void
    {
        session_regenerate_id(true); // true = delete old session file on disk
        unset($_SESSION['_remember_me']);
        $_SESSION['_fingerprint']   = self::buildFingerprint();
        $_SESSION['_last_activity'] = time();
        $_SESSION['_created_at']    = time();
    }

    /**
     * Fully destroy the session: clear data, invalidate cookie, call session_destroy().
     * Use this for logout and for forced session termination.
     */
    public static function destroy(): void
    {
        $_SESSION = [];

        // Expire the session cookie immediately
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            $path = $p['path'] ?: '/';
            $domain = (string)($p['domain'] ?? self::cookieDomain());

            // Expire current cookie params plus common legacy domain/path variants.
            foreach (self::cookieDomainsToClear($domain) as $cookieDomain) {
                foreach (self::cookiePathsToClear($path) as $cookiePath) {
                    self::expireCookie(session_name(), $cookiePath, $cookieDomain);
                }
                self::expireCookie(PRINTFLOW_REMEMBER_COOKIE, '/', $cookieDomain);
            }

            self::expireLegacyCookies();
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * Set HTTP headers that prevent browsers from caching a protected page.
     * Call at the top of every page that requires authentication, before any output.
     */
    public static function setNoCacheHeaders(): void
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
            header('Pragma: no-cache');
            header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
            header('X-Accel-Expires: 0');
        }
    }

    /**
     * Returns true if the session for this request was destroyed due to inactivity.
     * Use in require_auth() to distinguish "timeout" from "not logged in".
     */
    public static function wasTimedOut(): bool
    {
        return self::$timed_out;
    }

    /**
     * Extend session cookie lifetime for "Remember Me" functionality.
     * @param int $days Number of days to keep the session alive
     */
    public static function applyRememberMe(int $days): void
    {
        $lifetime = $days * 86400; // Convert days to seconds
        $_SESSION['_remember_me'] = true;
        self::setPersistentCookie(session_name(), session_id(), $lifetime);
        self::setPersistentCookie(PRINTFLOW_REMEMBER_COOKIE, '1', $lifetime);
    }

    /**
     * Force PHP to persist the session immediately.
     */
    public static function commit(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public static function clearRememberMe(): void
    {
        unset($_SESSION['_remember_me']);
        self::expireCookie(PRINTFLOW_REMEMBER_COOKIE, '/', self::cookieDomain());
        if (self::cookieDomain() !== '') {
            self::expireCookie(PRINTFLOW_REMEMBER_COOKIE, '/', ltrim(self::cookieDomain(), '.'));
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function validateFingerprint(): bool
    {
        if (!PRINTFLOW_ENFORCE_FINGERPRINT) {
            return true;
        }
        if (!isset($_SESSION['_fingerprint'])) {
            return true;
        }

        $current = self::buildFingerprint();
        if (hash_equals($_SESSION['_fingerprint'], $current)) {
            return true;
        }

        // Local development often switches between ::1 and 127.0.0.1
        // We log it but allow it locally to prevent constant session death
        if (($_SERVER['REMOTE_ADDR'] ?? '') === '::1' || ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1') {
             error_log("[PrintFlow] Local Fingerprint Mismatch (Allowed): " . $_SESSION['_fingerprint'] . " vs " . $current);
             return true;
        }
        
        return false;
    }

    private static function buildFingerprint(): string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return hash_hmac('sha256', $ua, SESSION_HMAC_SECRET);
    }

    private static function isTimedOut(): bool
    {
        if (!isset($_SESSION['_last_activity'])) {
            return false;
        }
        $lifetime = !empty($_SESSION['_remember_me'])
            ? REMEMBER_ME_SESSION_LIFETIME
            : SESSION_LIFETIME;
        return (time() - (int) $_SESSION['_last_activity']) > $lifetime;
    }

    private static function destroyAndRestart(): void
    {
        self::destroy();
        session_start();
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    }

    private static function cookieSameSite(): string
    {
        return 'Lax';
    }

    private static function cookieDomain(): string
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host);

        if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return '';
        }

        if (substr_count($host, '.') < 1) {
            return '';
        }

        // Ensure a single, consistent cookie domain across apex + subdomains.
        // This prevents duplicate cookies (same name, different Domain) that can
        // cause PHP to read the wrong session id and appear "logged out" on some requests.
        if ($host === 'mrandmrsprintflow.com' || substr($host, -strlen('.mrandmrsprintflow.com')) === '.mrandmrsprintflow.com') {
            return '.mrandmrsprintflow.com';
        }

        return $host;
    }

    private static function hasRememberCookie(): bool
    {
        return !empty($_COOKIE[PRINTFLOW_REMEMBER_COOKIE]);
    }

    private static function setPersistentCookie(string $name, string $value, int $lifetime): void
    {
        setcookie($name, $value, [
            'expires'  => time() + $lifetime,
            'path'     => '/',
            'domain'   => self::cookieDomain(),
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => self::cookieSameSite(),
        ]);
    }

    private static function refreshRememberCookies(): void
    {
        self::setPersistentCookie(session_name(), session_id(), REMEMBER_ME_SESSION_LIFETIME);
        self::setPersistentCookie(PRINTFLOW_REMEMBER_COOKIE, '1', REMEMBER_ME_SESSION_LIFETIME);
    }

    private static function expireCookie(string $name, string $path, string $domain): void
    {
        setcookie($name, '', [
            'expires'  => time() - 42000,
            'path'     => $path,
            'domain'   => $domain,
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => self::cookieSameSite(),
        ]);
    }

    private static function expireLegacyCookies(): void
    {
        $domain = self::cookieDomain();
        foreach (self::cookieDomainsToClear($domain) as $cookieDomain) {
            foreach (self::cookiePathsToClear('/') as $path) {
                self::expireCookie('PHPSESSID', $path, $cookieDomain);
                self::expireCookie(PRINTFLOW_SESSION_NAME, $path, $cookieDomain);
            }
        }
    }

    /**
     * Remove only stale cookies from old paths (e.g. /printflow when site moved to root).
     * Do NOT expire path=/ for the live session: PHP may not re-issue Set-Cookie on read-only
     * requests, so the browser would drop the session cookie and the next page load looks logged out.
     */
    private static function cleanupLegacyCookies(): void
    {
        if (headers_sent()) {
            return;
        }

        $domain = self::cookieDomain();
        $legacyOnlyPaths = ['/printflow', '/staff'];
        foreach (self::cookieDomainsToClear($domain) as $cookieDomain) {
            foreach ($legacyOnlyPaths as $path) {
                self::expireCookie('PHPSESSID', $path, $cookieDomain);
                self::expireCookie(PRINTFLOW_SESSION_NAME, $path, $cookieDomain);
            }
        }
    }

    /**
     * Return a list of cookie domain variants to clear (host-only + dot/no-dot variants).
     *
     * PHP can behave unexpectedly when multiple cookies with the same name exist.
     * Clearing common variants reduces "works on localhost but not production" issues
     * after a deployment changes cookie Domain handling.
     *
     * @param string $domain The preferred domain, e.g. ".example.com" or "example.com" or ""
     * @return array<int, string>
     */
    private static function cookieDomainsToClear(string $domain): array
    {
        $domain = (string)$domain;
        $domain = trim($domain);

        $domains = [''];
        if ($domain !== '') {
            $domains[] = $domain;
            $domains[] = ltrim($domain, '.');
            if ($domain[0] !== '.') {
                $domains[] = '.' . $domain;
            }
        }

        $unique = [];
        foreach ($domains as $d) {
            $d = (string)$d;
            if ($d === null) continue;
            $unique[$d] = true;
        }
        return array_keys($unique);
    }

    /**
     * Return common cookie path variants to clear.
     *
     * @param string $currentPath The current cookie path (usually "/")
     * @return array<int, string>
     */
    private static function cookiePathsToClear(string $currentPath): array
    {
        $currentPath = $currentPath !== '' ? $currentPath : '/';

        $paths = [
            '/',
            '/printflow',
            '/staff',
            $currentPath,
        ];

        $unique = [];
        foreach ($paths as $p) {
            $p = (string)$p;
            if ($p === '') $p = '/';
            $unique[$p] = true;
        }
        return array_keys($unique);
    }

}
