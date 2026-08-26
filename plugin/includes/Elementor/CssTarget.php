<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

/**
 * Immutable Elementor CSS file target. Raw filesystem path and URL never
 * appear in JSON or audit output.
 */
final class CssTarget implements \JsonSerializable {
	public const KIND_POST = 'post';
	public const KIND_LOOP = 'loop';

	/**
	 * @throws \InvalidArgumentException When the target identity is inconsistent.
	 */
	public function __construct(
		private int $post_id,
		private string $kind,
		private string $runtime_class,
		private string $filename,
		private string $path,
		private string $url
	) {
		if ( $this->post_id < 1 ) {
			throw new \InvalidArgumentException( 'CssTarget requires a positive post id.' );
		}
		if ( ! in_array( $this->kind, [ self::KIND_POST, self::KIND_LOOP ], true ) ) {
			throw new \InvalidArgumentException( 'CssTarget kind must be post or loop.' );
		}
		$expected = $this->kind . '-' . $this->post_id . '.css';
		if ( $expected !== $this->filename || 1 !== preg_match( '/\A(?:post|loop)-[0-9]+\.css\z/D', $this->filename ) ) {
			throw new \InvalidArgumentException( 'CssTarget filename must match the resolved kind and post id.' );
		}
		if ( '' === $this->runtime_class || 1 !== preg_match( '/\A[A-Za-z\\\\][A-Za-z0-9\\\\]*\z/D', $this->runtime_class ) ) {
			throw new \InvalidArgumentException( 'CssTarget runtime class is invalid.' );
		}
		$normalized_path = wp_normalize_path( $this->path );
		if ( '' === $normalized_path || ! str_ends_with( $normalized_path, '/' . $this->filename ) || str_contains( $normalized_path, '..' ) ) {
			throw new \InvalidArgumentException( 'CssTarget path must end with the resolved filename.' );
		}
		$parts = wp_parse_url( $this->url );
		$url_path = is_array( $parts ) ? wp_normalize_path( (string) ( $parts['path'] ?? '' ) ) : '';
		if ( '' === $this->url || '' === $url_path || ! str_ends_with( $url_path, '/' . $this->filename ) ) {
			throw new \InvalidArgumentException( 'CssTarget URL must end with the resolved filename.' );
		}
	}

	public function post_id(): int {
		return $this->post_id;
	}

	public function kind(): string {
		return $this->kind;
	}

	public function runtime_class(): string {
		return $this->runtime_class;
	}

	public function filename(): string {
		return $this->filename;
	}

	public function path(): string {
		return $this->path;
	}

	public function url(): string {
		return $this->url;
	}

	/**
	 * @return array{post_id:int,kind:string,runtime_class:string,filename:string,path_sha256:string,url_sha256:string}
	 */
	public function jsonSerialize(): array {
		return [
			'post_id'         => $this->post_id,
			'kind'            => $this->kind,
			'runtime_class'   => $this->runtime_class,
			'filename'        => $this->filename,
			'path_sha256'     => hash( 'sha256', $this->path ),
			'url_sha256'      => hash( 'sha256', $this->url ),
		];
	}
}
