<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Provider;

use Stonewright\WpMcp\Elementor\Provider\NativeRuntime;

/** In-memory {@see NativeRuntime}: tests script what the "native ability" does to the stored state. */
final class FakeNativeRuntime implements NativeRuntime {

	/** @var list<array{0:string,1:array<string,mixed>}> */
	public array $calls = [];

	/** @var callable(string, array<string,mixed>, FakeNativeRuntime): mixed */
	public $executor;

	public int $kit_id = 9300;

	/** @var array<string, mixed> */
	public array $styles = [];

	/** @var array<int, string> */
	public array $statuses = [];

	/** @var array<int, array<int, mixed>|null> */
	public array $autosaves = [];

	/** @var list<string> */
	public array $atomic_types = [ 'e-div-block', 'e-flexbox', 'e-heading', 'e-paragraph', 'e-button' ];

	/** @var array<string,mixed>|\WP_Error */
	public array|\WP_Error $css_result = [ 'ok' => true, 'generation_status' => 'verified', 'effect_verified' => true ];

	public bool $restore_styles_succeeds = true;

	/** Whether the per-post write lock was still held when CSS regeneration ran. */
	public ?bool $lock_held_during_css = null;

	public function __construct() {
		$this->executor = static fn(): array => [];
	}

	public function execute( string $ability, array $input ): mixed {
		$this->calls[] = [ $ability, $input ];
		return ( $this->executor )( $ability, $input, $this );
	}

	/** @return list<string> */
	public function called_abilities(): array {
		return array_column( $this->calls, 0 );
	}

	public function post_status( int $post_id ): string {
		return isset( $GLOBALS['stonewright_test_posts'][ $post_id ] ) ? ( $this->statuses[ $post_id ] ?? 'draft' ) : '';
	}

	public function autosave_tree( int $post_id ): ?array {
		return $this->autosaves[ $post_id ] ?? null;
	}

	public function restore_autosave( int $post_id, ?array $tree ): bool {
		$this->autosaves[ $post_id ] = $tree;
		return true;
	}

	public function active_kit_id(): int {
		return $this->kit_id;
	}

	public function default_styles( int $kit_id ): array|\WP_Error {
		return $this->styles;
	}

	public function restore_default_styles( int $kit_id, array $before ): bool {
		if ( $this->restore_styles_succeeds ) {
			$this->styles = $before;
		}
		return $this->restore_styles_succeeds;
	}

	public function atomic_types(): array {
		return $this->atomic_types;
	}

	public function regenerate_css( int $post_id ): array|\WP_Error {
		$this->lock_held_during_css = false !== get_option( 'stonewright_elementor_lock_' . $post_id, false );
		$this->calls[] = [ 'stonewright/elementor-css-regenerate', [ 'post_id' => $post_id ] ];
		return $this->css_result;
	}
}
