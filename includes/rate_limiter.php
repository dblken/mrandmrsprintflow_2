<?php
/**
 * Rate Limiter
 * PrintFlow - Printing Shop PWA
 *
 * Database-backed sliding-window rate limiter.
 * Uses the `rate_limit_log` table, auto-created on first use.
 *
 * Usage:
 *   RateLimiter::isBlocked('login', $ip, 5, 60)   // blocked after 5 hits in 60 s?
 *   RateLimiter::hit('login', $ip)                 // record one attempt
 *   RateLimiter::clear('login', $ip)               // clear on success
 *
 * Recommended limits (from the security plan):
 *   login        → 5 per 60 s per IP
 *   pwd_reset_ip → 3 per 600 s (10 min) per IP
 *   otp_request  → 3 per 600 s per identifier
 *   payment_verify → 5 per 300 s per order
 */

require_once __DIR__ . '/db.php';

class RateLimiter
{
    private const LOGIN_LOCKOUT_SCHEDULE = [60, 120, 300, 600];

    /**
     * Check whether the given action + key has exceeded its limit.
     *
     * @param string $action  Logical action name, e.g. 'login', 'pwd_reset_ip'
     * @param string $key     Per-subject identifier (IP, email, order ID, …)
     * @param int    $limit   Maximum allowed attempts in the window
     * @param int    $window  Window length in seconds
     * @return bool  true = blocked (limit exceeded), false = allowed
     */
    public static function isBlocked(string $action, string $key, int $limit, int $window): bool
    {
        self::ensureTable();
        self::ensureStateTable();
        self::maybeCleanup();

        $activeLock = self::getActiveLockout($action, $key);
        if ($activeLock !== null) {
            return true;
        }

        $rows = db_query(
            "SELECT COUNT(*) AS cnt
               FROM rate_limit_log
              WHERE action       = ?
                AND lookup_key   = ?
                AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
            'ssi',
            [$action, $key, $window]
        );

        return isset($rows[0]['cnt']) && (int) $rows[0]['cnt'] >= $limit;
    }

    public static function getActiveLockout(string $action, string $key): ?array
    {
        self::ensureStateTable();

        $rows = db_query(
            "SELECT lockout_level,
                    GREATEST(TIMESTAMPDIFF(SECOND, NOW(), lockout_until), 0) AS remaining_seconds
               FROM rate_limit_state
              WHERE action = ?
                AND lookup_key = ?
                AND lockout_until IS NOT NULL
                AND lockout_until > NOW()
              LIMIT 1",
            'ss',
            [$action, $key]
        );

        if (empty($rows)) {
            return null;
        }

        return [
            'lockout_level' => (int)($rows[0]['lockout_level'] ?? 0),
            'remaining_seconds' => (int)($rows[0]['remaining_seconds'] ?? 0),
        ];
    }

    public static function recordFailure(string $action, string $key, int $limit, int $window): array
    {
        self::ensureTable();
        self::ensureStateTable();

        $activeLock = self::getActiveLockout($action, $key);
        if ($activeLock !== null) {
            return [
                'locked' => true,
                'remaining_seconds' => (int)$activeLock['remaining_seconds'],
                'lockout_level' => (int)$activeLock['lockout_level'],
            ];
        }

        self::hit($action, $key);

        $rows = db_query(
            "SELECT COUNT(*) AS cnt
               FROM rate_limit_log
              WHERE action = ?
                AND lookup_key = ?
                AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
            'ssi',
            [$action, $key, $window]
        );
        $count = (int)($rows[0]['cnt'] ?? 0);

        if ($count < $limit) {
            return [
                'locked' => false,
                'attempts' => $count,
            ];
        }

        $stateRows = db_query(
            "SELECT lockout_level
               FROM rate_limit_state
              WHERE action = ?
                AND lookup_key = ?
              LIMIT 1",
            'ss',
            [$action, $key]
        );
        $previousLevel = (int)($stateRows[0]['lockout_level'] ?? 0);
        $nextLevel = max(1, $previousLevel + 1);
        $duration = self::lockoutDurationSeconds($nextLevel);
        $lockoutUntil = date('Y-m-d H:i:s', time() + $duration);

        db_execute(
            "INSERT INTO rate_limit_state (action, lookup_key, lockout_level, lockout_until, updated_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                 lockout_level = VALUES(lockout_level),
                 lockout_until = VALUES(lockout_until),
                 updated_at = NOW()",
            'ssis',
            [$action, $key, $nextLevel, $lockoutUntil]
        );

        db_execute(
            "DELETE FROM rate_limit_log WHERE action = ? AND lookup_key = ?",
            'ss',
            [$action, $key]
        );

        return [
            'locked' => true,
            'remaining_seconds' => $duration,
            'lockout_level' => $nextLevel,
        ];
    }

    /**
     * Record a failure and apply one fixed lockout once the limit is reached.
     * This is intended for flows such as OTP verification where the required
     * lock duration must not use the progressive login schedule.
     */
    public static function recordFixedFailure(
        string $action,
        string $key,
        int $limit,
        int $window,
        int $lockoutSeconds
    ): array {
        self::ensureTable();
        self::ensureStateTable();

        $activeLock = self::getActiveLockout($action, $key);
        if ($activeLock !== null) {
            return [
                'locked' => true,
                'remaining_seconds' => (int)$activeLock['remaining_seconds'],
            ];
        }

        self::hit($action, $key);
        $rows = db_query(
            "SELECT COUNT(*) AS cnt
               FROM rate_limit_log
              WHERE action = ?
                AND lookup_key = ?
                AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
            'ssi',
            [$action, $key, $window]
        );
        $count = (int)($rows[0]['cnt'] ?? 0);
        if ($count < $limit) {
            return ['locked' => false, 'attempts' => $count];
        }

        $lockoutSeconds = max(1, $lockoutSeconds);
        db_execute(
            "INSERT INTO rate_limit_state (action, lookup_key, lockout_level, lockout_until, updated_at)
             VALUES (?, ?, 1, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())
             ON DUPLICATE KEY UPDATE
                 lockout_level = 1,
                 lockout_until = DATE_ADD(NOW(), INTERVAL ? SECOND),
                 updated_at = NOW()",
            'ssii',
            [$action, $key, $lockoutSeconds, $lockoutSeconds]
        );

        db_execute(
            "DELETE FROM rate_limit_log WHERE action = ? AND lookup_key = ?",
            'ss',
            [$action, $key]
        );

        return [
            'locked' => true,
            'remaining_seconds' => $lockoutSeconds,
        ];
    }

    /**
     * Record one attempt for the given action + key.
     */
    public static function hit(string $action, string $key): void
    {
        self::ensureTable();

        db_execute(
            "INSERT INTO rate_limit_log (action, lookup_key, attempted_at) VALUES (?, ?, NOW())",
            'ss',
            [$action, $key]
        );
    }

    /**
     * Remove all recorded attempts for this action + key.
     * Call after a successful login / OTP verification to reset the counter.
     */
    public static function clear(string $action, string $key): void
    {
        self::ensureTable();
        self::ensureStateTable();

        db_execute(
            "DELETE FROM rate_limit_log WHERE action = ? AND lookup_key = ?",
            'ss',
            [$action, $key]
        );

        db_execute(
            "DELETE FROM rate_limit_state WHERE action = ? AND lookup_key = ?",
            'ss',
            [$action, $key]
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /** Avoid repeated CREATE TABLE calls within a single request. */
    private static bool $tableEnsured = false;
    private static bool $stateTableEnsured = false;

    private static function ensureTable(): void
    {
        if (self::$tableEnsured) {
            return;
        }

        db_execute(
            "CREATE TABLE IF NOT EXISTS rate_limit_log (
                id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                action       VARCHAR(64)  NOT NULL,
                lookup_key   VARCHAR(255) NOT NULL,
                attempted_at DATETIME     NOT NULL,
                INDEX idx_lookup (action, lookup_key, attempted_at)
            )"
        );

        self::$tableEnsured = true;
    }

    private static function ensureStateTable(): void
    {
        if (self::$stateTableEnsured) {
            return;
        }

        db_execute(
            "CREATE TABLE IF NOT EXISTS rate_limit_state (
                action         VARCHAR(64)  NOT NULL,
                lookup_key     VARCHAR(255) NOT NULL,
                lockout_level  INT NOT NULL DEFAULT 0,
                lockout_until  DATETIME DEFAULT NULL,
                updated_at     DATETIME NOT NULL,
                PRIMARY KEY (action, lookup_key)
            )"
        );

        self::$stateTableEnsured = true;
    }

    private static function lockoutDurationSeconds(int $level): int
    {
        $level = max(1, $level);
        if (isset(self::LOGIN_LOCKOUT_SCHEDULE[$level - 1])) {
            return self::LOGIN_LOCKOUT_SCHEDULE[$level - 1];
        }
        return 600 + (($level - 4) * 300);
    }

    /**
     * Probabilistic cleanup of old rows (1-in-100 chance per request).
     * Deletes entries older than 24 hours to prevent table bloat.
     */
    private static function maybeCleanup(): void
    {
        if (rand(1, 100) === 1) {
            db_execute(
                "DELETE FROM rate_limit_log WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );
            db_execute(
                "DELETE FROM rate_limit_state
                  WHERE lockout_until IS NOT NULL
                    AND lockout_until < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );
        }
    }
}
