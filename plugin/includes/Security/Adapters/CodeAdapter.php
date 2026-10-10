<?php
/**
 * Change history for code: theme files, Customizer CSS, snippets and sandbox files.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Adapters;

use Stonewright\WpMcp\CustomCode\ProviderRegistry;
use Stonewright\WpMcp\CustomCode\RestoresBodyInterface;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Security\ChangeImage;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\RescueGuard;
use Stonewright\WpMcp\Security\ThemeWriteTransaction;
use Stonewright\WpMcp\Support\Logger;

/**
 * What the code writers call to leave a record in the change ledger, and what restores a record.
 *
 * Recording: a writer calls begin() with the resource as it is, before it writes, and finish() or
 * applied() with the result afterwards. A ledger that cannot be reached, a row it refuses and an
 * exception inside it are logged and never reach the writer: the write goes on and returns what it
 * always returned.
 *
 * Families: theme_file (a file of a theme), custom_code (Customizer CSS, WPCode and Code Snippets
 * snippets) and sandbox (a draft, or the active copy of it). They are the families whose undo needs a
 * person: the caller of a restore holds that approval. The restore functions here do not ask for it, and
 * they are not abilities.
 *
 * Restoring: restore() sends a row to the function of its family. Each one writes the before image back
 * through the writer's own path (ThemeWriteTransaction, the snippet provider, SandboxFiles, the Customizer
 * CSS post), so every gate of that path still applies, and the restore is itself recorded as a rollback
 * row under the change it undoes. A resource the change created has no before image: undoing it removes it.
 * A restore does not claim the row and does not probe the site; the caller does both.
 *
 * Every restore function takes $options['expected_current_sha256']: when it is given, the resource must
 * still hash to it (the after_sha256 of the row, or a value of live_sha256()), or nothing is written.
 */
final class CodeAdapter {

	/** Ledger families this adapter records and restores. */
	public const FAMILIES = [ 'theme_file', 'custom_code', 'sandbox' ];

	private const SNIPPET_PREFIX = 'custom_code_';

	/** @var string|null Parent of the rows recorded while a restore runs. */
	private static ?string $rollback_parent = null;

	/** @var string|null First row recorded while a restore runs. */
	private static ?string $rollback_child = null;

	/** @var string Kind of the rows recorded while a restore runs: rollback, or redo when the restore acts on a rollback row. */
	private static string $rollback_kind = 'rollback';

	// -----------------------------------------------------------------------
	// Recording.
	// -----------------------------------------------------------------------

	/**
	 * Record a change before the write.
	 *
	 * Spec keys: family (theme_file, custom_code or sandbox), resource_type, resource_id and
	 * ability_fallback (the ability name to use outside an ability call) are required. before is the image
	 * of the resource as it is (a string, or an array of fields). created is true when the write creates the
	 * resource, so there is no before image and the undo removes it. restorable false with
	 * restorable_reason marks a change that cannot be undone. change_id is an id the journal uses, journal is
	 * [ journal resource type, journal resource key ] to look that id up, and summary is a short sentence.
	 *
	 * @param array<string, mixed> $spec
	 * @return string|null The change id, or null when nothing was recorded.
	 */
	public static function begin( array $spec ): ?string {
		try {
			$family = isset( $spec['family'] ) && is_string( $spec['family'] ) ? $spec['family'] : '';
			if ( ! in_array( $family, self::FAMILIES, true ) ) {
				return null;
			}
			$record = [
				'ability'       => RescueGuard::current_ability( isset( $spec['ability_fallback'] ) && is_string( $spec['ability_fallback'] ) ? $spec['ability_fallback'] : '' ),
				'family'        => $family,
				'resource_type' => $spec['resource_type'] ?? '',
				'resource_id'   => $spec['resource_id'] ?? '',
				'before'        => $spec['before'] ?? null,
				'summary'       => $spec['summary'] ?? '',
			];
			if ( null !== self::$rollback_parent ) {
				$record['kind']      = self::$rollback_kind;
				$record['parent_id'] = self::$rollback_parent;
			}
			if ( false === ( $spec['restorable'] ?? null ) ) {
				$record['restorable']        = false;
				$record['restorable_reason'] = $spec['restorable_reason'] ?? 'not_restorable';
			} elseif ( ! empty( $spec['created'] ) ) {
				$record['restorable'] = true;
			}
			$link = self::journal_link( $spec );
			if ( '' !== $link ) {
				$record['change_id'] = $link;
			}

			$row = ChangeLedger::record( $record );
			if ( $row instanceof \WP_Error ) {
				Logger::warning( 'change_ledger_record_failed', [ 'code' => $row->get_error_code(), 'family' => $family ] );
				return null;
			}
			$id = (string) $row['change_id'];
			if ( null !== self::$rollback_parent && null === self::$rollback_child ) {
				self::$rollback_child = $id;
			}
			return $id;
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_ledger_record_failed', [ 'error' => $failure::class ] );
			return null;
		}
	}

	/**
	 * Settle a recorded change after the write.
	 *
	 * @param string|null $change_id The id begin() returned; null does nothing.
	 * @param string      $status    A ledger status: verified, failed, rolled_back, rollback_failed or probe_unavailable.
	 * @param mixed       $after     The image the write produced (a string or an array), or null for none.
	 */
	public static function finish( ?string $change_id, string $status, mixed $after = null, string $summary = '' ): void {
		if ( null === $change_id ) {
			return;
		}
		try {
			$result = [ 'status' => $status ];
			if ( null !== $after ) {
				$result['after'] = $after;
			}
			if ( '' !== $summary ) {
				$result['summary'] = $summary;
			}
			$settled = ChangeLedger::settle( $change_id, $result );
			if ( $settled instanceof \WP_Error ) {
				Logger::warning( 'change_ledger_settle_failed', [ 'code' => $settled->get_error_code() ] );
			}
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_ledger_settle_failed', [ 'error' => $failure::class ] );
		}
	}

	/**
	 * Settle a write that was applied and read back. A change that the rescue journal armed in this call
	 * stays armed: the journal reaches its outcome after the site check, and the row follows it. Any other
	 * change is verified by its readback.
	 *
	 * @param mixed $after The image the write produced, or null for none (the resource is gone).
	 */
	public static function applied( ?string $change_id, mixed $after = null, string $summary = '' ): void {
		if ( null === $change_id ) {
			return;
		}
		$status = 'verified';
		try {
			$entry = ChangeJournal::get( $change_id );
			if ( null !== $entry && 'armed' === $entry['state'] ) {
				$status = 'armed';
			}
		} catch ( \Throwable $failure ) {
			unset( $failure );
		}
		self::finish( $change_id, $status, $after, $summary );
	}

	/**
	 * Run a restore: every change recorded while $run executes is a rollback row under $parent.
	 *
	 * @param string      $parent The change the restore undoes.
	 * @param string|null $child  Set to the first row recorded inside, or null when none was.
	 * @param string      $kind   rollback, or redo for the restore of a rollback row.
	 * @return mixed What $run returned.
	 */
	public static function as_rollback( string $parent, callable $run, ?string &$child = null, string $kind = 'rollback' ): mixed {
		$previous_parent       = self::$rollback_parent;
		$previous_child        = self::$rollback_child;
		$previous_kind         = self::$rollback_kind;
		self::$rollback_parent = $parent;
		self::$rollback_child  = null;
		self::$rollback_kind   = 'redo' === $kind ? 'redo' : 'rollback';
		try {
			return $run();
		} finally {
			$child                 = self::$rollback_child;
			self::$rollback_parent = $previous_parent;
			self::$rollback_child  = $previous_child;
			self::$rollback_kind   = $previous_kind;
		}
	}

	/**
	 * The ledger identity of a theme file: the theme folder and the path in it, for example site-a/inc/menu.php.
	 *
	 * @return array{id:string,restorable:bool,reason:string}
	 */
	public static function theme_resource( string $absolute, string $relative ): array {
		$relative = ltrim( str_replace( '\\', '/', $relative ), '/' );
		$slug     = self::theme_slug_of( $absolute );
		if ( '' === $slug ) {
			return [ 'id' => $relative, 'restorable' => false, 'reason' => 'theme_unknown' ];
		}
		$id = $slug . '/' . $relative;
		if ( strlen( $id ) > ChangeLedger::MAX_RESOURCE_ID ) {
			return [ 'id' => $id, 'restorable' => false, 'reason' => 'path_too_long' ];
		}
		return [ 'id' => $id, 'restorable' => true, 'reason' => '' ];
	}

	/**
	 * Start the record of a theme file write.
	 *
	 * @param string $before  The file as it is; ignored when the write creates it.
	 * @param bool   $existed Whether the file exists now.
	 * @param string $journal The journal id of the write, or ''.
	 */
	public static function begin_theme_file( string $absolute, string $relative, string $before, bool $existed, string $summary, string $journal = '' ): ?string {
		$resource = self::theme_resource( $absolute, $relative );
		return self::begin(
			[
				'ability_fallback'  => 'stonewright/theme-file-patch',
				'family'            => 'theme_file',
				'resource_type'     => 'theme_file',
				'resource_id'       => $resource['id'],
				'before'            => $existed ? $before : null,
				'created'           => ! $existed,
				'restorable'        => $resource['restorable'] ? null : false,
				'restorable_reason' => $resource['reason'],
				'change_id'         => $journal,
				'summary'           => $summary,
			]
		);
	}

	/**
	 * The image of a snippet as the ledger keeps it: its body and its metadata.
	 *
	 * @param array<string, mixed> $snippet The snippet as a provider loads it (code, title, language, active, scope).
	 * @return array<string, mixed>
	 */
	public static function snippet_image( string $provider, string $target_id, string $path, array $snippet ): array {
		return [
			'provider'  => $provider,
			'target_id' => $target_id,
			'path'      => $path,
			'title'     => (string) ( $snippet['title'] ?? '' ),
			'language'  => (string) ( $snippet['language'] ?? '' ),
			'active'    => (bool) ( $snippet['active'] ?? false ),
			'scope'     => (string) ( $snippet['scope'] ?? '' ),
			'body'      => (string) ( $snippet['code'] ?? $snippet['body'] ?? '' ),
		];
	}

	/**
	 * Start the record of a snippet write, with the snippet as it is.
	 *
	 * @param array<string, mixed> $snippet
	 */
	public static function begin_snippet( string $provider, string $target_id, string $path, array $snippet ): ?string {
		return self::begin(
			[
				'ability_fallback' => 'stonewright/custom-code-provider',
				'family'           => 'custom_code',
				'resource_type'    => self::snippet_type( $provider ),
				'resource_id'      => $target_id,
				'before'           => self::snippet_image( $provider, $target_id, $path, $snippet ),
				'journal'          => [ 'custom_code', $provider . ':' . $target_id ],
				'summary'          => $path,
			]
		);
	}

	public static function snippet_type( string $provider ): string {
		return self::SNIPPET_PREFIX . str_replace( '-', '_', sanitize_key( $provider ) );
	}

	// -----------------------------------------------------------------------
	// Restore.
	// -----------------------------------------------------------------------

	/**
	 * Restore a change from its before image, by the family of its row.
	 *
	 * @param array<string, mixed> $options expected_current_sha256, and skip_smoke for a theme file (tests).
	 * @return array{status:string,detail:string,recipe:string,change_id:string,rollback_change_id:?string}
	 *         status is succeeded, noop (already as it was), failed, or not_available (not a code family).
	 */
	public static function restore( string $change_id, array $options = [] ): array {
		$row = ChangeLedger::get( $change_id );
		if ( null === $row ) {
			return self::result( 'failed', 'change_not_found', $change_id, 'ledger' );
		}
		$type = (string) $row['resource_type'];
		if ( 'theme_file' === $type ) {
			return self::restore_theme_file( $change_id, $options );
		}
		if ( 'customizer_css' === $type ) {
			return self::restore_customizer_css( $change_id, $options );
		}
		if ( str_starts_with( $type, self::SNIPPET_PREFIX ) ) {
			return self::restore_snippet( $change_id, $options );
		}
		if ( in_array( $type, [ 'sandbox_draft', 'sandbox_active' ], true ) ) {
			return self::restore_sandbox( $change_id, $options );
		}
		return self::result( 'not_available', 'unsupported_family', $change_id, 'ledger' );
	}

	/**
	 * Write a theme file back as it was, or delete the file the change created, through ThemeWriteTransaction.
	 *
	 * @param array<string, mixed> $options
	 * @return array{status:string,detail:string,recipe:string,change_id:string,rollback_change_id:?string}
	 */
	public static function restore_theme_file( string $change_id, array $options = [] ): array {
		$recipe = 'ledger_theme_file';
		[ $row, $failed ] = self::row_for( $change_id, [ 'theme_file' ], 'theme_file', $recipe );
		if ( null !== $failed || null === $row ) {
			return $failed ?? self::result( 'failed', 'change_not_found', $change_id, $recipe );
		}
		$target = self::theme_target( (string) $row['resource_id'] );
		if ( is_string( $target ) ) {
			return self::result( 'failed', $target, $change_id, $recipe );
		}
		$absolute = $target['absolute'];
		$exists   = is_file( $absolute );
		$current  = $exists ? file_get_contents( $absolute ) : '';
		if ( false === $current ) {
			return self::result( 'failed', 'current_unreadable', $change_id, $recipe );
		}
		$stale = self::stale( $options, ChangeImage::hash_of( $exists ? $current : null, 'theme_file', (string) $row['resource_id'] ), $change_id, $recipe );
		if ( null !== $stale ) {
			return $stale;
		}

		$created = '' === $row['before_ref'];
		$before  = '';
		if ( ! $created ) {
			$image = ChangeLedger::read_image( $change_id, 'before' );
			if ( ! is_string( $image ) ) {
				return self::result( 'failed', 'image_unreadable', $change_id, $recipe );
			}
			$before = $image;
			if ( $exists && hash_equals( hash( 'sha256', $before ), hash( 'sha256', $current ) ) ) {
				return self::result( 'noop', 'already_restored', $change_id, $recipe );
			}
		} elseif ( ! $exists ) {
			return self::result( 'noop', 'already_removed', $change_id, $recipe );
		}

		$child   = null;
		$outcome = self::as_rollback(
			$change_id,
			static function () use ( $created, $target, $current, $before, $options ) {
				if ( $created ) {
					return ThemeWriteTransaction::delete_file(
						[
							'absolute' => $target['absolute'],
							'relative' => $target['relative'],
							'before'   => $current,
						]
					);
				}
				return ThemeWriteTransaction::apply(
					[
						'absolute'          => $target['absolute'],
						'relative'          => $target['relative'],
						'before'            => $current,
						'after'             => $before,
						'language'          => ThemeWriteTransaction::detect_language( $target['relative'] ),
						'max_changed_bytes' => max( ThemeWriteTransaction::DEFAULT_MAX_CHANGED_BYTES, ThemeWriteTransaction::changed_bytes( $current, $before ) ),
						'skip_smoke'        => ! empty( $options['skip_smoke'] ),
					]
				);
			},
			$child,
			self::kind_of( $options )
		);
		if ( $outcome instanceof \WP_Error ) {
			return self::result( 'failed', self::short_code( $outcome ), $change_id, $recipe, $child );
		}
		return self::result( 'succeeded', '', $change_id, $recipe, $child );
	}

	/**
	 * Put the Customizer CSS of a theme back as it was, through the custom CSS post. The CSS before the first
	 * save is empty, so undoing the first save empties the CSS.
	 *
	 * @param array<string, mixed> $options
	 * @return array{status:string,detail:string,recipe:string,change_id:string,rollback_change_id:?string}
	 */
	public static function restore_customizer_css( string $change_id, array $options = [] ): array {
		$recipe = 'ledger_customizer_css';
		[ $row, $failed ] = self::row_for( $change_id, [ 'custom_code' ], 'customizer_css', $recipe );
		if ( null !== $failed || null === $row ) {
			return $failed ?? self::result( 'failed', 'change_not_found', $change_id, $recipe );
		}
		if ( ! function_exists( 'wp_update_custom_css_post' ) || ! function_exists( 'wp_get_custom_css' ) ) {
			return self::result( 'failed', 'customizer_unavailable', $change_id, $recipe );
		}
		$stylesheet = (string) $row['resource_id'];
		$current    = (string) wp_get_custom_css( $stylesheet );
		$stale      = self::stale( $options, ChangeImage::hash_of( $current, 'customizer_css', $stylesheet ), $change_id, $recipe );
		if ( null !== $stale ) {
			return $stale;
		}
		$before = ChangeLedger::read_image( $change_id, 'before' );
		if ( ! is_string( $before ) ) {
			return self::result( 'failed', 'image_unreadable', $change_id, $recipe );
		}
		if ( $before === $current ) {
			return self::result( 'noop', 'already_restored', $change_id, $recipe );
		}

		$child   = null;
		$outcome = self::as_rollback(
			$change_id,
			static function () use ( $stylesheet, $current, $before ): ?string {
				$record = self::begin(
					[
						'ability_fallback' => 'stonewright/theme-custom-css',
						'family'           => 'custom_code',
						'resource_type'    => 'customizer_css',
						'resource_id'      => $stylesheet,
						'before'           => $current,
						'summary'          => 'Restored the Customizer CSS.',
					]
				);
				$written = wp_update_custom_css_post( $before, [ 'stylesheet' => $stylesheet ] );
				if ( is_wp_error( $written ) ) {
					self::finish( $record, 'failed' );
					return self::short_code( $written );
				}
				if ( (string) wp_get_custom_css( $stylesheet ) !== $before ) {
					self::finish( $record, 'failed' );
					return 'readback_mismatch';
				}
				self::applied( $record, $before );
				return null;
			},
			$child,
			self::kind_of( $options )
		);
		return null === $outcome
			? self::result( 'succeeded', '', $change_id, $recipe, $child )
			: self::result( 'failed', (string) $outcome, $change_id, $recipe, $child );
	}

	/**
	 * Write a snippet body back through its provider. The ledger image is the source, so the restore works
	 * after the provider snapshot has expired.
	 *
	 * @param array<string, mixed> $options
	 * @return array{status:string,detail:string,recipe:string,change_id:string,rollback_change_id:?string}
	 */
	public static function restore_snippet( string $change_id, array $options = [] ): array {
		$recipe = 'ledger_snippet';
		[ $row, $failed ] = self::row_for( $change_id, [ 'custom_code' ], null, $recipe );
		if ( null !== $failed || null === $row ) {
			return $failed ?? self::result( 'failed', 'change_not_found', $change_id, $recipe );
		}
		$type        = (string) $row['resource_type'];
		$provider_id = self::snippet_provider( $type );
		if ( '' === $provider_id ) {
			return self::result( 'failed', 'not_a_snippet', $change_id, $recipe );
		}
		$provider = ProviderRegistry::get( $provider_id );
		if ( null === $provider || ! $provider instanceof RestoresBodyInterface ) {
			return self::result( 'failed', 'provider_missing', $change_id, $recipe );
		}
		$target = (string) $row['resource_id'];
		$live   = $provider->read( $target );
		if ( ! is_array( $live ) ) {
			return self::result( 'failed', 'snippet_unavailable', $change_id, $recipe );
		}
		$stale = self::stale( $options, ChangeImage::hash_of( self::snippet_image( $provider_id, $target, (string) ( $live['path'] ?? '' ), $live ), $type, $target ), $change_id, $recipe );
		if ( null !== $stale ) {
			return $stale;
		}
		$image = ChangeLedger::read_image( $change_id, 'before' );
		if ( ! is_array( $image ) || ! isset( $image['body'] ) || ! is_string( $image['body'] ) ) {
			return self::result( 'failed', 'image_unreadable', $change_id, $recipe );
		}
		$body = $image['body'];
		if ( (string) ( $live['code'] ?? '' ) === $body ) {
			return self::result( 'noop', 'already_restored', $change_id, $recipe );
		}

		$child   = null;
		$outcome = self::as_rollback(
			$change_id,
			static function () use ( $provider, $provider_id, $target, $live, $body ): ?string {
				$path   = (string) ( $live['path'] ?? '' );
				$record = self::begin_snippet( $provider_id, $target, $path, $live );
				$result = $provider->restore_body( $target, $body, (bool) ( $live['active'] ?? false ) );
				if ( $result instanceof \WP_Error ) {
					self::finish( $record, 'failed' );
					return self::short_code( $result );
				}
				if ( true !== ( $result['effect_verified'] ?? false ) ) {
					self::finish( $record, 'failed' );
					return 'restore_not_verified';
				}
				self::applied( $record, self::snippet_image( $provider_id, $target, $path, array_merge( $live, [ 'code' => $body ] ) ) );
				return null;
			},
			$child,
			self::kind_of( $options )
		);
		return null === $outcome
			? self::result( 'succeeded', '', $change_id, $recipe, $child )
			: self::result( 'failed', (string) $outcome, $change_id, $recipe, $child );
	}

	/**
	 * Write a sandbox draft or its active copy back through SandboxFiles. A draft or an active copy the
	 * change created is removed.
	 *
	 * @param array<string, mixed> $options
	 * @return array{status:string,detail:string,recipe:string,change_id:string,rollback_change_id:?string}
	 */
	public static function restore_sandbox( string $change_id, array $options = [] ): array {
		$recipe = 'ledger_sandbox';
		[ $row, $failed ] = self::row_for( $change_id, [ 'sandbox' ], null, $recipe );
		if ( null !== $failed || null === $row ) {
			return $failed ?? self::result( 'failed', 'change_not_found', $change_id, $recipe );
		}
		$type = (string) $row['resource_type'];
		$name = (string) $row['resource_id'];
		if ( ! in_array( $type, [ 'sandbox_draft', 'sandbox_active' ], true ) ) {
			return self::result( 'failed', 'not_a_sandbox_file', $change_id, $recipe );
		}
		if ( $name !== basename( $name ) || ! SandboxFiles::valid_name( $name ) || in_array( $name, SandboxFiles::RESERVED_NAMES, true ) ) {
			return self::result( 'failed', 'invalid_name', $change_id, $recipe );
		}
		$active  = 'sandbox_active' === $type;
		$current = self::sandbox_text( $name, $active );
		$stale   = self::stale( $options, ChangeImage::hash_of( $current, $type, $name ), $change_id, $recipe );
		if ( null !== $stale ) {
			return $stale;
		}

		$created = '' === $row['before_ref'];
		$before  = '';
		if ( ! $created ) {
			$image = ChangeLedger::read_image( $change_id, 'before' );
			if ( ! is_string( $image ) ) {
				return self::result( 'failed', 'image_unreadable', $change_id, $recipe );
			}
			$before = $image;
			if ( $before === $current ) {
				return self::result( 'noop', 'already_restored', $change_id, $recipe );
			}
		} elseif ( null === $current ) {
			return self::result( 'noop', 'already_removed', $change_id, $recipe );
		}

		$child   = null;
		$written = self::as_rollback(
			$change_id,
			static function () use ( $created, $active, $name, $before ) {
				if ( $created ) {
					return $active ? SandboxFiles::deactivate( $name ) : SandboxFiles::delete( $name );
				}
				return $active ? SandboxFiles::restore_active( $name, $before ) : SandboxFiles::write( $name, $before );
			},
			$child,
			self::kind_of( $options )
		);
		if ( $written instanceof \WP_Error ) {
			return self::result( 'failed', self::short_code( $written ), $change_id, $recipe, $child );
		}
		return self::result( 'succeeded', '', $change_id, $recipe, $child );
	}

	/**
	 * The hash that the resource of a change has now, comparable with the after_sha256 of its row: the
	 * hash of the image the ledger would store for it. An empty string means the resource does not exist.
	 * For a restore's expected_current_sha256, and for a person to see that the code was edited since.
	 *
	 * @return string|null Null when the resource cannot be read or the row is not a code row.
	 */
	public static function live_sha256( string $change_id ): ?string {
		$row = ChangeLedger::get( $change_id );
		if ( null === $row ) {
			return null;
		}
		$live = self::live_image( $row );
		return null === $live ? null : ChangeImage::hash_of( $live['image'], (string) $row['resource_type'], (string) $row['resource_id'] );
	}

	/**
	 * The resource of a code row as it is now, in the shape of its stored images: the text of a file, or the
	 * fields of a snippet.
	 *
	 * @param array<string, mixed> $row A row of ChangeLedger.
	 * @return array{image:string|array<mixed>|null}|null Null when the resource cannot be read or the row is not a code row. The image is null when the resource does not exist.
	 */
	public static function live_image( array $row ): ?array {
		try {
			if ( ! in_array( $row['family'] ?? '', self::FAMILIES, true ) ) {
				return null;
			}
			$type = (string) $row['resource_type'];
			$id   = (string) $row['resource_id'];
			if ( 'theme_file' === $type ) {
				$target = self::theme_target( $id );
				if ( is_string( $target ) ) {
					return null;
				}
				$text = is_file( $target['absolute'] ) ? file_get_contents( $target['absolute'] ) : null;
				return false === $text ? null : [ 'image' => $text ];
			}
			if ( 'customizer_css' === $type ) {
				return function_exists( 'wp_get_custom_css' ) ? [ 'image' => (string) wp_get_custom_css( $id ) ] : null;
			}
			if ( str_starts_with( $type, self::SNIPPET_PREFIX ) ) {
				$provider_id = self::snippet_provider( $type );
				$provider    = '' === $provider_id ? null : ProviderRegistry::get( $provider_id );
				$live        = null !== $provider ? $provider->read( $id ) : null;
				return is_array( $live ) ? [ 'image' => self::snippet_image( $provider_id, $id, (string) ( $live['path'] ?? '' ), $live ) ] : null;
			}
			if ( in_array( $type, [ 'sandbox_draft', 'sandbox_active' ], true ) && SandboxFiles::valid_name( $id ) ) {
				return [ 'image' => self::sandbox_text( $id, 'sandbox_active' === $type ) ];
			}
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_ledger_live_hash_failed', [ 'error' => $failure::class ] );
		}
		return null;
	}
	// -----------------------------------------------------------------------
	// Helpers.
	// -----------------------------------------------------------------------

	/**
	 * Whether a restore acts on a rollback row, so that its rows are redos.
	 *
	 * @param array<string, mixed> $options
	 */
	private static function kind_of( array $options ): string {
		return isset( $options['kind'] ) && 'redo' === $options['kind'] ? 'redo' : 'rollback';
	}

	/** The provider id of a snippet resource type, or '' when the type is not a known snippet provider. */
	private static function snippet_provider( string $type ): string {
		foreach ( [ 'wpcode', 'code-snippets' ] as $provider ) {
			if ( self::snippet_type( $provider ) === $type ) {
				return $provider;
			}
		}
		return '';
	}

	/**
	 * The journal id to use for a change, when the call armed one and no row has it yet.
	 *
	 * @param array<string, mixed> $spec
	 */
	private static function journal_link( array $spec ): string {
		$id = isset( $spec['change_id'] ) && is_string( $spec['change_id'] ) ? $spec['change_id'] : '';
		if ( '' === $id && is_array( $spec['journal'] ?? null ) && 2 === count( $spec['journal'] ) ) {
			[ $type, $key ] = array_values( $spec['journal'] );
			$id             = (string) RescueGuard::armed_id_for( (string) $type, (string) $key );
		}
		if ( '' === $id || ! ChangeLedger::is_valid_id( $id ) || null !== ChangeLedger::get( $id ) ) {
			return '';
		}
		return $id;
	}

	/** Text of a sandbox draft or active copy, or null when it does not exist. */
	private static function sandbox_text( string $name, bool $active ): ?string {
		if ( $active ) {
			$twin = SandboxFiles::mu_dir() . '/' . SandboxFiles::active_prefix() . $name;
			$text = is_file( $twin ) ? file_get_contents( $twin ) : null;
			return false === $text ? null : $text;
		}
		$text = SandboxFiles::read( $name );
		return is_string( $text ) ? $text : null;
	}

	/**
	 * The row a restore works on, or a failed result.
	 *
	 * @param list<string> $families
	 * @return array{0:array<string, mixed>|null,1:array<string, mixed>|null} The row, or the failed result.
	 */
	private static function row_for( string $change_id, array $families, ?string $resource_type, string $recipe ): array {
		$row = ChangeLedger::get( $change_id );
		if ( null === $row ) {
			return [ null, self::result( 'failed', 'change_not_found', $change_id, $recipe ) ];
		}
		if ( ! in_array( $row['family'], $families, true ) || ( null !== $resource_type && $resource_type !== $row['resource_type'] ) ) {
			return [ null, self::result( 'failed', 'wrong_family', $change_id, $recipe ) ];
		}
		if ( ! $row['restorable'] ) {
			return [ null, self::result( 'failed', 'not_restorable', $change_id, $recipe ) ];
		}
		return [ $row, null ];
	}

	/**
	 * A failed result when the caller expects the resource to hash to something else than it does now.
	 *
	 * @param array<string, mixed> $options
	 * @return array{status:string,detail:string,recipe:string,change_id:string,rollback_change_id:?string}|null
	 */
	private static function stale( array $options, string $live, string $change_id, string $recipe ): ?array {
		$expected = isset( $options['expected_current_sha256'] ) && is_string( $options['expected_current_sha256'] ) ? strtolower( trim( $options['expected_current_sha256'] ) ) : '';
		if ( '' === $expected || hash_equals( $expected, $live ) ) {
			return null;
		}
		return self::result( 'failed', 'current_changed', $change_id, $recipe );
	}

	/**
	 * @return array{status:string,detail:string,recipe:string,change_id:string,rollback_change_id:?string}
	 */
	private static function result( string $status, string $detail, string $change_id, string $recipe, ?string $child = null ): array {
		return [
			'status'             => $status,
			'detail'             => $detail,
			'recipe'             => $recipe,
			'change_id'          => $change_id,
			'rollback_change_id' => $child,
		];
	}

	private static function short_code( \WP_Error $error ): string {
		return substr( (string) preg_replace( '/^stonewright_/', '', sanitize_key( (string) $error->get_error_code() ) ), 0, 64 );
	}

	/**
	 * Absolute path of a theme file from its ledger id, or the reason it cannot be placed.
	 *
	 * @return array{absolute:string,relative:string}|string
	 */
	private static function theme_target( string $resource_id ): array|string {
		$pos = strpos( $resource_id, '/' );
		if ( false === $pos || 0 === $pos ) {
			return 'theme_unknown';
		}
		$slug     = substr( $resource_id, 0, $pos );
		$relative = substr( $resource_id, $pos + 1 );
		if ( '' === $relative || str_contains( $relative, '..' ) || str_contains( $relative, "\0" ) || str_contains( $relative, '\\' ) || str_starts_with( $relative, '/' ) ) {
			return 'path_invalid';
		}
		foreach ( self::theme_roots() as $root ) {
			if ( basename( $root ) !== $slug ) {
				continue;
			}
			$absolute = $root . '/' . $relative;
			return ThemeWriteTransaction::inside_theme_root( $absolute )
				? [ 'absolute' => $absolute, 'relative' => $relative ]
				: 'path_outside_theme';
		}
		return 'theme_not_found';
	}

	/** @return list<string> Normalized folders of the active theme and its parent. */
	private static function theme_roots(): array {
		$roots = [];
		foreach ( [ (string) get_stylesheet_directory(), (string) get_template_directory() ] as $root ) {
			$root = rtrim( wp_normalize_path( $root ), '/' );
			if ( '' !== $root && ! in_array( $root, $roots, true ) ) {
				$roots[] = $root;
			}
		}
		return $roots;
	}

	/** Folder name of the theme a path lies in, or ''. */
	private static function theme_slug_of( string $absolute ): string {
		$absolute = wp_normalize_path( $absolute );
		$real     = realpath( $absolute );
		$real     = false === $real ? '' : wp_normalize_path( $real );
		foreach ( self::theme_roots() as $root ) {
			$real_root = realpath( $root );
			$real_root = false === $real_root ? '' : rtrim( wp_normalize_path( $real_root ), '/' );
			foreach ( [ [ $absolute, $root ], [ $absolute, $real_root ], [ $real, $real_root ] ] as [ $path, $base ] ) {
				if ( '' !== $path && '' !== $base && str_starts_with( $path, $base . '/' ) ) {
					return basename( $root );
				}
			}
		}
		return '';
	}
}
