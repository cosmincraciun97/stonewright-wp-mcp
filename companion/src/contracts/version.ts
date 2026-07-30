/**
 * Contract version — single source of truth.
 *
 * Increment MAJOR when any endpoint's request or response shape changes in a
 * backward-incompatible way. PHP CompanionClient reads /health and short-circuits
 * with WP_Error('stonewright_companion_version_mismatch') when the major version
 * differs from EXPECTED_CONTRACT_MAJOR.
 */

export const CONTRACT_VERSION = '1.0.0';

/** Major version extracted at build time for cheap numeric comparison. */
export const CONTRACT_MAJOR = 1;
