<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

use Stonewright\WpMcp\Admin\ConnectClientConfig;
use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Skills\Skills;
use Stonewright\WpMcp\Support\Utf8;

/**
 * Stonewright-specific REST routes outside of the MCP transport.
 *
 * Currently exposes a read-only audit log endpoint and a settings endpoint.
 */
final class RestRoutes {

	public static function register(): void {
		register_rest_route(
			'stonewright/v1',
			'/audit-log',
			[
				'methods'             => 'GET',
				'permission_callback' => [ Permissions::class, 'manage_options' ],
				'args'                => [
					'per_page' => [
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 200,
					],
					'page'     => [
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					],
				],
				'callback'            => static function ( \WP_REST_Request $request ) {
					$per_page = absint( $request['per_page'] );
					$page     = absint( $request['page'] );
					$rows     = AuditLog::recent( $per_page, $page );

					return rest_ensure_response( [
						'page'     => $page,
						'per_page' => $per_page,
						'items'    => $rows,
					] );
				},
			]
		);

		// -----------------------------------------------------------------
		// Abilities
		// -----------------------------------------------------------------

		register_rest_route(
			'stonewright/v1',
			'/abilities',
			[
				'methods'             => 'GET',
				'permission_callback' => [ Permissions::class, 'manage_options' ],
				'callback'            => static function () {
					return rest_ensure_response( AbilityRegistry::enabled_abilities() );
				},
			]
		);

		register_rest_route(
			'stonewright/v1',
			'/abilities/run',
			[
				'methods'             => 'POST',
				'permission_callback' => [ Permissions::class, 'manage_options' ],
				'args'                => [
					'name'  => [
						'type'     => 'string',
						'required' => true,
					],
					'input' => [
						'type'    => 'object',
						'default' => [],
					],
				],
				'callback'            => static function ( \WP_REST_Request $request ) {
					$name  = sanitize_text_field( (string) $request->get_param( 'name' ) );
					$input = $request->get_param( 'input' );
					$input = is_array( $input ) ? Utf8::deep_sanitize( $input ) : [];

					$ability = AbilityRegistry::ability_by_name( $name );
					if ( null === $ability ) {
						return new \WP_Error(
							'stonewright_ability_not_found',
							__( 'Ability not found.', 'stonewright' ),
							[ 'status' => 404 ]
						);
					}

					$master_enabled = (bool) get_option( 'stonewright_enabled', false );
					if ( ! $master_enabled && 'stonewright/ping' !== $name ) {
						return new \WP_Error(
							'stonewright_disabled',
							__( 'Master toggle is OFF.', 'stonewright' ),
							[ 'status' => 403 ]
						);
					}

					$disabled = (array) get_option( 'stonewright_disabled_abilities', [] );
					if ( in_array( $name, $disabled, true ) ) {
						return new \WP_Error(
							'stonewright_ability_disabled',
							__( 'Ability is disabled.', 'stonewright' ),
							[ 'status' => 403 ]
						);
					}

					$permission = $ability->permission_callback( $input );
					if ( $permission instanceof \WP_Error ) {
						return $permission;
					}
					if ( true !== $permission ) {
						return new \WP_Error(
							'stonewright_ability_forbidden',
							__( 'You do not have permission to run this ability.', 'stonewright' ),
							[ 'status' => 403 ]
						);
					}

					$result = AbilityRegistry::execute_with_context_guard( $ability, $input );
					if ( $result instanceof \WP_Error ) {
						return $result;
					}

					return rest_ensure_response( [
						'name'   => $name,
						'result' => $result,
					] );
				},
			]
		);

		register_rest_route(
			'stonewright/v1',
			'/abilities/toggle',
			[
				'methods'             => 'POST',
				'permission_callback' => [ Permissions::class, 'manage_options' ],
				'args'                => [
					'name'    => [
						'type'     => 'string',
						'required' => true,
					],
					'enabled' => [
						'type'     => 'boolean',
						'required' => true,
					],
				],
				'callback'            => static function ( \WP_REST_Request $request ) {
					$name    = sanitize_text_field( (string) $request->get_param( 'name' ) );
					$enabled = (bool) $request->get_param( 'enabled' );

					$disabled = (array) get_option( 'stonewright_disabled_abilities', [] );

					if ( $enabled ) {
						$disabled = array_values( array_diff( $disabled, [ $name ] ) );
					} elseif ( ! in_array( $name, $disabled, true ) ) {
						$disabled[] = $name;
					}

					update_option( 'stonewright_disabled_abilities', $disabled );

					return rest_ensure_response( [
						'name'    => $name,
						'enabled' => $enabled,
					] );
				},
			]
		);

		// -----------------------------------------------------------------
		// Memory
		// -----------------------------------------------------------------

		register_rest_route(
			'stonewright/v1',
			'/memory',
			[
				[
					'methods'             => 'GET',
					'permission_callback' => [ Permissions::class, 'manage_options' ],
					'args'                => [
						'type'   => [
							'type'    => 'string',
							'default' => '',
						],
						'limit'  => [
							'type'    => 'integer',
							'default' => 100,
							'minimum' => 1,
							'maximum' => 500,
						],
						'offset' => [
							'type'    => 'integer',
							'default' => 0,
							'minimum' => 0,
						],
					],
					'callback'            => static function ( \WP_REST_Request $request ) {
						$type   = (string) $request->get_param( 'type' );
						$limit  = absint( $request->get_param( 'limit' ) );
						$offset = absint( $request->get_param( 'offset' ) );

						if ( '' !== $type ) {
							$items = Memory::list_by_type( $type, $limit, $offset );
						} else {
							$items = Memory::list_all( $limit, $offset );
						}

						return rest_ensure_response( [ 'items' => $items ] );
					},
				],
				[
					'methods'             => 'POST',
					'permission_callback' => [ Permissions::class, 'manage_options' ],
					'args'                => [
						'type'       => [
							'type'    => 'string',
							'default' => 'generic',
						],
						'scope'      => [
							'type'     => 'string',
							'required' => true,
						],
						'key'        => [
							'type'     => 'string',
							'required' => true,
						],
						'name'       => [
							'type'    => 'string',
							'default' => '',
						],
						'value'      => [
							'required' => true,
						],
						'confidence' => [
							'type'    => 'number',
							'default' => 1.0,
							'minimum' => 0.0,
							'maximum' => 1.0,
						],
					],
					'callback'            => static function ( \WP_REST_Request $request ) {
						$id = Memory::put_typed(
							(string) $request->get_param( 'type' ),
							(string) $request->get_param( 'scope' ),
							(string) $request->get_param( 'key' ),
							(string) $request->get_param( 'name' ),
							$request->get_param( 'value' ),
							(float) $request->get_param( 'confidence' )
						);

						if ( 0 === $id ) {
							return new \WP_Error( 'stonewright_memory_write_failed', __( 'Failed to write memory entry.', 'stonewright' ), [ 'status' => 500 ] );
						}

						return rest_ensure_response( [ 'id' => $id ] );
					},
				],
			]
		);

		register_rest_route(
			'stonewright/v1',
			'/memory/(?P<id>\d+)',
			[
				'methods'             => 'DELETE',
				'permission_callback' => [ Permissions::class, 'manage_options' ],
				'args'                => [
					'id' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
				'callback'            => static function ( \WP_REST_Request $request ) {
					$id      = absint( $request->get_param( 'id' ) );
					$deleted = Memory::delete_by_id( $id );

					if ( ! $deleted ) {
						return new \WP_Error( 'stonewright_memory_not_found', __( 'Memory entry not found.', 'stonewright' ), [ 'status' => 404 ] );
					}

					return rest_ensure_response( [ 'deleted' => true, 'id' => $id ] );
				},
			]
		);

		// -----------------------------------------------------------------
		// Custom Instructions
		// -----------------------------------------------------------------

		register_rest_route(
			'stonewright/v1',
			'/instructions',
			[
				[
					'methods'             => 'GET',
					'permission_callback' => [ Permissions::class, 'manage_options' ],
					'callback'            => static function () {
						return rest_ensure_response( [
							'text'    => (string) get_option( 'stonewright_custom_instructions', '' ),
							'enabled' => (bool) get_option( 'stonewright_custom_instructions_enabled', true ),
						] );
					},
				],
				[
					'methods'             => 'POST',
					'permission_callback' => [ Permissions::class, 'manage_options' ],
					'args'                => [
						'text'    => [
							'type'              => 'string',
							'sanitize_callback' => static function ( $val ) {
								return mb_substr( sanitize_textarea_field( (string) $val ), 0, 4000 );
							},
						],
						'enabled' => [
							'type' => 'boolean',
						],
					],
					'callback'            => static function ( \WP_REST_Request $request ) {
						$text    = $request->get_param( 'text' );
						$enabled = $request->get_param( 'enabled' );

						if ( null !== $text ) {
							update_option( 'stonewright_custom_instructions', $text );
						}

						if ( null !== $enabled ) {
							update_option( 'stonewright_custom_instructions_enabled', (bool) $enabled );
						}

						return rest_ensure_response( [
							'text'    => (string) get_option( 'stonewright_custom_instructions', '' ),
							'enabled' => (bool) get_option( 'stonewright_custom_instructions_enabled', true ),
						] );
					},
				],
			]
		);

		// -----------------------------------------------------------------
		// Sandbox — shared master-toggle guard
		// -----------------------------------------------------------------

		$sandbox_toggle_check = static function (): ?\WP_Error {
			if ( ! (bool) get_option( 'stonewright_enabled', false ) ) {
				return new \WP_Error(
					'stonewright_disabled',
					__( 'Master toggle is OFF', 'stonewright' ),
					[ 'status' => 403 ]
				);
			}
			return null;
		};

		register_rest_route(
			'stonewright/v1',
			'/sandbox/files',
			[
				[
					'methods'             => 'GET',
					'permission_callback' => [ Permissions::class, 'manage_options' ],
					'callback'            => static function () use ( $sandbox_toggle_check ) {
						$err = $sandbox_toggle_check();
						if ( $err ) {
							return $err;
						}
						return rest_ensure_response( [ 'files' => SandboxFiles::list_files() ] );
					},
				],
				[
					'methods'             => 'POST',
					'permission_callback' => [ Permissions::class, 'manage_options' ],
					'args'                => [
						'name'     => [
							'type'     => 'string',
							'required' => true,
						],
						'contents' => [
							'type'     => 'string',
							'required' => true,
						],
					],
					'callback'            => static function ( \WP_REST_Request $request ) use ( $sandbox_toggle_check ) {
						$err = $sandbox_toggle_check();
						if ( $err ) {
							return $err;
						}

						$result = SandboxFiles::write(
							(string) $request->get_param( 'name' ),
							(string) $request->get_param( 'contents' )
						);

						if ( is_wp_error( $result ) ) {
							$result->add_data( [ 'status' => 422 ] );
							return $result;
						}

						return rest_ensure_response( [ 'written' => true ] );
					},
				],
			]
		);

		register_rest_route(
			'stonewright/v1',
			'/sandbox/files/(?P<name>[a-z0-9_-]+\.php)',
			[
				[
					'methods'             => 'GET',
					'permission_callback' => [ Permissions::class, 'manage_options' ],
					'callback'            => static function ( \WP_REST_Request $request ) use ( $sandbox_toggle_check ) {
						$err = $sandbox_toggle_check();
						if ( $err ) {
							return $err;
						}

						$result = SandboxFiles::read( (string) $request->get_param( 'name' ) );

						if ( is_wp_error( $result ) ) {
							$result->add_data( [ 'status' => 404 ] );
							return $result;
						}

						return rest_ensure_response( [
							'name'     => (string) $request->get_param( 'name' ),
							'contents' => $result,
						] );
					},
				],
				[
					'methods'             => 'PUT',
					'permission_callback' => [ Permissions::class, 'manage_options' ],
					'args'                => [
						'contents'   => [
							'type' => 'string',
						],
						'old_string' => [
							'type' => 'string',
						],
						'new_string' => [
							'type' => 'string',
						],
					],
					'callback'            => static function ( \WP_REST_Request $request ) use ( $sandbox_toggle_check ) {
						$err = $sandbox_toggle_check();
						if ( $err ) {
							return $err;
						}

						$name     = (string) $request->get_param( 'name' );
						$contents = $request->get_param( 'contents' );

						if ( null !== $contents ) {
							$result = SandboxFiles::write( $name, (string) $contents );
						} else {
							$old = $request->get_param( 'old_string' );
							$new = $request->get_param( 'new_string' );

							if ( null === $old || null === $new ) {
								return new \WP_Error(
									'stonewright_sandbox_missing_args',
									__( 'Provide either "contents" or both "old_string" and "new_string".', 'stonewright' ),
									[ 'status' => 422 ]
								);
							}

							$result = SandboxFiles::edit( $name, (string) $old, (string) $new );
						}

						if ( is_wp_error( $result ) ) {
							$result->add_data( [ 'status' => 422 ] );
							return $result;
						}

						return rest_ensure_response( [ 'updated' => true ] );
					},
				],
				[
					'methods'             => 'DELETE',
					'permission_callback' => [ Permissions::class, 'manage_options' ],
					'args'                => [
						'confirmation_token' => [
							'type' => 'string',
						],
					],
					'callback'            => static function ( \WP_REST_Request $request ) use ( $sandbox_toggle_check ) {
						$err = $sandbox_toggle_check();
						if ( $err ) {
							return $err;
						}

						$name = (string) $request->get_param( 'name' );
						if ( ! Permissions::not_production_safe() ) {
							$token = (string) $request->get_param( 'confirmation_token' );
							if ( '' === $token ) {
								return new \WP_Error(
									'stonewright_confirmation_required',
									__( 'A confirmation_token is required in production-safe mode.', 'stonewright' ),
									[ 'status' => 403 ]
								);
							}
							$verify_result = ConfirmationToken::verify_or_error( $token, 'stonewright/sandbox-delete', [ 'name' => $name ] );
							if ( is_wp_error( $verify_result ) ) {
								return $verify_result;
							}
						}

						$result = SandboxFiles::delete( $name );

						if ( is_wp_error( $result ) ) {
							$result->add_data( [ 'status' => 422 ] );
							return $result;
						}

						return rest_ensure_response( [ 'deleted' => true ] );
					},
				],
			]
		);

		register_rest_route(
			'stonewright/v1',
			'/sandbox/files/(?P<name>[a-z0-9_-]+\.php)/activate',
			[
				'methods'             => 'POST',
				'permission_callback' => [ Permissions::class, 'manage_options' ],
				'args'                => [
					'confirmation_token' => [
						'type' => 'string',
					],
				],
				'callback'            => static function ( \WP_REST_Request $request ) use ( $sandbox_toggle_check ) {
					$err = $sandbox_toggle_check();
					if ( $err ) {
						return $err;
					}

					$name = (string) $request->get_param( 'name' );
					if ( ! Permissions::not_production_safe() ) {
						$token = (string) $request->get_param( 'confirmation_token' );
						if ( '' === $token ) {
							return new \WP_Error(
								'stonewright_confirmation_required',
								__( 'A confirmation_token is required in production-safe mode.', 'stonewright' ),
								[ 'status' => 403 ]
							);
						}
						$verify_result = ConfirmationToken::verify_or_error( $token, 'stonewright/sandbox-activate', [ 'name' => $name ] );
						if ( is_wp_error( $verify_result ) ) {
							return $verify_result;
						}
					}

					$result = SandboxFiles::activate( $name );

					if ( is_wp_error( $result ) ) {
						$result->add_data( [ 'status' => 422 ] );
						return $result;
					}

					return rest_ensure_response( [ 'activated' => true ] );
				},
			]
		);

		register_rest_route(
			'stonewright/v1',
			'/sandbox/files/(?P<name>[a-z0-9_-]+\.php)/disable',
			[
				'methods'             => 'POST',
				'permission_callback' => [ Permissions::class, 'manage_options' ],
				'args'                => [
					'enable'             => [
						'type'    => 'boolean',
						'default' => false,
					],
					'confirmation_token' => [
						'type' => 'string',
					],
				],
				'callback'            => static function ( \WP_REST_Request $request ) use ( $sandbox_toggle_check ) {
					$err = $sandbox_toggle_check();
					if ( $err ) {
						return $err;
					}

					$name   = (string) $request->get_param( 'name' );
					$enable = (bool) $request->get_param( 'enable' );

					// Re-enable is destructive (puts code back into the auto-loaded mu-plugins surface).
					if ( $enable && ! Permissions::not_production_safe() ) {
						$token = (string) $request->get_param( 'confirmation_token' );
						if ( '' === $token ) {
							return new \WP_Error(
								'stonewright_confirmation_required',
								__( 'A confirmation_token is required to enable a sandbox file in production-safe mode.', 'stonewright' ),
								[ 'status' => 403 ]
							);
						}
						$verify_result = ConfirmationToken::verify_or_error( $token, 'stonewright/sandbox-toggle', [ 'name' => $name, 'action' => 'enable' ] );
						if ( is_wp_error( $verify_result ) ) {
							return $verify_result;
						}
					}

					$result = $enable ? SandboxFiles::enable( $name ) : SandboxFiles::disable( $name );

					if ( is_wp_error( $result ) ) {
						$result->add_data( [ 'status' => 422 ] );
						return $result;
					}

					return rest_ensure_response( [ 'enabled' => $enable ] );
				},
			]
		);

		// -----------------------------------------------------------------
		// Connect Config
		// -----------------------------------------------------------------

		register_rest_route(
			'stonewright/v1',
			'/connect-config',
			[
				'methods'             => 'GET',
				'permission_callback' => [ Permissions::class, 'manage_options' ],
				'args'                => [
					'client'   => [
						'type'     => 'string',
						'required' => true,
					],
					'username' => [
						'type'    => 'string',
						'default' => '',
					],
					'password' => [
						'type'    => 'string',
						'default' => '',
					],
				],
				'callback'            => static function ( \WP_REST_Request $request ) {
					$snippet = ConnectClientConfig::snippet_for(
						(string) $request->get_param( 'client' ),
						(string) $request->get_param( 'username' ),
						(string) $request->get_param( 'password' )
					);

					if ( is_wp_error( $snippet ) ) {
						$snippet->add_data( [ 'status' => 400 ] );
						return $snippet;
					}

					return rest_ensure_response( $snippet );
				},
			]
		);

		// -----------------------------------------------------------------
		// Application Password (generate once)
		// -----------------------------------------------------------------

		register_rest_route(
			'stonewright/v1',
			'/app-password',
			[
				'methods'             => 'POST',
				'permission_callback' => [ Permissions::class, 'manage_options' ],
				'args'                => [
					'name' => [
						'type'    => 'string',
						'default' => 'Stonewright',
					],
				],
				'callback'            => static function ( \WP_REST_Request $request ) {
					if ( ! class_exists( 'WP_Application_Passwords' ) ) {
						return new \WP_Error(
							'stonewright_app_passwords_unavailable',
							__( 'Application Passwords are not available on this WordPress installation.', 'stonewright' ),
							[ 'status' => 501 ]
						);
					}

					$user_id = get_current_user_id();
					$name    = sanitize_text_field( (string) $request->get_param( 'name' ) );
					if ( '' === $name ) {
						$name = 'Stonewright';
					}

					$result = \WP_Application_Passwords::create_new_application_password(
						$user_id,
						[ 'name' => $name ]
					);

					if ( is_wp_error( $result ) ) {
						$result->add_data( [ 'status' => 500 ] );
						return $result;
					}

					// $result[0] = plaintext password (shown once), $result[1] = item array with uuid.
					return rest_ensure_response( [
						'uuid'     => $result[1]['uuid'] ?? '',
						'password' => $result[0],
					] );
				},
			]
		);

		// -----------------------------------------------------------------
		// Settings (existing — preserved below)
		// -----------------------------------------------------------------

		register_rest_route(
			'stonewright/v1',
			'/settings',
			[
				[
					'methods'             => 'GET',
					'permission_callback' => [ Permissions::class, 'manage_options' ],
					'callback'            => static function () {
						return rest_ensure_response( [
							'mode'                 => get_option( 'stonewright_mode', 'development' ),
							'essential_tools_mode' => (bool) get_option( 'stonewright_essential_tools_mode', true ),
							'feature_flags'        => get_option( 'stonewright_feature_flags', [] ),
							'version'              => STONEWRIGHT_VERSION,
						] );
					},
				],
				[
					'methods'             => 'POST',
					'permission_callback' => [ Permissions::class, 'manage_options' ],
					'args'                => [
						'mode'          => [
							'type' => 'string',
							'enum' => [ 'development', 'staging', 'production-safe' ],
						],
						'feature_flags' => [
							'type' => 'object',
						],
						'essential_tools_mode' => [
							'type' => 'boolean',
						],
					],
					'callback'            => static function ( \WP_REST_Request $request ) {
						$mode = $request->get_param( 'mode' );
						if ( $mode ) {
							update_option( 'stonewright_mode', $mode );
						}

						$essential_tools_mode = $request->get_param( 'essential_tools_mode' );
						if ( null !== $essential_tools_mode ) {
							update_option( 'stonewright_essential_tools_mode', (bool) $essential_tools_mode );
						}

						$flags = $request->get_param( 'feature_flags' );
						if ( is_array( $flags ) ) {
							update_option( 'stonewright_feature_flags', $flags );
						}

						return rest_ensure_response( [
							'mode'                 => get_option( 'stonewright_mode', 'development' ),
							'essential_tools_mode' => (bool) get_option( 'stonewright_essential_tools_mode', true ),
							'feature_flags'        => get_option( 'stonewright_feature_flags', [] ),
						] );
					},
				],
			]
		);

		// -----------------------------------------------------------------
		// Skills
		// -----------------------------------------------------------------

		register_rest_route(
			'stonewright/v1',
			'/skills',
			[
				[
					'methods'             => 'GET',
					'permission_callback' => [ Permissions::class, 'manage_options' ],
					'args'                => [
						'enabled_only' => [
							'type'    => 'boolean',
							'default' => false,
						],
						'mode'         => [
							'type'    => 'string',
							'default' => 'all',
							'enum'    => [ 'all', 'agentic', 'prompt' ],
						],
					],
					'callback'            => static function ( \WP_REST_Request $request ) {
						$enabled_only = (bool) $request->get_param( 'enabled_only' );
						$mode         = (string) $request->get_param( 'mode' );

						if ( 'agentic' === $mode ) {
							$skills = Skills::list_agentic();
						} elseif ( 'prompt' === $mode ) {
							$skills = Skills::list_prompt();
						} else {
							$skills = Skills::list( $enabled_only );
							$mode   = 'all';
						}

						return rest_ensure_response( [
							'skills' => $skills,
							'count'  => count( $skills ),
							'mode'   => $mode,
						] );
					},
				],
				[
					'methods'             => 'POST',
					'permission_callback' => [ Permissions::class, 'manage_options' ],
					'args'                => [
						'slug'           => [ 'type' => 'string', 'required' => true ],
						'title'          => [ 'type' => 'string', 'required' => true ],
						'description'    => [ 'type' => 'string', 'default' => '' ],
						'content'        => [ 'type' => 'string', 'required' => true ],
						'enabled'        => [ 'type' => 'boolean', 'default' => true ],
						'enable_agentic' => [ 'type' => 'boolean' ],
						'enable_prompt'  => [ 'type' => 'boolean' ],
					],
					'callback'            => static function ( \WP_REST_Request $request ) {
						$enabled = (bool) $request->get_param( 'enabled' );
						$id      = Skills::save( [
							'slug'           => (string) $request->get_param( 'slug' ),
							'title'          => (string) $request->get_param( 'title' ),
							'description'    => (string) $request->get_param( 'description' ),
							'content'        => (string) $request->get_param( 'content' ),
							'enabled'        => $enabled,
							'enable_agentic' => null !== $request->get_param( 'enable_agentic' )
								? (bool) $request->get_param( 'enable_agentic' )
								: $enabled,
							'enable_prompt'  => null !== $request->get_param( 'enable_prompt' )
								? (bool) $request->get_param( 'enable_prompt' )
								: $enabled,
							'source'         => 'user',
						] );
						if ( 0 === $id ) {
							return new \WP_Error( 'stonewright_skills_save_failed', __( 'Failed to save skill.', 'stonewright' ), [ 'status' => 500 ] );
						}
						return rest_ensure_response( [ 'id' => $id ] );
					},
				],
			]
		);

		register_rest_route(
			'stonewright/v1',
			'/skills/(?P<id>\d+)/toggle',
			[
				'methods'             => 'POST',
				'permission_callback' => [ Permissions::class, 'manage_options' ],
				'args'                => [
					'id'      => [ 'type' => 'integer', 'required' => true ],
					'enabled' => [ 'type' => 'boolean', 'required' => true ],
				],
				'callback'            => static function ( \WP_REST_Request $request ) {
					$id      = absint( $request->get_param( 'id' ) );
					$enabled = (bool) $request->get_param( 'enabled' );
					Skills::toggle( $id, $enabled );
					return rest_ensure_response( [ 'id' => $id, 'enabled' => $enabled ] );
				},
			]
		);

		register_rest_route(
			'stonewright/v1',
			'/skills/(?P<id>\d+)',
			[
				'methods'             => 'DELETE',
				'permission_callback' => [ Permissions::class, 'manage_options' ],
				'args'                => [
					'id' => [ 'type' => 'integer', 'required' => true ],
				],
				'callback'            => static function ( \WP_REST_Request $request ) {
					$id      = absint( $request->get_param( 'id' ) );
					$skill   = Skills::get_by_id( $id );
					if ( null === $skill ) {
						return new \WP_Error( 'stonewright_skill_not_found', __( 'Skill not found.', 'stonewright' ), [ 'status' => 404 ] );
					}
					if ( 'builtin' === $skill['source'] ) {
						return new \WP_Error( 'stonewright_skill_builtin', __( 'Built-in skills cannot be deleted. Disable them instead.', 'stonewright' ), [ 'status' => 403 ] );
					}
					Skills::delete( $id );
					return rest_ensure_response( [ 'deleted' => true, 'id' => $id ] );
				},
			]
		);
	}
}
