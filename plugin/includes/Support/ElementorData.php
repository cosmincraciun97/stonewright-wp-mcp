<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

use Stonewright\WpMcp\Elementor\Integrity\DocumentIntegrityGate;
use Stonewright\WpMcp\Elementor\PostCacheInvalidator;
use Stonewright\WpMcp\Elementor\Schema\ContainerSchemaRepository;
use Stonewright\WpMcp\Elementor\Schema\PatchValidator;
use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Elementor\V4\AtomicTreeInspector;
use Stonewright\WpMcp\Elementor\Write\ElementorWriteReceipt;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;
use Stonewright\WpMcp\Elementor\Write\TreeHasher;

/**
 * Read/write helpers for Elementor V3 page data, which lives in the
 * `_elementor_data` post meta as JSON-encoded list of elements:
 *
 *   [ { id, elType, settings, elements, widgetType? }, … ]
 */
final class ElementorData {
	private const DOCUMENT_META_KEYS = [ '_elementor_data', '_elementor_edit_mode', '_elementor_version' ];

	private static ?\WP_Error $last_write_error = null;
	/** @var array<string, mixed> */
	private static array $last_write_receipt = [];
	/** @var array<string, mixed> */
	private static array $last_elementor_write_receipt = [];

	public static function last_write_error(): ?\WP_Error {
		return self::$last_write_error;
	}

	/**
	 * Cache-closure receipt for the last verified write in this request.
	 *
	 * @return array<string, mixed>
	 */
	public static function last_write_receipt(): array {
		return self::$last_write_receipt;
	}

	/**
	 * Machine-readable receipt for the last Elementor document write in this
	 * request. The cache invalidation receipt remains available separately via
	 * last_write_receipt() for backwards compatibility.
	 *
	 * @return array<string, mixed>
	 */
	public static function last_elementor_write_receipt(): array {
		return self::$last_elementor_write_receipt;
	}

	/** Clear request-local write state before an ability begins. */
	public static function clear_write_context(): void {
		self::$last_write_error             = null;
		self::$last_write_receipt           = [];
		self::$last_elementor_write_receipt = [];
	}

	/**
	 * WP_Error an ability should return after ElementorData::write() failed.
	 * Prefers the specific gate/validator error; falls back to a generic code.
	 */
	public static function write_error_for_ability( string $fallback_code = 'stonewright_write_failed' ): \WP_Error {
		if ( self::$last_write_error instanceof \WP_Error ) {
			$data = self::$last_write_error->get_error_data();
			$data = is_array( $data ) ? $data : [];
			if ( [] !== self::$last_elementor_write_receipt && ! isset( $data['write_receipt'] ) ) {
				$data['write_receipt'] = self::$last_elementor_write_receipt;
				self::$last_write_error->add_data( $data );
			}
			return self::$last_write_error;
		}
		return new \WP_Error(
			$fallback_code,
			__( 'Could not save Elementor data.', 'stonewright' ),
			[ 'status' => 500, 'write_receipt' => self::$last_elementor_write_receipt ]
		);
	}

	/**
	 * Pull the parsed _elementor_data for a post. Empty array if missing.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function read( int $post_id ): array {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( '' === $raw || null === $raw ) {
			return [];
		}
		if ( is_array( $raw ) ) {
			return $raw;
		}
		foreach ( [ (string) $raw, (string) wp_unslash( $raw ) ] as $candidate ) {
			$decoded = json_decode( $candidate, true );
			// Guard: if first decode is still a JSON string, decode once more
			// for read convenience — but never write that double-encoded form.
			if ( is_string( $decoded ) ) {
				$inner = json_decode( $decoded, true );
				if ( is_array( $inner ) ) {
					return $inner;
				}
			}
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return [];
	}

	/**
	 * Persist tree back to post meta. Elementor expects slashed JSON.
	 *
	 * P0 integrity gate runs first (size collapse, double-encode, widgetType
	 * remap). On readback failure the previous document is restored.
	 *
	 * @param array<int, array<string, mixed>> $tree    Document tree.
	 * @param array<string, mixed>             $options force_destructive?, allow_widget_type_remap?, min_size_ratio?, skip_integrity?, touched_ids?, lock_owner?, defer_rollback?, allow_unknown_setting_removal?, id_only_repair?.
	 */
	public static function write( int $post_id, array $tree, array $options = [] ): bool {
		self::clear_write_context();

		$provided_owner = isset( $options['lock_owner'] ) ? sanitize_key( (string) $options['lock_owner'] ) : '';
		if ( '' !== $provided_owner ) {
			if ( ! PostWriteLock::owned_by( $post_id, $provided_owner ) ) {
				self::$last_write_error = new \WP_Error(
					'stonewright_elementor_lock_invalid',
					__( 'The supplied Elementor write-lock owner does not own this post lease.', 'stonewright' ),
					[ 'status' => 409, 'post_id' => $post_id ]
				);
				$receipt = self::new_write_receipt( $post_id, $tree, $options, self::read( $post_id ) );
				$receipt->set_lock( [ 'status' => 'invalid' ] );
				self::$last_elementor_write_receipt = $receipt->fail( self::$last_write_error, 'lock.validate' )->to_array();
				return false;
			}
			return self::write_locked( $post_id, $tree, $options );
		}

		$owner = 'data-' . substr( hash( 'sha256', $post_id . '|' . hrtime( true ) ), 0, 24 );
		$lease = PostWriteLock::acquire( $post_id, $owner );
		if ( $lease instanceof \WP_Error ) {
			self::$last_write_error = $lease;
			$data = $lease->get_error_data();
			$data = is_array( $data ) ? $data : [];
			$receipt = self::new_write_receipt( $post_id, $tree, $options, self::read( $post_id ) );
			$receipt->set_lock(
				[
					'status'      => 'busy',
					'fingerprint' => (string) ( $data['lock_fingerprint'] ?? '' ),
					'age_seconds' => (int) ( $data['lock_age_seconds'] ?? 0 ),
					'retry_after' => (int) ( $data['retry_after_seconds'] ?? $data['retry_after'] ?? 0 ),
					'expires_at'  => (int) ( $data['lock_expires_at'] ?? 0 ),
				]
			);
			self::$last_elementor_write_receipt = $receipt->fail( $lease, 'lock.acquire' )->to_array();
			return false;
		}

		$locked_options                = $options;
		$locked_options['lock_owner']  = $owner;
		$locked_options['_lock_lease'] = $lease;
		try {
			return self::write_locked( $post_id, $tree, $locked_options );
		} finally {
			PostWriteLock::release( $post_id, $owner );
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<string, mixed>             $options
	 */
	private static function write_locked( int $post_id, array $tree, array $options ): bool {
		$previous            = self::read( $post_id );
		$previous_meta_state = self::capture_document_meta_state( $post_id );
		$before_hash          = TreeHasher::hash( $previous );
		$planned_hash         = TreeHasher::hash( $tree );
		$receipt              = self::new_write_receipt( $post_id, $tree, $options, $previous, $before_hash, $planned_hash );
		self::$last_elementor_write_receipt = $receipt->to_array();

		if ( empty( $options['skip_integrity'] ) ) {
			$gate = DocumentIntegrityGate::assert_write_allowed( $tree, $previous, $options );
			if ( $gate instanceof \WP_Error ) {
				self::$last_write_error = $gate;
				self::$last_elementor_write_receipt = $receipt->fail( $gate, 'integrity' )->to_array();
				return false;
			}
		}

		// Always validate the full structure and the global ID set. Settings are
		// validated separately as a before/after delta so untouched legacy values
		// cannot poison an otherwise safe surgical write.
		if ( ! SettingsValidator::validate_tree( $tree, [] ) ) {
			self::$last_write_error = SettingsValidator::last_error()
				?? new \WP_Error(
					'stonewright_elementor_tree_invalid',
					__( 'Elementor tree structure is invalid.', 'stonewright' ),
					[ 'status' => 400 ]
				);
			self::$last_elementor_write_receipt = $receipt->fail( self::$last_write_error, 'schema' )->to_array();
			return false;
		}
		$id_only_repair = ! empty( $options['id_only_repair'] );
		if ( $id_only_repair && ! self::trees_equal_except_element_ids( $previous, $tree ) ) {
			self::$last_write_error = self::settings_delta_error( 'root', 'id_only_repair_scope_violation', 'Element-id repair cannot change settings, content, hierarchy, element types, or ordering.' );
			self::$last_elementor_write_receipt = $receipt->fail( self::$last_write_error, 'schema.delta' )->to_array();
			return false;
		}
		$delta_error = $id_only_repair ? null : self::validate_settings_delta( $previous, $tree, ! empty( $options['allow_unknown_setting_removal'] ) );
		if ( $delta_error instanceof \WP_Error ) {
			self::$last_write_error = $delta_error;
			self::$last_elementor_write_receipt = $receipt->fail( $delta_error, 'schema.delta' )->to_array();
			return false;
		}

		$json = wp_json_encode( $tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			self::$last_write_error = new \WP_Error(
				'stonewright_elementor_json_encode_failed',
				__( 'Could not encode Elementor tree as JSON.', 'stonewright' ),
				[ 'status' => 500 ]
			);
			self::$last_elementor_write_receipt = $receipt->fail( self::$last_write_error, 'serialize' )->to_array();
			return false;
		}

		// Reject accidental double-encode of the encoded string itself.
		$payload_check = DocumentIntegrityGate::assert_meta_payload_not_double_encoded( $json );
		if ( $payload_check instanceof \WP_Error ) {
			self::$last_write_error = $payload_check;
			self::$last_elementor_write_receipt = $receipt->fail( $payload_check, 'integrity.payload' )->to_array();
			return false;
		}

		$ok = self::persist_encoded( $post_id, $json, $tree );
		if ( $ok ) {
			self::$last_write_receipt = PostCacheInvalidator::invalidate( $post_id );
			$after_hash = TreeHasher::hash( self::read( $post_id ) );
			$receipt->set_hashes( $before_hash, $planned_hash, $after_hash, $after_hash )->verified();
			self::$last_elementor_write_receipt = $receipt->to_array();
			return true;
		}

		// The batch orchestrator can own rollback so the primary write failure and
		// the restore result are reported in one receipt. Standalone callers keep
		// the historical safe default below.
		if ( ! empty( $options['defer_rollback'] ) ) {
			self::$last_write_error = new \WP_Error(
				'stonewright_elementor_readback_failed',
				__( 'Elementor write readback failed; rollback is pending at the transaction owner.', 'stonewright' ),
				[
					'status'          => 500,
					'post_id'         => $post_id,
					'rollback_status' => 'pending',
					'retryable'       => false,
					'primary_failure' => 'readback_mismatch',
				]
			);
			self::$last_elementor_write_receipt = $receipt->set( 'rollback_status', 'pending' )->fail( self::$last_write_error, 'write.readback' )->to_array();
			return false;
		}

		// Readback failed — restore the exact pre-write metadata state. This is
		// intentionally presence-aware: an absent document and a stored [] tree
		// both parse to [], but require different rollback operations.
		$restored   = self::restore_document_meta_state( $post_id, $previous_meta_state );
		$final_hash = TreeHasher::hash( self::read( $post_id ) );
		PostCacheInvalidator::invalidate( $post_id );
		$recovery = [
			'snapshot_id'       => (string) ( $options['snapshot_id'] ?? '' ),
			'primary_error_code' => 'readback_mismatch',
			'meta_state_hash'    => Json::hash( $previous_meta_state ),
		];
		if ( $restored ) {
			self::$last_write_error = new \WP_Error(
				'stonewright_elementor_readback_failed_restored',
				__( 'Elementor write readback failed; the exact pre-write Elementor state was restored.', 'stonewright' ),
				[
					'status'              => 500,
					'post_id'             => $post_id,
					'verification_status' => 'failed',
					'fix'                 => [ 'use_batch_mutate', 'do_not_retry_raw_meta_write' ],
				]
			);
			self::$last_elementor_write_receipt = $receipt
				->set_hashes( $before_hash, $planned_hash, $final_hash, $final_hash )
				->verified( 'failed' )
				->rollback( 'succeeded', $recovery )
				->fail( self::$last_write_error, 'write.readback' )
				->to_array();
		} else {
			self::$last_write_error = new \WP_Error(
				'stonewright_elementor_readback_failed_restore_failed',
				__( 'Elementor write readback failed and the pre-write state could not be restored. Restore from a Stonewright snapshot before further edits.', 'stonewright' ),
				[
					'status'              => 500,
					'post_id'             => $post_id,
					'verification_status' => 'failed',
					'fix'                 => [ 'restore_snapshot', 'use_batch_mutate', 'do_not_retry_raw_meta_write' ],
				]
			);
			self::$last_elementor_write_receipt = $receipt
				->set_hashes( $before_hash, $planned_hash, $final_hash, $final_hash )
				->verified( 'failed' )
				->rollback( 'failed', $recovery )
				->fail( self::$last_write_error, 'write.readback' )
				->to_array();
		}

		return false;
	}

	/**
	 * Validate the actual settings delta, not every historical value in the
	 * resulting document. Structural validation has already run globally.
	 *
	 * @param array<int, array<string, mixed>> $before_tree
	 * @param array<int, array<string, mixed>> $after_tree
	 */
	private static function validate_settings_delta( array $before_tree, array $after_tree, bool $allow_unknown_removal = false ): ?\WP_Error {
		$before = self::index_elements( $before_tree );
		$after  = self::index_elements( $after_tree );

		foreach ( $after as $id => $entry ) {
			$element        = $entry['element'];
			$before_element = isset( $before[ $id ] ) ? $before[ $id ]['element'] : null;
			$compatible     = is_array( $before_element )
				&& (string) ( $before_element['elType'] ?? '' ) === (string) ( $element['elType'] ?? '' )
				&& (string) ( $before_element['widgetType'] ?? '' ) === (string) ( $element['widgetType'] ?? '' );
			$before_settings = $compatible ? self::settings_array( $before_element['settings'] ?? [] ) : [];
			$after_settings  = self::settings_array( $element['settings'] ?? [] );
			if ( $compatible && $before_settings === $after_settings ) {
				continue;
			}

			$error = self::validate_element_settings_delta( $element, $before_settings, $after_settings, $entry['path'], $allow_unknown_removal );
			if ( $error instanceof \WP_Error ) {
				return $error;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $element
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 */
	private static function validate_element_settings_delta( array $element, array $before, array $after, string $path, bool $allow_unknown_removal ): ?\WP_Error {
		$element_type = (string) ( $element['elType'] ?? '' );
		$subject      = '';
		$controls     = [];
		$is_widget    = 'widget' === $element_type;
		if ( $is_widget ) {
			$subject = (string) ( $element['widgetType'] ?? '' );
			// Atomic and HTML widgets intentionally remain structure-only, matching
			// the established final-tree policy.
			if ( str_starts_with( $subject, 'e-' ) || 'html' === $subject ) {
				return self::settings_text_error( self::changed_settings_patch( $before, $after ), $path . '.settings' );
			}
			$schema = WidgetSchemaRepository::get( $subject );
		} elseif ( in_array( $element_type, [ 'container', 'section', 'column' ], true ) ) {
			$subject = $element_type;
			$schema  = ContainerSchemaRepository::get( $element_type );
		} else {
			return self::settings_text_error( self::changed_settings_patch( $before, $after ), $path . '.settings' );
		}
		if ( $schema instanceof \WP_Error ) {
			return $schema;
		}
		$controls = is_array( $schema['controls'] ?? null ) ? $schema['controls'] : [];

		$preservation_error = self::unknown_settings_preservation_error( $before, $after, $controls, $path . '.settings', $allow_unknown_removal );
		if ( $preservation_error instanceof \WP_Error ) {
			return $preservation_error;
		}

		$baseline = $before;
		foreach ( array_diff_key( $before, $after ) as $key => $value ) {
			$key = (string) $key;
			if ( ! $allow_unknown_removal && ! in_array( $key, [ '__dynamic__', '__globals__' ], true ) && null === self::schema_control_key( $key, $controls ) ) {
				return self::settings_delta_error( $path . '.settings.' . $key, 'unknown_setting_not_preserved', 'An existing setting absent from the live schema cannot disappear during a document write.' );
			}
			unset( $baseline[ $key ] );
		}

		$patch      = self::changed_settings_patch( $before, $after );
		$text_error = self::settings_text_error( $patch, $path . '.settings' );
		if ( $text_error instanceof \WP_Error ) {
			return $text_error;
		}
		$validated = $is_widget
			? PatchValidator::widget( $subject, $baseline, $patch, 'merge' )
			: PatchValidator::container( $baseline, $patch, $subject, 'merge' );
		if ( $validated instanceof \WP_Error ) {
			return $validated;
		}
		if ( $validated['settings'] !== $after ) {
			return self::settings_delta_error( $path . '.settings', 'delta_result_mismatch', 'The validated settings delta does not reproduce the proposed document exactly.' );
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @param array<string, array<string, mixed>> $controls
	 */
	private static function unknown_settings_preservation_error( array $before, array $after, array $controls, string $path, bool $allow_unknown_removal ): ?\WP_Error {
		foreach ( $before as $key => $value ) {
			$key = (string) $key;
			if ( in_array( $key, [ '__dynamic__', '__globals__' ], true ) ) {
				continue;
			}
			$control_key = self::schema_control_key( $key, $controls );
			if ( null === $control_key ) {
				if ( ! $allow_unknown_removal && ! array_key_exists( $key, $after ) ) {
					return self::settings_delta_error( $path . '.' . $key, 'unknown_setting_not_preserved', 'An existing third-party setting must be preserved unless a typed patch changes it explicitly.' );
				}
				continue;
			}

			$control = is_array( $controls[ $control_key ] ?? null ) ? $controls[ $control_key ] : [];
			if ( ! isset( $control['fields'] ) || ! is_array( $control['fields'] ) || ! is_array( $value ) || ! is_array( $after[ $key ] ?? null ) ) {
				continue;
			}
			$error = self::unknown_repeater_fields_preservation_error( $value, $after[ $key ], $control['fields'], $path . '.' . $key, $allow_unknown_removal );
			if ( $error instanceof \WP_Error ) {
				return $error;
			}
		}

		return null;
	}

	/**
	 * @param array<int, mixed> $before_rows
	 * @param array<int, mixed> $after_rows
	 * @param array<string, mixed> $fields
	 */
	private static function unknown_repeater_fields_preservation_error( array $before_rows, array $after_rows, array $fields, string $path, bool $allow_unknown_removal ): ?\WP_Error {
		if ( $allow_unknown_removal ) {
			return null;
		}
		foreach ( $before_rows as $index => $before_row ) {
			if ( ! is_array( $before_row ) ) {
				continue;
			}
			$after_row = self::matching_repeater_row( $after_rows, $before_row, (int) $index );
			// Removing an entire row is an explicit repeater operation; preservation
			// applies when the same row survives the write.
			if ( null === $after_row ) {
				continue;
			}
			foreach ( $before_row as $field => $value ) {
				$field = (string) $field;
				if ( '_id' === $field || isset( $fields[ $field ] ) ) {
					continue;
				}
				if ( ! array_key_exists( $field, $after_row ) || $after_row[ $field ] !== $value ) {
					return self::settings_delta_error( $path . '.' . (string) $index . '.' . $field, 'unknown_repeater_field_not_preserved', 'An existing third-party repeater field must remain byte-equivalent during a sibling patch.' );
				}
			}
		}

		return null;
	}

	/** @param array<int, mixed> $rows @param array<string, mixed> $candidate @return array<string, mixed>|null */
	private static function matching_repeater_row( array $rows, array $candidate, int $fallback_index ): ?array {
		foreach ( [ 'custom_id', '_id' ] as $identity_key ) {
			if ( ! is_scalar( $candidate[ $identity_key ] ?? null ) || '' === trim( (string) $candidate[ $identity_key ] ) ) {
				continue;
			}
			$value = trim( (string) $candidate[ $identity_key ] );
			foreach ( $rows as $row ) {
				if ( is_array( $row ) && is_scalar( $row[ $identity_key ] ?? null ) && hash_equals( $value, trim( (string) $row[ $identity_key ] ) ) ) {
					return $row;
				}
			}
			return null;
		}

		return is_array( $rows[ $fallback_index ] ?? null ) ? $rows[ $fallback_index ] : null;
	}

	/** @param array<string, array<string, mixed>> $controls */
	private static function schema_control_key( string $key, array $controls ): ?string {
		if ( isset( $controls[ $key ] ) ) {
			return $key;
		}
		foreach ( [ '_widescreen', '_laptop', '_tablet_extra', '_tablet', '_mobile_extra', '_mobile' ] as $suffix ) {
			if ( ! str_ends_with( $key, $suffix ) ) {
				continue;
			}
			$base = substr( $key, 0, -strlen( $suffix ) );
			if ( isset( $controls[ $base ] ) && ! empty( $controls[ $base ]['responsive'] ) ) {
				return $base;
			}
		}

		return null;
	}

	/** @param array<string, mixed> $before @param array<string, mixed> $after @return array<string, mixed> */
	private static function changed_settings_patch( array $before, array $after ): array {
		$patch = [];
		foreach ( $after as $key => $value ) {
			if ( ! array_key_exists( $key, $before ) || $before[ $key ] !== $value ) {
				$patch[ (string) $key ] = $value;
			}
		}
		return $patch;
	}

	/** @param array<string, mixed> $settings */
	private static function settings_text_error( array $settings, string $path ): ?\WP_Error {
		$violation = TextIntegrity::first_violation( $settings, $path );
		if ( null === $violation ) {
			return null;
		}
		return new \WP_Error(
			'stonewright_elementor_tree_invalid',
			__( 'Elementor tree structure is invalid.', 'stonewright' ),
			[
				'status'     => 400,
				'violations' => [ $violation ],
			]
		);
	}

	private static function settings_delta_error( string $path, string $code, string $message ): \WP_Error {
		return new \WP_Error(
			'stonewright_elementor_settings_invalid',
			__( 'The Elementor settings delta is invalid.', 'stonewright' ),
			[
				'status'           => 400,
				'reason'           => $message,
				'patch_validation' => true,
				'violations'       => [ [ 'path' => $path, 'code' => $code ] ],
			]
		);
	}

	/**
	 * Element-id recovery may alter only `id` values. Everything else must stay
	 * byte-equivalent at the decoded tree level, including unknown settings.
	 *
	 * @param array<int, array<string, mixed>> $before
	 * @param array<int, array<string, mixed>> $after
	 */
	private static function trees_equal_except_element_ids( array $before, array $after ): bool {
		$without_ids = static function ( array $nodes ) use ( &$without_ids ): array {
			foreach ( $nodes as $index => $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				unset( $node['id'] );
				if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
					$node['elements'] = $without_ids( $node['elements'] );
				}
				$nodes[ $index ] = $node;
			}
			return $nodes;
		};

		return $without_ids( $before ) === $without_ids( $after );
	}

	/** @param array<int, array<string, mixed>> $tree @return array<string, array{element:array<string,mixed>,path:string}> */
	private static function index_elements( array $tree, string $path = 'root' ): array {
		$indexed = [];
		foreach ( $tree as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$element_path = $path . '.' . (string) $index;
			$id           = isset( $element['id'] ) && is_scalar( $element['id'] ) ? trim( (string) $element['id'] ) : '';
			if ( '' !== $id ) {
				$indexed[ $id ] = [ 'element' => $element, 'path' => $element_path ];
			}
			$children = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : [];
			$indexed  = array_merge( $indexed, self::index_elements( $children, $element_path . '.elements' ) );
		}
		return $indexed;
	}

	/** @return array<string, mixed> */
	private static function settings_array( mixed $settings ): array {
		if ( is_array( $settings ) ) {
			return $settings;
		}
		return is_object( $settings ) ? (array) $settings : [];
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 */
	private static function persist_encoded( int $post_id, string $json, array $tree ): bool {
		$elementor_version = defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0';

		update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_version', $elementor_version );

		$stored_data = (string) get_post_meta( $post_id, '_elementor_data', true );
		$stored_mode = (string) get_post_meta( $post_id, '_elementor_edit_mode', true );
		$stored_ver  = (string) get_post_meta( $post_id, '_elementor_version', true );

		if ( 'builder' !== $stored_mode || $stored_ver !== $elementor_version ) {
			return false;
		}

		if ( $stored_data === $json ) {
			return true;
		}

		if ( wp_unslash( $stored_data ) === $json ) {
			return true;
		}

		foreach ( [ $stored_data, wp_unslash( $stored_data ) ] as $candidate ) {
			$decoded = json_decode( (string) $candidate, true );
			if ( is_array( $decoded ) ) {
				$canonical_stored = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( $canonical_stored === $json || $decoded === $tree ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<string, mixed>             $options
	 * @param array<int, array<string, mixed>> $previous
	 */
	private static function new_write_receipt(
		int $post_id,
		array $tree,
		array $options,
		array $previous,
		string $before_hash = '',
		string $planned_hash = ''
	): ElementorWriteReceipt {
		$before_hash  = '' !== $before_hash ? $before_hash : TreeHasher::hash( $previous );
		$planned_hash = '' !== $planned_hash ? $planned_hash : TreeHasher::hash( $tree );
		$architecture = (string) ( AtomicTreeInspector::inspect( $previous )['architecture'] ?? 'empty' );
		$target_ids   = isset( $options['touched_ids'] ) && is_array( $options['touched_ids'] )
			? array_values( array_map( 'strval', $options['touched_ids'] ) )
			: [];
		$change_set_id = isset( $options['change_set_id'] ) ? sanitize_text_field( (string) $options['change_set_id'] ) : '';
		if ( '' === $change_set_id ) {
			$change_set_id = 'cs-' . substr( hash( 'sha256', $post_id . '|' . $before_hash . '|' . $planned_hash ), 0, 24 );
		}
		$receipt = new ElementorWriteReceipt( $post_id, $architecture, $target_ids, false, $change_set_id );
		$receipt->set_hashes( $before_hash, $planned_hash );
		if ( isset( $options['snapshot_id'] ) && is_scalar( $options['snapshot_id'] ) ) {
			$receipt->set_snapshot( (string) $options['snapshot_id'] );
		}
		if ( isset( $options['lock_owner'] ) && is_scalar( $options['lock_owner'] ) && '' !== (string) $options['lock_owner'] ) {
			$lock = [
				'status' => 'acquired',
				'owner'  => (string) $options['lock_owner'],
			];
			$lease = $options['_lock_lease'] ?? null;
			if ( is_array( $lease ) ) {
				$lock['age_seconds'] = max( 0, time() - (int) ( $lease['acquired_at'] ?? time() ) );
				$lock['expires_at']  = (int) ( $lease['expires_at'] ?? 0 );
			}
			$receipt->set_lock( $lock );
		}

		return $receipt;
	}

	/** @return array<string, array{exists:bool,value:mixed}> */
	private static function capture_document_meta_state( int $post_id ): array {
		$state = [];
		foreach ( self::DOCUMENT_META_KEYS as $key ) {
			$exists        = self::meta_exists( $post_id, $key );
			$state[ $key ] = [
				'exists' => $exists,
				'value'  => $exists ? get_post_meta( $post_id, $key, true ) : null,
			];
		}
		return $state;
	}

	/** @param array<string, array{exists:bool,value:mixed}> $expected */
	private static function restore_document_meta_state( int $post_id, array $expected ): bool {
		$operations_verified = true;
		foreach ( self::DOCUMENT_META_KEYS as $key ) {
			$state = $expected[ $key ] ?? [ 'exists' => false, 'value' => null ];
			if ( $state['exists'] ) {
				update_post_meta( $post_id, $key, wp_slash( $state['value'] ) );
			} else {
				delete_post_meta( $post_id, $key );
			}
			$operations_verified = self::meta_matches_state( $post_id, $key, $state ) && $operations_verified;
		}

		$actual = self::capture_document_meta_state( $post_id );
		return $operations_verified && self::document_meta_states_match( $actual, $expected );
	}

	/**
	 * @param array<string, array{exists:bool,value:mixed}> $actual
	 * @param array<string, array{exists:bool,value:mixed}> $expected
	 */
	private static function document_meta_states_match( array $actual, array $expected ): bool {
		$normalized = [];
		foreach ( self::DOCUMENT_META_KEYS as $key ) {
			$actual_state   = $actual[ $key ] ?? [ 'exists' => false, 'value' => null ];
			$expected_state = $expected[ $key ] ?? [ 'exists' => false, 'value' => null ];
			if ( $actual_state['exists'] !== $expected_state['exists'] ) {
				return false;
			}
			if ( $actual_state['exists'] && ! self::values_match( $actual_state['value'], $expected_state['value'] ) ) {
				return false;
			}
			$normalized[ $key ] = $expected_state;
		}

		return hash_equals( Json::hash( $expected ), Json::hash( $normalized ) );
	}

	/** @param array{exists:bool,value:mixed} $expected */
	private static function meta_matches_state( int $post_id, string $key, array $expected ): bool {
		$exists = self::meta_exists( $post_id, $key );
		if ( $exists !== $expected['exists'] ) {
			return false;
		}
		return ! $exists || self::values_match( get_post_meta( $post_id, $key, true ), $expected['value'] );
	}

	private static function meta_exists( int $post_id, string $key ): bool {
		if ( function_exists( 'metadata_exists' ) ) {
			return metadata_exists( 'post', $post_id, $key );
		}
		$all_meta = get_post_meta( $post_id );
		return is_array( $all_meta ) && array_key_exists( $key, $all_meta );
	}

	private static function values_match( mixed $actual, mixed $expected ): bool {
		if ( $actual === $expected ) {
			return true;
		}
		return ( is_string( $actual ) || is_array( $actual ) ) && wp_unslash( $actual ) === $expected;
	}

	public static function is_active( int $post_id ): bool {
		return 'builder' === (string) get_post_meta( $post_id, '_elementor_edit_mode', true );
	}

	public static function generate_id(): string {
		return substr( md5( uniqid( '', true ) ), 0, 7 );
	}

	/**
	 * Flatten tree to a map of id → element snapshot (no children).
	 *
	 * @param array<int, array<string, mixed>> $tree
	 * @return array<string, array<string, mixed>>
	 */
	public static function flatten( array $tree ): array {
		$out = [];
		self::walk(
			$tree,
			static function ( array $element, array $path ) use ( &$out ) {
				$id = (string) ( $element['id'] ?? '' );
				if ( '' === $id ) {
					return;
				}
				$copy             = $element;
				$copy['elements'] = [];
				$copy['_path']    = $path;
				$out[ $id ]       = $copy;
			}
		);
		return $out;
	}

	/**
	 * Apply $mutator to every element. Mutator receives ($element, $path)
	 * and returns the new element (or null to drop).
	 *
	 * @param array<int, array<string, mixed>> $tree
	 */
	public static function walk( array $tree, callable $mutator, array $path = [] ): void {
		foreach ( $tree as $index => $element ) {
			$current_path = array_merge( $path, [ $index ] );
			$mutator( $element, $current_path );
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::walk( $element['elements'], $mutator, $current_path );
			}
		}
	}

	/**
	 * Find an element by id, returning a reference path so callers can mutate.
	 *
	 * @param array<int, array<string, mixed>> $tree
	 * @return array<int, int>|null
	 */
	public static function find_path( array $tree, string $id ): ?array {
		foreach ( $tree as $index => $element ) {
			if ( (string) ( $element['id'] ?? '' ) === $id ) {
				return [ (int) $index ];
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$nested = self::find_path( $element['elements'], $id );
				if ( null !== $nested ) {
					return array_merge( [ (int) $index ], $nested );
				}
			}
		}
		return null;
	}

	/**
	 * Replace the element at $path with $element (or remove if null).
	 *
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<int, int>                  $path
	 */
	public static function set( array $tree, array $path, ?array $element ): array {
		if ( empty( $path ) ) {
			return $tree;
		}
		$head = array_shift( $path );
		if ( ! isset( $tree[ $head ] ) ) {
			return $tree;
		}
		if ( empty( $path ) ) {
			if ( null === $element ) {
				array_splice( $tree, $head, 1 );
				return array_values( $tree );
			}
			$tree[ $head ] = $element;
			return $tree;
		}
		$children = isset( $tree[ $head ]['elements'] ) && is_array( $tree[ $head ]['elements'] )
			? $tree[ $head ]['elements']
			: [];
		$tree[ $head ]['elements'] = self::set( $children, $path, $element );
		return $tree;
	}

	/**
	 * Insert $element into the container at $parent_path at $position.
	 *
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<int, int>                  $parent_path Empty = root.
	 */
	public static function insert( array $tree, array $parent_path, int $position, array $element ): array {
		if ( empty( $parent_path ) ) {
			$position = max( 0, min( $position, count( $tree ) ) );
			array_splice( $tree, $position, 0, [ $element ] );
			return $tree;
		}

		$head = array_shift( $parent_path );
		if ( ! isset( $tree[ $head ] ) ) {
			return $tree;
		}
		$children                 = isset( $tree[ $head ]['elements'] ) && is_array( $tree[ $head ]['elements'] )
			? $tree[ $head ]['elements']
			: [];
		$tree[ $head ]['elements'] = self::insert( $children, $parent_path, $position, $element );
		return $tree;
	}
}
