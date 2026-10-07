<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Provider;

use Stonewright\WpMcp\Abilities\ElementorV3\CssRegenerate;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;
use Stonewright\WpMcp\Elementor\Write\ElementTreeDiff;

/** Live WordPress and Elementor implementation of {@see NativeRuntime}. */
final class WordPressNativeRuntime implements NativeRuntime {

	private const DEFAULT_STYLES_REPOSITORY = 'Elementor\\Modules\\DefaultStyles\\Default_Styles_Repository';

	public function execute( string $ability, array $input ): mixed {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return new \WP_Error( 'stonewright_native_abilities_api_missing', 'The WordPress Abilities API is not available.', [ 'status' => 409 ] );
		}
		if ( function_exists( 'wp_has_ability' ) && ! wp_has_ability( $ability ) ) {
			return new \WP_Error( 'stonewright_native_ability_missing', 'The Elementor ability is not registered on this site.', [ 'status' => 409, 'ability' => $ability ] );
		}
		$registered = wp_get_ability( $ability );
		if ( ! is_object( $registered ) || ! method_exists( $registered, 'execute' ) ) {
			return new \WP_Error( 'stonewright_native_ability_missing', 'The Elementor ability is not registered on this site.', [ 'status' => 409, 'ability' => $ability ] );
		}
		return $registered->execute( $input );
	}

	public function post_status( int $post_id ): string {
		$status = get_post_status( $post_id );
		return is_string( $status ) ? $status : '';
	}

	public function autosave_tree( int $post_id ): ?array {
		$autosave = $this->autosave( $post_id );
		if ( null === $autosave ) {
			return null;
		}
		return ElementTreeDiff::tree_from_meta( get_post_meta( (int) $autosave->ID, '_elementor_data', true ) );
	}

	public function restore_autosave( int $post_id, ?array $tree ): bool {
		$autosave = $this->autosave( $post_id );
		if ( null === $autosave ) {
			return null === $tree;
		}
		if ( null === $tree ) {
			return false !== wp_delete_post_revision( (int) $autosave->ID );
		}
		$json = wp_json_encode( $tree );
		return is_string( $json ) && false !== update_post_meta( (int) $autosave->ID, '_elementor_data', wp_slash( $json ) );
	}

	public function active_kit_id(): int {
		return V4FeatureGate::active_kit_id();
	}

	public function default_styles( int $kit_id ): array|\WP_Error {
		$repository = $this->repository();
		if ( $repository instanceof \WP_Error ) {
			return $repository;
		}
		try {
			$all = $repository->all( true );
			return is_array( $all ) ? $all : [];
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'stonewright_native_default_styles_unreadable', 'The default styles could not be read.', [ 'status' => 502, 'error_class' => get_class( $error ) ] );
		}
	}

	public function restore_default_styles( int $kit_id, array $before ): bool {
		$repository = $this->repository();
		if ( $repository instanceof \WP_Error ) {
			return false;
		}
		try {
			$current = $repository->all( true );
			foreach ( array_keys( is_array( $current ) ? $current : [] ) as $tag ) {
				if ( ! array_key_exists( $tag, $before ) ) {
					$repository->delete( (string) $tag );
				}
			}
			foreach ( $before as $tag => $style ) {
				if ( ! is_array( $style ) ) {
					continue;
				}
				if ( ! is_array( $current ) || ! array_key_exists( $tag, $current ) || $current[ $tag ] !== $style ) {
					$repository->put( (string) $tag, [ 'type' => (string) ( $style['type'] ?? 'class' ), 'variants' => (array) ( $style['variants'] ?? [] ) ] );
				}
			}
			return true;
		} catch ( \Throwable $error ) {
			unset( $error );
			return false;
		}
	}

	public function atomic_types(): array {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! is_object( \Elementor\Plugin::$instance ) ) {
			return [];
		}
		$types = [];
		foreach ( [ 'elements_manager' => 'get_element_types', 'widgets_manager' => 'get_widget_types' ] as $property => $method ) {
			$manager = \Elementor\Plugin::$instance->{$property} ?? null;
			if ( ! is_object( $manager ) || ! method_exists( $manager, $method ) ) {
				continue;
			}
			try {
				$registered = $manager->{$method}();
			} catch ( \Throwable $error ) {
				unset( $error );
				continue;
			}
			foreach ( is_array( $registered ) ? array_keys( $registered ) : [] as $type ) {
				if ( is_string( $type ) && str_starts_with( $type, 'e-' ) ) {
					$types[] = $type;
				}
			}
		}
		$types = array_values( array_unique( $types ) );
		sort( $types );
		return $types;
	}

	/**
	 * Runs the CSS regeneration ability in-process, after its own permission check. It is a Stonewright ability
	 * called from inside the closure, so the caller's task-start context token is not needed again.
	 */
	public function regenerate_css( int $post_id ): array|\WP_Error {
		$ability    = new CssRegenerate();
		$input      = [ 'post_id' => $post_id ];
		$permission = $ability->permission_callback( $input );
		if ( true !== $permission ) {
			return $permission instanceof \WP_Error ? $permission : new \WP_Error( 'stonewright_native_css_forbidden', 'The current user cannot regenerate CSS for this post.', [ 'status' => 403 ] );
		}
		return $ability->execute( $input );
	}

	private function autosave( int $post_id ): ?object {
		if ( ! function_exists( 'wp_get_post_autosave' ) ) {
			return null;
		}
		$autosave = wp_get_post_autosave( $post_id, get_current_user_id() );
		return is_object( $autosave ) ? $autosave : null;
	}

	/** @return object The repository, or a WP_Error describing why it is unavailable. */
	private function repository(): object {
		$class = self::DEFAULT_STYLES_REPOSITORY;
		if ( ! class_exists( $class ) || ! is_callable( [ $class, 'make' ] ) || ! class_exists( '\\Elementor\\Plugin' ) ) {
			return new \WP_Error( 'stonewright_native_default_styles_unavailable', 'The Elementor default styles repository is not available.', [ 'status' => 409 ] );
		}
		try {
			$kit        = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
			$repository = call_user_func( [ $class, 'make' ], $kit );
			return is_object( $repository ) ? $repository : new \WP_Error( 'stonewright_native_default_styles_unavailable', 'The default styles repository returned no object.', [ 'status' => 409 ] );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'stonewright_native_default_styles_unavailable', 'The default styles repository could not be opened.', [ 'status' => 409, 'error_class' => get_class( $error ) ] );
		}
	}
}
