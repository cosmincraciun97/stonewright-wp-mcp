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
		'stonewright_batch_operation_failed' => 'Use only action=add_container|add_widget|update_element|move_element|remove_element. Read schema_requests, call each exact request once, replace only rejected settings, and rerun one consolidated dry-run with require_evidence:true for visual work. Never fall back to php-execute or WP-CLI.',
		'stonewright_elementor_settings_invalid'      => 'Read the rejected setting path, expected control type, and received value from the error. Fetch that exact live control schema, replace only the invalid value, then rerun one consolidated dry-run. Do not strip unrelated settings or duplicate the widget.',
		'stonewright_elementor_readback_failed_restored' => 'The previous document was restored. Re-read the current tree and hash, serialize all later writes to this document, rebuild the smallest mutation against fresh state, and retry one dry-run. Do not force a full-tree rewrite.',
		'stonewright_elementor_size_collapse'         => 'The incoming tree is materially smaller than the live document. Re-read the full tree and use a surgical element mutation. Use force_destructive only for an explicitly approved full replacement with a fresh snapshot.',
		'stonewright_v3_architecture_mismatch'       => 'Read stonewright/elementor-document-health. In a mixed document, target only a returned v3_safe_root with a surgical V3 batch; for Atomic targets read stonewright/elementor-v4-read-atomic-tree and use stonewright/elementor-v4-update-node. Never mutate the mixed root or retry a blocked target. Do not use php-execute.',
		'stonewright_v4_architecture_mismatch'       => 'This document has no Elementor V4 Atomic nodes. Use stonewright/elementor-v3-update-element or stonewright/elementor-v3-batch-mutate for classic V3 trees; call stonewright/elementor-v4-read-atomic-tree only after confirming architecture is v4 or mixed.',
		'stonewright_php_elementor_raw_write_blocked' => 'Raw Elementor writes via php-execute are permanently blocked — do not retry php-execute for this. Use stonewright/elementor-v3-batch-mutate or stonewright/elementor-v3-update-element for V3, or stonewright/elementor-v4-update-node for atomic V4 nodes (dry_run:true first).',
		'stonewright_php_custom_css_write_blocked'   => 'php-execute cannot write Customizer or Elementor custom CSS. Use stonewright/theme-custom-css with dry_run:true, show the user approval_url, exact path, byte counts, and summary, then stop for a human-issued custom_code_grant.',
		'stonewright_custom_code_approval_required'  => 'Custom CSS/HTML requires a human-issued custom_code_grant. Prefer Gutenberg block supports and theme preset slugs, or typed Elementor widget controls. For site-wide CSS run stonewright/theme-custom-css with dry_run:true, show approval_url, path, byte counts, and summary, then stop. Gutenberg <style> also needs allow_raw_html:true. Do not strip the CSS or open the approval page unless the user explicitly asks.',
		'stonewright_raw_html_refused'               => 'An all-raw-HTML Gutenberg tree is refused. Queue named core blocks with attributes, or retry with allow_raw_html:true. Raw <style> still needs a consumed custom_code_grant.',
		'stonewright_css_classes_not_approved'       => 'Elementor _css_classes must be in the approved_css_classes allowlist returned on this error. Use a listed class or native Elementor controls instead of inventing class names.',
		'stonewright_php_code_file_write_blocked'    => 'php-execute permanently blocks theme/plugin/core code-file writes. Never retry the same file write through php-execute. Use stonewright/theme-file-patch with dry_run:true, show the user approval_url, exact path, byte counts, and summary, then stop for a human-issued custom-code grant. Never open or submit the approval page unless the user explicitly asks.',
		'stonewright_php_candidate_invalid'          => 'The complete theme PHP candidate failed syntax validation. Fix the full file (not only the inserted fragment), re-run dry_run, and do not write until validation passes.',
		'stonewright_php_parse_error'                => 'The PHP snippet failed to parse. Resend it as a normal multi-line JSON string with no shell heredoc/base64 wrapper. Only if the received code visibly contains literal \\\\n or \\\\t layout outside strings/comments, retry once with decode_escaped_layout:true; that decoder intentionally refuses heredoc/nowdoc and script/style bodies. php-execute has no dry_run.',
		'stonewright_php_execute_failed'             => 'Read exception_class and exception_line in the error data, fix the snippet, and retry once — php-execute has no dry_run parameter. For Elementor document changes use the typed elementor-v3/v4 abilities instead of raw meta writes. Never write theme/plugin PHP through php-execute.',
		'stonewright_custom_code_grant_required'     => 'Custom PHP/CSS/JS/HTML requires a human-issued one-time grant after dry_run proves the candidate. Show the user approval_url, exact path, byte counts, and change summary, then stop. Do not open the page, issue or retrieve a grant, or apply unless the user explicitly asks. After the human returns the token, retry once with custom_code_grant bound to after_sha256.',
		'stonewright_custom_code_grant_invalid'      => 'The custom-code grant is malformed, expired, already used, or bound to a different candidate. Rerun dry_run, show the refreshed approval URL and exact candidate to the user, then stop. The human issues and returns a fresh one-time grant; never open or submit the approval page unless explicitly asked. Retry the unchanged candidate once.',
		'stonewright_native_gap_required'             => 'Native implementation has not been disproved. Read the live schema and document the exact native methods tried and why each failed before requesting a custom-code grant. Never use php-execute or Sandbox to bypass this refusal.',
		'stonewright_php_read_only_violation'         => 'The snippet declared read_only but attempted a mutation. Keep the inspection read-only, or rerun the intended mutation through the appropriate typed ability. php-execute read_only is not a write bypass.',
		'stonewright_theme_write_smoke_failed'       => 'Theme write was rolled back after fresh-bootstrap smoke failed. Inspect the backup under uploads/stonewright-theme-backups, fix the candidate, dry_run again, then re-apply with a new grant.',
		'stonewright_non_atomic_target'              => 'The target id is not an Atomic node. Re-read with stonewright/elementor-v4-read-atomic-tree and pick an e-* id, or use stonewright/elementor-v3-update-element for classic widgets/containers.',
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
