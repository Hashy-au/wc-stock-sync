<?php
/**
 * SKU utilities.
 *
 * @package Hashy_AU
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Hashy_AU_SKU {

    /**
     * Normalize SKU for matching.
     *
     * @param string $sku Raw.
     * @return string Normalized.
     */
    public static function normalize(string $sku): string {
        $sku = strtoupper((string) $sku);
        $sku = trim($sku);

        // Remove common 3-letter prefix like PRM- or ABA_ .
        $sku = preg_replace('/^[A-Z0-9]{3}[-_\s]/', '', $sku);

        // Remove all separators/spaces.
        $sku = preg_replace('/[-_\s]+/', '', $sku);

        return (string) $sku;
    }
}
