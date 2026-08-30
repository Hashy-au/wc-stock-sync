<?php
/**
 * HMAC signing utilities.
 *
 * @package WC_Stock_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Hashy_AU_Crypto {

    /**
     * Create signature.
     *
     * @param string $secret Shared secret.
     * @param string $timestamp Unix timestamp as string.
     * @param string $body Raw request body.
     * @return string Base64 signature.
     */
    public static function sign(string $secret, string $timestamp, string $body): string {
        $payload = $timestamp . '.' . $body;
        $raw = hash_hmac('sha256', $payload, $secret, true);
        return base64_encode($raw);
    }

    public static function verify(string $secret, string $timestamp, string $body, string $signature, int $max_skew_seconds = 300): bool {
        if (empty($secret) || empty($timestamp) || empty($signature)) {
            return false;
        }

        $ts = (int) $timestamp;
        if ($ts <= 0) {
            return false;
        }

        $now = time();
        if (abs($now - $ts) > $max_skew_seconds) {
            return false;
        }

        $expected = self::sign($secret, (string) $ts, $body);
        return hash_equals($expected, $signature);
    }

    /**
     * Generate a random shared secret.
     *
     * @param int $length Bytes before base64url encoding.
     * @return string
     */
    public static function random_secret(int $length = 48): string {
        $bytes = random_bytes(max(16, $length));
        $b64 = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        return substr($b64, 0, max(32, (int) ($length * 1.3)));
    }
}
