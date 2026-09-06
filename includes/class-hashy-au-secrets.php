<?php
/**
 * Secret storage: the Host's per-agent shared secrets, the Agent's own
 * shared secret and the optional GitHub update token.
 *
 * Before 0.5.1 these sat inside the autoloaded `hashy_au_settings` option, so
 * they were loaded on every request and appeared in any options export or
 * debug dump. They now live in `hashy_au_secrets`, saved with autoload off
 * and sealed with libsodium secretbox (keyed from wp_salt('auth')) whenever
 * sodium_crypto_secretbox() exists. Sealed values carry a marker prefix;
 * anything without it is read as plain text, so a store written on a site
 * without libsodium, or one written before sealing existed, still reads.
 *
 * The first read after upgrading moves the secrets out of `hashy_au_settings`
 * and removes them from it. Every public getter in Hashy_AU_Settings keeps
 * its signature and shape; this class is the only place that knows where
 * the values really are. Nothing here logs a secret value.
 *
 * If AUTH_KEY or AUTH_SALT in wp-config.php are rotated, sealed values can no
 * longer be opened and read back as empty; the administrator re-enters them.
 *
 * @package Hashy_AU
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Hashy_AU_Secrets {

    public const OPTION = 'hashy_au_secrets';

    /** Prefix on sealed values; a stored value without it is legacy plain text. */
    private const SEAL_MARKER = 'wcss:sb1:';

    private const SETTINGS_OPTION = 'hashy_au_settings';

    /**
     * Decrypted store for this request, or null before the first read.
     *
     * @var array{agent_secret: string, github_token: string, agents: array<string, string>}|null
     */
    private static $cache = null;

    /**
     * The whole store, decrypted.
     *
     * @return array{agent_secret: string, github_token: string, agents: array<string, string>}
     *         agents is keyed by the agent id from the Host settings rows.
     */
    public static function all(): array {
        if (null !== self::$cache) {
            return self::$cache;
        }
        // Set the cache before migrating: the migration writes options, and a
        // registered sanitiser on hashy_au_settings reads back through here.
        self::$cache = self::read_store();
        self::migrate_legacy();
        return self::$cache;
    }

    public static function get_agent_secret(): string {
        return (string) (self::all()['agent_secret'] ?? '');
    }

    public static function get_github_token(): string {
        return (string) (self::all()['github_token'] ?? '');
    }

    public static function get_host_agent_secret(string $agent_id): string {
        $agents = self::all()['agents'];
        return (string) ($agents[$agent_id] ?? '');
    }

    /**
     * Persist the store: plain values in, sealed values out, autoload off.
     *
     * @param array $store Same shape as all() returns.
     */
    public static function save(array $store): void {
        $store = self::normalise($store);
        $sealed = [
            'v' => 1,
            'agent_secret' => self::seal($store['agent_secret']),
            'github_token' => self::seal($store['github_token']),
            'agents' => [],
        ];
        foreach ($store['agents'] as $id => $secret) {
            $sealed['agents'][(string) $id] = self::seal((string) $secret);
        }
        update_option(self::OPTION, $sealed, false);
        self::$cache = $store;
    }

    public static function can_seal(): bool {
        return function_exists('sodium_crypto_secretbox')
            && function_exists('sodium_crypto_secretbox_open')
            && defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES');
    }

    private static function read_store(): array {
        $raw = get_option(self::OPTION, []);
        if (!is_array($raw)) {
            $raw = [];
        }
        $store = [
            'agent_secret' => self::open((string) ($raw['agent_secret'] ?? '')),
            'github_token' => self::open((string) ($raw['github_token'] ?? '')),
            'agents' => [],
        ];
        $agents = (isset($raw['agents']) && is_array($raw['agents'])) ? $raw['agents'] : [];
        foreach ($agents as $id => $sealed) {
            $store['agents'][(string) $id] = self::open((string) $sealed);
        }
        return $store;
    }

    /**
     * One-time move out of hashy_au_settings. Runs on the first read of the
     * store after the upgrade; once the old option carries none of the keys
     * this is a no-op. A legacy value wins over one already in the store (a
     * downgrade to 0.5.0 would have written the newer value to the old place).
     * Also covers the two pre-0.5.0 field names for the Agent secret.
     */
    private static function migrate_legacy(): void {
        $legacy = get_option(self::SETTINGS_OPTION, []);
        if (!is_array($legacy)) {
            return;
        }

        $store = self::$cache;
        $found = false;

        if (isset($legacy['agent']) && is_array($legacy['agent'])) {
            foreach (['shared_secret', 'host_shared_secret'] as $field) {
                if (!array_key_exists($field, $legacy['agent'])) {
                    continue;
                }
                $found = true;
                $value = (string) $legacy['agent'][$field];
                if ('' !== $value && '' === $store['agent_secret']) {
                    $store['agent_secret'] = $value;
                }
                unset($legacy['agent'][$field]);
            }
        }
        if (array_key_exists('shared_secret', $legacy)) {
            $found = true;
            $value = (string) $legacy['shared_secret'];
            if ('' !== $value && '' === $store['agent_secret']) {
                $store['agent_secret'] = $value;
            }
            unset($legacy['shared_secret']);
        }

        if (isset($legacy['updates']) && is_array($legacy['updates']) && array_key_exists('github_token', $legacy['updates'])) {
            $found = true;
            $token = (string) $legacy['updates']['github_token'];
            if ('' !== $token) {
                $store['github_token'] = $token;
            }
            unset($legacy['updates']['github_token']);
        }

        if (isset($legacy['host']['agents']) && is_array($legacy['host']['agents'])) {
            foreach ($legacy['host']['agents'] as $i => $agent) {
                if (!is_array($agent) || !array_key_exists('shared_secret', $agent)) {
                    continue;
                }
                $found = true;
                $id = (string) ($agent['id'] ?? '');
                if ('' === $id) {
                    // Same rule the settings sanitiser applies to a row without an id.
                    $id = md5(untrailingslashit((string) ($agent['url'] ?? '')));
                    $legacy['host']['agents'][$i]['id'] = $id;
                }
                $secret = (string) $agent['shared_secret'];
                if ('' !== $secret) {
                    $store['agents'][$id] = $secret;
                }
                unset($legacy['host']['agents'][$i]['shared_secret']);
            }
        }

        if (!$found) {
            return;
        }

        self::save($store);
        update_option(self::SETTINGS_OPTION, $legacy);

        if (class_exists('Hashy_AU_Logger')) {
            Hashy_AU_Logger::instance()->info('Secrets moved out of hashy_au_settings into hashy_au_secrets (autoload off)', [
                'agents' => count($store['agents']),
                'sealed' => self::can_seal() ? 'yes' : 'no',
            ]);
        }
    }

    /**
     * Coerce any caller-supplied array to the store shape; empty agent
     * secrets are dropped rather than stored.
     */
    private static function normalise(array $store): array {
        $agents = [];
        $raw_agents = (isset($store['agents']) && is_array($store['agents'])) ? $store['agents'] : [];
        foreach ($raw_agents as $id => $secret) {
            $id = (string) $id;
            $secret = (string) $secret;
            if ('' === $id || '' === $secret) {
                continue;
            }
            $agents[$id] = $secret;
        }
        return [
            'agent_secret' => (string) ($store['agent_secret'] ?? ''),
            'github_token' => (string) ($store['github_token'] ?? ''),
            'agents' => $agents,
        ];
    }

    private static function seal(string $plain): string {
        if ('' === $plain || !self::can_seal()) {
            return $plain;
        }
        try {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plain, $nonce, self::key());
        } catch (\Throwable $e) {
            // Sealing failed; keep the secret usable rather than lose it. It
            // still sits in a non-autoloaded option and reads back as plain.
            return $plain;
        }
        return self::SEAL_MARKER . self::b64url_encode($nonce . $cipher);
    }

    private static function open(string $stored): string {
        if ('' === $stored || 0 !== strpos($stored, self::SEAL_MARKER)) {
            return $stored; // Legacy plain text, or empty.
        }
        if (!self::can_seal()) {
            return '';
        }
        $bin = self::b64url_decode(substr($stored, strlen(self::SEAL_MARKER)));
        $nonce_len = (int) SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($bin) <= $nonce_len) {
            return '';
        }
        try {
            $plain = sodium_crypto_secretbox_open(substr($bin, $nonce_len), substr($bin, 0, $nonce_len), self::key());
        } catch (\Throwable $e) {
            return '';
        }
        return is_string($plain) ? $plain : '';
    }

    /** A stable 32-byte key from the auth salt, which never appears in wp-admin. */
    private static function key(): string {
        return hash('sha256', wp_salt('auth'), true);
    }

    private static function b64url_encode(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding for binary ciphertext, not obfuscation.
    }

    private static function b64url_decode(string $b64url): string {
        $pad = strlen($b64url) % 4;
        if ($pad) {
            $b64url .= str_repeat('=', 4 - $pad);
        }
        $bin = base64_decode(strtr($b64url, '-_', '+/'), true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- transport decoding of binary ciphertext, not obfuscation.
        return is_string($bin) ? $bin : '';
    }
}
