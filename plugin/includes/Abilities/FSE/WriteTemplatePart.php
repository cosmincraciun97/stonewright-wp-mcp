<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\FSE;

/**
 * Ability: stonewright/fse.write_template_part
 *
 * Same as WriteTemplate but targets wp_template_part and adds the `area` field.
 *
 * @stonewright-status stable
 */
final class WriteTemplatePart extends AbstractTemplateWriter {

	public function name(): string {
		return 'stonewright/fse-write-template-part';
	}

	public function label(): string {
		return __( 'Write FSE template part', 'stonewright' );
	}

	public function description(): string {
		return __( 'Inserts or updates a Full Site Editing wp_template_part post. Backup is taken before any overwrite. Requires a confirmation token in production-safe mode.', 'stonewright' );
	}

	protected function post_type(): string {
		return 'wp_template_part';
	}

	protected function ability_slug(): string {
		return 'stonewright/fse-write-template-part';
	}

	public function input_schema(): array {
		$schema = parent::input_schema();
		$schema['properties']['area'] = [
			'type'        => 'string',
			'enum'        => [ 'header', 'footer', 'sidebar', 'uncategorized' ],
			'description' => 'Template part area.',
		];
		return $schema;
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ): array|\WP_Error {
				$result = $this->write( $args );
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				// Store area as meta on the newly created/updated post.
				$area = (string) ( $args['area'] ?? 'uncategorized' );
				if ( in_array( $area, [ 'header', 'footer', 'sidebar', 'uncategorized' ], true ) ) {
					update_post_meta( (int) $result['post_id'], 'area', $area );
				}

				return $result;
			}
		);
	}
}
