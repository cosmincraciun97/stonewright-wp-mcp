<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

/**
 * Resolves an Elementor document to a post or loop CSS target using official
 * document/template evidence. Caller-claimed kinds are never trusted alone.
 */
final class CssTargetResolver {
	public const POST_CSS_CLASS = 'Elementor\\Core\\Files\\CSS\\Post';
	public const LOOP_CSS_CLASS = 'ElementorPro\\Modules\\LoopBuilder\\Files\\Css\\Loop';

	/** @var list<string> */
	private const LOOP_NAMES = [ 'loop-item', 'loop' ];

	/** @var (callable(int): (array<string,mixed>|\WP_Error))|null */
	private $evidence_reader;

	/** @var (callable(string): bool)|null */
	private $class_exists;

	/**
	 * @param (callable(int): (array<string,mixed>|\WP_Error))|null $evidence_reader
	 * @param (callable(string): bool)|null                         $class_exists
	 */
	public function __construct( $evidence_reader = null, $class_exists = null ) {
		$this->evidence_reader = is_callable( $evidence_reader ) ? $evidence_reader : null;
		$this->class_exists    = is_callable( $class_exists ) ? $class_exists : null;
	}

	public function resolve( int $post_id, string $requested_kind = 'auto' ): CssTarget|\WP_Error {
		if ( $post_id < 1 ) {
			return $this->error( 'stonewright_elementor_css_invalid_post', 'A valid Elementor post id is required.', 400 );
		}

		$requested = sanitize_key( $requested_kind );
		if ( ! in_array( $requested, [ 'auto', CssTarget::KIND_POST, CssTarget::KIND_LOOP ], true ) ) {
			return $this->error( 'stonewright_elementor_css_kind_invalid', 'CSS asset kind must be auto, post, or loop.', 400 );
		}

		$evidence = $this->read_evidence( $post_id );
		if ( $evidence instanceof \WP_Error ) {
			return $evidence;
		}

		$kind = $this->kind_from_evidence( $evidence );
		if ( $kind instanceof \WP_Error ) {
			return $kind;
		}
		if ( 'auto' !== $requested && $requested !== $kind ) {
			return $this->error( 'stonewright_elementor_css_kind_mismatch', 'The requested CSS kind does not match the Elementor document.', 409 );
		}

		$runtime_class = CssTarget::KIND_LOOP === $kind ? self::LOOP_CSS_CLASS : self::POST_CSS_CLASS;
		if ( ! $this->runtime_exists( $runtime_class ) ) {
			return $this->error( 'stonewright_elementor_css_runtime_unavailable', 'The official Elementor CSS runtime class is unavailable.', 409 );
		}

		$filename = $kind . '-' . $post_id . '.css';
		$location = CssAssetTransaction::expected_asset_location( $filename );
		if ( $location instanceof \WP_Error ) {
			return $location;
		}

		try {
			return new CssTarget( $post_id, $kind, $runtime_class, $filename, $location['path'], $location['url'] );
		} catch ( \InvalidArgumentException $error ) {
			return $this->error( 'stonewright_elementor_css_target_invalid', 'The resolved Elementor CSS target is invalid.', 409 );
		}
	}

	/**
	 * @return array{post_type:string,document_name:string,template_type:string}|\WP_Error
	 */
	private function read_evidence( int $post_id ): array|\WP_Error {
		if ( null !== $this->evidence_reader ) {
			$raw = ( $this->evidence_reader )( $post_id );
			if ( $raw instanceof \WP_Error ) {
				return $raw;
			}
			if ( ! is_array( $raw ) ) {
				return $this->error( 'stonewright_elementor_css_target_ambiguous', 'Elementor CSS target evidence is incomplete.', 409 );
			}
			return $this->normalize_evidence( $raw );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $this->error( 'stonewright_elementor_css_invalid_post', 'A valid Elementor post id is required.', 404 );
		}

		$document_name = '';
		$template_type = '';
		$plugin        = class_exists( '\\Elementor\\Plugin' ) ? \Elementor\Plugin::$instance : null;
		$documents     = is_object( $plugin ) ? ( $plugin->documents ?? null ) : null;
		if ( is_object( $documents ) && method_exists( $documents, 'get' ) ) {
			$document = $documents->get( $post_id );
			if ( is_object( $document ) ) {
				if ( method_exists( $document, 'get_name' ) ) {
					$document_name = (string) $document->get_name();
				}
				if ( method_exists( $document, 'get_template_type' ) ) {
					$template_type = (string) $document->get_template_type();
				}
			}
		}
		if ( '' === $template_type ) {
			$template_type = (string) get_post_meta( $post_id, '_elementor_template_type', true );
		}

		return $this->normalize_evidence(
			[
				'post_type'     => (string) $post->post_type,
				'document_name' => $document_name,
				'template_type' => $template_type,
			]
		);
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array{post_type:string,document_name:string,template_type:string}
	 */
	private function normalize_evidence( array $raw ): array {
		return [
			'post_type'     => sanitize_key( (string) ( $raw['post_type'] ?? '' ) ),
			'document_name' => sanitize_key( (string) ( $raw['document_name'] ?? '' ) ),
			'template_type' => sanitize_key( (string) ( $raw['template_type'] ?? '' ) ),
		];
	}

	/**
	 * @param array{post_type:string,document_name:string,template_type:string} $evidence
	 */
	private function kind_from_evidence( array $evidence ): string|\WP_Error {
		$document = $evidence['document_name'];
		$template = $evidence['template_type'];
		$loop_doc = in_array( $document, self::LOOP_NAMES, true );
		$loop_tpl = in_array( $template, self::LOOP_NAMES, true );

		if ( $loop_doc && '' !== $template && ! $loop_tpl ) {
			return $this->error( 'stonewright_elementor_css_target_ambiguous', 'Elementor document and template CSS evidence conflict.', 409 );
		}
		if ( $loop_tpl && '' !== $document && ! $loop_doc ) {
			return $this->error( 'stonewright_elementor_css_target_ambiguous', 'Elementor document and template CSS evidence conflict.', 409 );
		}
		if ( $loop_doc || $loop_tpl ) {
			return CssTarget::KIND_LOOP;
		}
		if ( 'elementor_library' === $evidence['post_type'] && '' === $document && '' === $template ) {
			return $this->error( 'stonewright_elementor_css_target_ambiguous', 'The Elementor library document has no resolvable CSS kind.', 409 );
		}

		return CssTarget::KIND_POST;
	}

	private function runtime_exists( string $class ): bool {
		$check = $this->class_exists;
		if ( null !== $check ) {
			return (bool) $check( $class );
		}
		return class_exists( $class );
	}

	private function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
