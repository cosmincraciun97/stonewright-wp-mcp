<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Maps recurring ability error codes (and ability names) to agent repair guidance.
 *
 * Resolution order: specific error code → per-ability-name fallback → generic.
 */
final class RemediationHints {

	/** @var array<string, string> */
	private const CODE_HINTS = [
		'stonewright_spec_invalid'           => 'Validate the design spec first; read the validation errors in the response and fix each path before re-rendering.',
		'stonewright_confirmation_required'  => 'Issue a token via stonewright/security-issue-confirmation-token and pass it as confirmation_token.',
		'stonewright_confirmation_invalid'   => 'The confirmation token is invalid or expired. Re-issue via stonewright/security-issue-confirmation-token for the current args.',
		'stonewright_permission_denied'      => 'The current user lacks the required capability. Use an admin Application Password or elevate the user role.',
		'stonewright_plugin_missing'         => 'A required third-party plugin is inactive. Install/activate it, or use an alternate ability path that does not depend on it.',
		'stonewright_backup_failed'          => 'Snapshot failed before the write. Check post existence, disk space, and retry; do not write without a successful backup.',
		'stonewright_self_protection'        => 'Stonewright refuses to deactivate or delete itself. Target a different plugin.',
		'stonewright_revision_not_found'     => 'The revision id is missing. List revisions for the parent post first, then restore a valid revision id.',
		'stonewright_user_self_delete'       => 'Cannot delete the authenticated user. Pass a different user id and a valid reassign target.',
		'stonewright_user_not_found'         => 'User id not found. Call stonewright/user-list or user-get first.',
		'stonewright_theme_invalid'          => 'Theme stylesheet is invalid or not installed. List themes and pass a real stylesheet slug.',
		'stonewright_sidebar_not_found'      => 'Sidebar id is unknown. List sidebars/widgets first and use an existing sidebar_id.',
		'stonewright_plugin_active'          => 'Plugin must be deactivated before delete. Deactivate first, then delete.',
		'stonewright_acf_group_not_found'    => 'ACF field group key not found. List field groups and use a real key.',
		'stonewright_cpt_slug_invalid'       => 'CPT slug is invalid. Use a lowercase slug under 32 chars with letters, numbers, and underscores only.',
		'stonewright_taxonomy_invalid'       => 'Taxonomy args are invalid. Provide slug + non-empty object_types.',
		'stonewright_widget_invalid_name'    => 'Widget name/type is invalid. Read the live widget registry before defining or registering.',
		'stonewright_tree_hash_mismatch'     => 'Elementor tree hash is stale. Re-read page structure, recompute mutations against the fresh tree, then retry batch-mutate.',
		'stonewright_element_not_found'      => 'Element id missing from the Elementor tree. Re-read page structure and use a live element id.',
		'stonewright_unknown_widget'         => 'Widget type is not registered on this site. List live widgets / read schema before writing controls.',
		'stonewright_parent_missing'         => 'Parent container id is missing. Create or locate the parent first, then attach children.',
		'sw_test_boom'                       => 'Test-only error: fix the fixture cause before retrying.',
	];

	/** @var array<string, string> */
	private const ABILITY_HINTS = [
		'stonewright/elementor-v3-batch-mutate'       => 'Re-read elementor-v3-get-page-structure, apply only operations that match live element ids, and use dry_run when available before writing.',
		'stonewright/elementor-v3-build-page-from-spec' => 'Validate the Design Spec, confirm Elementor is active, and ensure kit globals exist before rebuild.',
		'stonewright/design-validate-spec'            => 'Fix every path listed in the validator errors; do not render until validation returns ok.',
		'stonewright/content-update-page'             => 'Confirm the page id exists and the user can edit it; re-fetch content before overwriting.',
		'stonewright/wp-cli-run'                      => 'Check stonewright-wp-cli-status, discover the exact command argv, and never use eval/shell entry points.',
	];

	private const GENERIC = 'Re-run the failing ability with dry_run:true where supported, read the per-operation errors and repair hints in the response, fix the cause, then retry once. If it recurs, record it with stonewright/learning-record.';

	/**
	 * @param string $code    Error code from audit _meta.error_code when present.
	 * @param string $ability Ability name (slash form) for per-ability fallback.
	 */
	public static function for_code( string $code, string $ability = '' ): string {
		$code = sanitize_key( $code );
		if ( '' !== $code && isset( self::CODE_HINTS[ $code ] ) ) {
			return self::CODE_HINTS[ $code ];
		}
		$ability = (string) $ability;
		if ( '' !== $ability && isset( self::ABILITY_HINTS[ $ability ] ) ) {
			return self::ABILITY_HINTS[ $ability ];
		}
		return self::GENERIC;
	}
}
