<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

use Stonewright\WpMcp\Abilities\Memory\LearningRecord;
use Stonewright\WpMcp\Abilities\System\TaskStart;
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
 * Mutations under the stonewright/v1 namespace are audited centrally. When a
 * route delegates to an AbilityKernel that already calls AuditLog::record(),
 * the REST layer skips the duplicate row via request-scoped deduplication.
 */
final class RestRoutes {

	public static function register(): void {
		// Central mutation audit for Stonewright-owned REST routes only.
		add_filter( 'rest_pre_dispatch', [ self::class, 'audit_pre_dispatch' ], 5, 3 );
		add_filter( 'rest_post_dispatch', [ self::class, 'audit_post_dispatch' ], 20, 3 );

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
					$filters  = [];
					if ( $request->get_param( 'status' ) ) {
						$filters['status'] = sanitize_key( (string) $request->get_param( 'status' ) );
					}
					if ( $request->get_param( 'ability' ) ) {
						$filters['ability'] = sanitize_text_field( (string) $request->get_param( 'ability' ) );
					}
					$rows  = AuditLog::recent( $per_page, $page, $filters );
					$total = AuditLog::count( $filters );

					return rest_ensure_response( [
						'page'        => $page,
						'per_page'    => $per_page,
						'total'       => $total,
						'total_pages' => (int) max( 1, (int) ceil( $total / max( 1, $per_page ) ) ),
						'items'       => $rows,
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
					return rest_ensure_response( AbilityRegistry::all_abilities() );
				},
			]
		);

		// Typed bridge for Direct-capable companions. This is deliberately not
		// the generic abilities/run route: only task-start and verified learning
		// are exposed, with the normal context and permission gates intact.
		register_rest_route(
			'stonewright/v1',
			'/direct/task-start',
			[
				'methods'             => 'POST',
				'permission_callback' => [ Permissions::class, 'manage_options' ],
				'callback'            => static function ( \WP_REST_Request $request ) {
					$args = [
						'task'         => sanitize_textarea_field( (string) $request->get_param( 'task' ) ),
						'surface'      => sanitize_key( (string) $request->get_param( 'surface' ) ),
						'intent'       => sanitize_key( (string) $request->get_param( 'intent' ) ),
						'responseMode' => 'compact',
					];
					$result = ( new TaskStart() )->execute( $args );
					return $result instanceof \WP_Error ? $result : rest_ensure_response( $result );
				},
			]
		);

		register_rest_route(
			'stonewright/v1',
			'/direct/learning-record',
			[
				'methods'             => 'POST',
				'permission_callback' => [ Permissions::class, 'manage_options' ],
				'callback'            => static function ( \WP_REST_Request $request ) {
					$params = $request->get_json_params();
					$params = is_array( $params ) ? Utf8::deep_sanitize( $params ) : [];
					$result = AbilityRegistry::execute_with_context_guard( new LearningRecord(), $params );
					return $result instanceof \WP_Error ? $result : rest_ensure_response( $result );
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

					$master_enabled = \Stonewright\WpMcp\Security\PluginEffectiveState::is_effectively_enabled();
					if ( ! $master_enabled && 'stonewright/ping' !== $name ) {
						return new \WP_Error(
							'stonewright_disabled',
							__( 'Master toggle is OFF or blocked (domain lock / dependency).', 'stonewright' ),
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
			if ( ! \Stonewright\WpMcp\Security\PluginEffectiveState::is_effectively_enabled() ) {
				return new \WP_Error(
					'stonewright_disabled',
					__( 'Master toggle is OFF or blocked (domain lock / dependency).', 'stonewright' ),
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
		// Application Password (create / list / revoke — password once only)
		// -----------------------------------------------------------------

		register_rest_route(
			'stonewright/v1',
			'/app-password',
			[
				[
					'methods'             => 'GET',
					'permission_callback' => [ Permissions::class, 'manage_options' ],
					'callback'            => static function () {
						$user_id = get_current_user_id();
						$rows    = [];
						if (
							$user_id > 0
							&& class_exists( 'WP_Application_Passwords' )
							&& method_exists( '\WP_Application_Passwords', 'get_user_application_passwords' )
						) {
							$passwords = \WP_Application_Passwords::get_user_application_passwords( $user_id );
							if ( is_array( $passwords ) ) {
								foreach ( $passwords as $item ) {
									if ( ! is_array( $item ) ) {
										continue;
									}
									$rows[] = [
										'uuid'    => (string) ( $item['uuid'] ?? '' ),
										'name'    => (string) ( $item['name'] ?? '' ),
										'created' => (int) ( $item['created'] ?? 0 ),
										'last_used' => (int) ( $item['last_used'] ?? 0 ),
									];
								}
							}
						}

						$response = rest_ensure_response(
							[
								'username'  => wp_get_current_user()->user_login ?? '',
								'passwords' => $rows,
							]
						);
						$response->header( 'Cache-Control', 'no-store, private' );
						return $response;
					},
				],
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
							return new \WP_Error(
								'stonewright_app_password_name_required',
								__( 'Enter a name before generating an Application Password.', 'stonewright' ),
								[ 'status' => 400 ]
							);
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
						// Never persist plaintext; return once with no-store.
						$response = rest_ensure_response(
							[
								'uuid'     => (string) ( $result[1]['uuid'] ?? '' ),
								'name'     => $name,
								'password' => (string) $result[0],
								'created'  => (int) ( $result[1]['created'] ?? time() ),
								'username' => (string) ( wp_get_current_user()->user_login ?? '' ),
							]
						);
						$response->header( 'Cache-Control', 'no-store, private' );
						return $response;
					},
				],
				[
					'methods'             => 'DELETE',
					'permission_callback' => [ Permissions::class, 'manage_options' ],
					'args'                => [
						'uuid' => [
							'type'     => 'string',
							'required' => true,
						],
					],
					'callback'            => static function ( \WP_REST_Request $request ) {
						if ( ! class_exists( 'WP_Application_Passwords' ) || ! method_exists( '\WP_Application_Passwords', 'delete_application_password' ) ) {
							return new \WP_Error(
								'stonewright_app_passwords_unavailable',
								__( 'Application Password revocation is not available on this site.', 'stonewright' ),
								[ 'status' => 501 ]
							);
						}

						$uuid = sanitize_text_field( (string) $request->get_param( 'uuid' ) );
						if ( '' === $uuid ) {
							return new \WP_Error(
								'stonewright_app_password_uuid_required',
								__( 'Choose an Application Password to revoke.', 'stonewright' ),
								[ 'status' => 400 ]
							);
						}

						$deleted = \WP_Application_Passwords::delete_application_password( get_current_user_id(), $uuid );
						if ( is_wp_error( $deleted ) ) {
							$deleted->add_data( [ 'status' => 500 ] );
							return $deleted;
						}

						$response = rest_ensure_response(
							[
								'deleted' => true,
								'uuid'    => $uuid,
							]
						);
						$response->header( 'Cache-Control', 'no-store, private' );
						return $response;
					},
				],
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

	/**
	 * Start a correlation id for Stonewright mutation requests.
	 *
	 * @param mixed            $result  Dispatch result so far.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function audit_pre_dispatch( $result, $server, $request ) {
		if ( ! self::is_stonewright_mutation( $request ) ) {
			return $result;
		}
		AuditLog::begin_request();
		return $result;
	}

	/**
	 * Persist one audit row for Stonewright mutations that were not already
	 * recorded by AbilityKernel.
	 *
	 * @param \WP_REST_Response|\WP_HTTP_Response|\WP_Error|mixed $response Response.
	 * @param \WP_REST_Server                                      $server   Server.
	 * @param \WP_REST_Request                                     $request  Request.
	 * @return mixed
	 */
	public static function audit_post_dispatch( $response, $server, $request ) {
		// OAuth endpoints get their own recorder: their failures are protocol
		// refusals rather than agent mistakes, and their payloads are credentials
		// that must never be summarized into the generic mutation row.
		if ( self::is_oauth_endpoint( $request ) ) {
			return self::audit_oauth_dispatch( $response, $request );
		}
		if ( ! self::is_stonewright_mutation( $request ) ) {
			return $response;
		}
		if ( AuditLog::was_audited() ) {
			return $response;
		}

		// Extract WP_Error fields before any later conversion discards them.
		$envelope = self::build_rest_error_envelope( $request, $response );

		$route  = $envelope['route'];
		$method = $envelope['method'];
		$params = $request->get_params();
		$params = is_array( $params ) ? $params : [];
		unset( $params['_wpnonce'], $params['_locale'] );
		$resource     = self::resource_from_params( $params );
		$audit_params = self::compact_audit_params( $params );

		$meta = [
			'error_code'      => $envelope['error_code'],
			'public_message'  => $envelope['public_message'],
			'error_message'   => $envelope['public_message'],
			'http_status'     => $envelope['http_status'],
			'operation_class' => 'rest_mutation',
			'resource_type'   => $envelope['resource_type'] ?? ( '' !== $resource ? 'rest_resource' : '' ),
			'resource_ref'    => $resource,
			'retryable'       => $envelope['retryable'],
			'correlation_id'  => AuditLog::request_id(),
		];
		if ( '' !== $envelope['target_ability'] ) {
			$meta['target_ability'] = $envelope['target_ability'];
			$meta['ability']        = $envelope['target_ability'];
		}
		if ( null !== $envelope['operation_index'] ) {
			$meta['failed_action_index'] = $envelope['operation_index'];
		}
		// Stable cause: target ability (or route label) + code + resource context.
		// Never empty `route|error|` — always include a concrete code and ability.
		$cause_ability = '' !== $envelope['target_ability']
			? $envelope['target_ability']
			: ( 'rest:' . strtoupper( $method ) . ' ' . $route );
		$meta['cause_key'] = strtolower( $cause_ability )
			. '|' . strtolower( $envelope['error_code'] !== '' ? $envelope['error_code'] : ( 'ok' === $envelope['audit_status'] ? 'ok' : 'stonewright_rest_error' ) )
			. '|' . strtolower( (string) $meta['resource_type'] );

		$payload = [
			'source'         => 'rest',
			'route'          => $route,
			'method'         => $method,
			'mode'           => (string) get_option( 'stonewright_mode', 'development' ),
			'resource'       => $resource,
			'params'         => $audit_params,
			'error_envelope' => $envelope['public'],
			'_meta'          => $meta,
		];
		if ( '' !== $envelope['target_ability'] ) {
			$payload['target_ability'] = $envelope['target_ability'];
		}

		AuditLog::record_rest_mutation(
			$route,
			$method,
			$payload,
			$envelope['audit_status'],
			$envelope['target_ability']
		);

		return $response;
	}

	/**
	 * Build the redacted REST audit error envelope from the live response.
	 *
	 * Captures WP_Error code/message/status and allowlisted data keys before
	 * transport conversion can drop them. Never records credentials, tokens,
	 * full args, code bodies, private content, or stack traces.
	 *
	 * @param \WP_REST_Request                                     $request  Request.
	 * @param \WP_REST_Response|\WP_HTTP_Response|\WP_Error|mixed $response Response.
	 * @return array{
	 *   route:string,
	 *   method:string,
	 *   target_ability:string,
	 *   audit_status:string,
	 *   http_status:int,
	 *   error_code:string,
	 *   public_message:string,
	 *   operation_index:int|null,
	 *   resource_type:string|null,
	 *   retryable:bool,
	 *   public:array<string,mixed>
	 * }
	 */
	public static function build_rest_error_envelope( \WP_REST_Request $request, $response ): array {
		$route  = (string) $request->get_route();
		$method = strtoupper( (string) $request->get_method() );
		$target = self::target_ability_from_request( $request );

		$http_status      = 200;
		$error_code       = '';
		$public_message   = '';
		$operation_index  = null;
		$resource_type    = null;
		$retryable        = false;
		$audit_status     = 'ok';
		$allowlisted_data = [];

		if ( $response instanceof \WP_Error ) {
			// Prefer the original WP_Error fields; never collapse to unknown_error.
			$error_code     = sanitize_key( (string) $response->get_error_code() );
			$public_message = self::redact_public_message( (string) $response->get_error_message() );
			$data           = $response->get_error_data();
			$data           = is_array( $data ) ? $data : [];
			$http_status    = (int) ( $data['status'] ?? 0 );
			if ( $http_status <= 0 ) {
				$http_status = self::default_http_status_for_code( $error_code );
			}
			$allowlisted_data = self::allowlisted_error_data( $data );
			if ( isset( $data['retryable'] ) ) {
				$retryable = (bool) $data['retryable'];
			}
			if ( isset( $data['failed_action_index'] ) && is_numeric( $data['failed_action_index'] ) ) {
				$operation_index = (int) $data['failed_action_index'];
			} elseif ( isset( $data['operation_index'] ) && is_numeric( $data['operation_index'] ) ) {
				$operation_index = (int) $data['operation_index'];
			}
			if ( isset( $data['resource_type'] ) && is_scalar( $data['resource_type'] ) ) {
				$resource_type = sanitize_key( (string) $data['resource_type'] );
			}
			$audit_status = self::audit_status_from_error( $error_code, $http_status, $data );
		} elseif ( is_object( $response ) && method_exists( $response, 'get_status' ) ) {
			$http_status = (int) $response->get_status();
			$body        = method_exists( $response, 'get_data' ) ? $response->get_data() : null;
			if ( is_array( $body ) && $http_status >= 400 ) {
				// REST error shape: { code, message, data: { status, ... } }.
				$raw_code = '';
				if ( isset( $body['code'] ) && is_scalar( $body['code'] ) ) {
					$raw_code = (string) $body['code'];
				} elseif ( isset( $body['error'] ) && is_scalar( $body['error'] ) ) {
					$raw_code = (string) $body['error'];
				}
				$error_code = sanitize_key( $raw_code );
				if ( isset( $body['message'] ) && is_scalar( $body['message'] ) ) {
					$public_message = self::redact_public_message( (string) $body['message'] );
				} elseif ( isset( $body['error_description'] ) && is_scalar( $body['error_description'] ) ) {
					$public_message = self::redact_public_message( (string) $body['error_description'] );
				}
				$nested = is_array( $body['data'] ?? null ) ? $body['data'] : $body;
				$allowlisted_data = self::allowlisted_error_data( $nested );
				if ( isset( $nested['retryable'] ) ) {
					$retryable = (bool) $nested['retryable'];
				}
				if ( isset( $nested['failed_action_index'] ) && is_numeric( $nested['failed_action_index'] ) ) {
					$operation_index = (int) $nested['failed_action_index'];
				}
				if ( isset( $nested['resource_type'] ) && is_scalar( $nested['resource_type'] ) ) {
					$resource_type = sanitize_key( (string) $nested['resource_type'] );
				}
			}
			if ( $http_status >= 400 ) {
				$audit_status = self::audit_status_from_error( $error_code, $http_status, $allowlisted_data );
			}
		}

		// Preserve a real code when present; only invent a bounded rest code when
		// the transport lost the original WP_Error entirely.
		if ( 'ok' !== $audit_status && '' === $error_code ) {
			$error_code = 403 === $http_status
				? 'stonewright_permission_denied'
				: 'stonewright_rest_error';
		}
		if ( 'ok' !== $audit_status && '' === $public_message ) {
			$public_message = self::default_public_message( $error_code, $http_status );
		}
		if ( in_array( $http_status, [ 429, 502, 503, 504 ], true ) ) {
			$retryable = true;
		}

		$public = [
			'route'            => $route,
			'method'           => $method,
			'target_ability'   => '' !== $target ? $target : null,
			'outcome'          => 'ok' === $audit_status ? 'success' : 'error',
			'http_status'      => $http_status,
			'error_code'       => '' !== $error_code ? $error_code : null,
			'public_message'   => '' !== $public_message ? $public_message : null,
			'operation_index'  => $operation_index,
			'resource_type'    => $resource_type,
			'retryable'        => $retryable,
			'correlation_id'   => AuditLog::request_id(),
		];
		// Allowlisted scalar data only — never merge arbitrary error data.
		if ( [] !== $allowlisted_data ) {
			$public['details'] = $allowlisted_data;
		}

		return [
			'route'           => $route,
			'method'          => $method,
			'target_ability'  => $target,
			'audit_status'    => $audit_status,
			'http_status'     => $http_status,
			'error_code'      => $error_code,
			'public_message'  => $public_message,
			'operation_index' => $operation_index,
			'resource_type'   => $resource_type,
			'retryable'       => $retryable,
			'public'          => $public,
		];
	}

	/**
	 * For /abilities/run, attribute the target ability from validated request args.
	 */
	private static function target_ability_from_request( \WP_REST_Request $request ): string {
		$route = (string) $request->get_route();
		if ( ! str_ends_with( $route, '/abilities/run' ) ) {
			return '';
		}
		$name = $request->get_param( 'name' );
		if ( ! is_scalar( $name ) ) {
			return '';
		}
		$name = sanitize_text_field( (string) $name );
		if ( '' === $name || ! str_contains( $name, '/' ) ) {
			return '';
		}
		return mb_substr( $name, 0, 190 );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function audit_status_from_error( string $code, int $http, array $data ): string {
		$execution = strtolower( (string) ( $data['execution_status'] ?? '' ) );
		if ( 403 === $http || 'blocked' === $execution ) {
			return 'blocked';
		}
		foreach ( [ 'forbidden', 'blocked', 'permission', 'grant_required', 'approval_required', 'confirmation_required', 'disabled', 'rule_violation' ] as $marker ) {
			if ( str_contains( strtolower( $code ), $marker ) ) {
				return 'blocked';
			}
		}
		return 'error';
	}

	private static function default_http_status_for_code( string $code ): int {
		$code = strtolower( $code );
		if ( str_contains( $code, 'not_found' ) ) {
			return 404;
		}
		if ( str_contains( $code, 'forbidden' ) || str_contains( $code, 'permission' ) || str_contains( $code, 'disabled' ) ) {
			return 403;
		}
		if ( str_contains( $code, 'invalid' ) || str_contains( $code, 'validation' ) || str_contains( $code, 'required' ) ) {
			return 400;
		}
		return 500;
	}

	private static function default_public_message( string $code, int $http ): string {
		if ( 'stonewright_permission_denied' === $code || 403 === $http ) {
			return __( 'Permission denied for the requested ability.', 'stonewright' );
		}
		if ( 'stonewright_ability_not_found' === $code ) {
			return __( 'Ability not found.', 'stonewright' );
		}
		if ( 'stonewright_disabled' === $code ) {
			return __( 'Master toggle is OFF.', 'stonewright' );
		}
		return __( 'Request failed.', 'stonewright' );
	}

	/**
	 * Bound + redact free-form error messages for audit persistence.
	 */
	private static function redact_public_message( string $message ): string {
		$message = mb_substr( preg_replace( '/\s+/', ' ', sanitize_text_field( $message ) ) ?? '', 0, 500 );
		$message = (string) preg_replace( '#\b(Bearer|Basic)\s+[A-Za-z0-9._~+/-=]+#i', '$1 [redacted]', $message );
		$message = (string) preg_replace(
			'~\b(client_secret|access_token|refresh_token|id_token|assertion|authorization|token|password|app_password|application_password|code)\b(\s*[:=]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s&,;]+)~i',
			'$1$2[redacted]',
			$message
		);
		return $message;
	}

	/**
	 * Scalar allowlist from WP_Error data. Never includes args, bodies, traces.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, scalar>
	 */
	private static function allowlisted_error_data( array $data ): array {
		$keys = [
			'status',
			'retryable',
			'retry_after_seconds',
			'execution_status',
			'verification_status',
			'rollback_status',
			'resource_type',
			'resource_ref',
			'operation_class',
			'failed_action_index',
			'operation_index',
			'element_id',
			'setting_path',
			'expected_type',
			'actual_type',
			'schema_version',
			'remediation_code',
			'rule_id',
			'before_sha256',
			'after_sha256',
			'changed_bytes',
			'provider',
			'provider_id',
		];
		$out = [];
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $data ) || ! is_scalar( $data[ $key ] ) ) {
				continue;
			}
			$value = $data[ $key ];
			$out[ $key ] = is_string( $value )
				? mb_substr( sanitize_text_field( $value ), 0, 255 )
				: $value;
		}
		return $out;
	}

	private static function is_oauth_endpoint( \WP_REST_Request $request ): bool {
		return str_starts_with( (string) $request->get_route(), '/stonewright/v1/oauth/' );
	}

	/**
	 * Persist one auth row for an OAuth endpoint outcome.
	 *
	 * Successful GETs on the auth surface (the authorize redirect, discovery) are
	 * not recorded: they would bury the failures this row exists to surface. Every
	 * 4xx/5xx is recorded regardless of method.
	 *
	 * @param \WP_REST_Response|\WP_HTTP_Response|\WP_Error|mixed $response Response.
	 * @return mixed
	 */
	private static function audit_oauth_dispatch( $response, \WP_REST_Request $request ) {
		if ( AuditLog::was_audited() ) {
			return $response;
		}

		if ( $response instanceof \WP_Error ) {
			$data      = $response->get_error_data();
			$http      = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
			$http      = $http > 0 ? $http : 500;
			$carrier   = new \WP_REST_Response(
				[
					'error'             => (string) $response->get_error_code(),
					'error_description' => (string) $response->get_error_message(),
				],
				$http
			);
		} elseif ( is_object( $response ) && method_exists( $response, 'get_status' ) ) {
			// Duck-typed rather than instanceof: the OAuth bridge hands back
			// whatever response object the transport produced.
			$carrier = $response;
			$http    = (int) $response->get_status();
		} else {
			return $response;
		}

		$method = strtoupper( (string) $request->get_method() );
		if ( $http < 400 && 'POST' !== $method ) {
			return $response;
		}

		$route    = (string) $request->get_route();
		$endpoint = 'oauth/' . ltrim( substr( $route, strlen( '/stonewright/v1/oauth/' ) ), '/' );
		$body     = $request->get_body_params();
		$body     = is_array( $body ) ? $body : [];
		$sensitive_values = [];
		foreach ( [ 'client_secret', 'code', 'refresh_token', 'access_token', 'id_token', 'device_code', 'user_code', 'assertion' ] as $key ) {
			if ( isset( $body[ $key ] ) && is_scalar( $body[ $key ] ) ) {
				$sensitive_values[] = (string) $body[ $key ];
			}
		}
		$authorization = $request->get_header( 'authorization' );
		if ( is_string( $authorization ) && '' !== trim( $authorization ) ) {
			$sensitive_values[] = $authorization;
			if ( preg_match( '/^\S+\s+(.+)$/', trim( $authorization ), $authorization_parts ) ) {
				$sensitive_values[] = $authorization_parts[1];
			}
		}

		AuditLog::record_auth_event(
			$endpoint,
			$carrier,
			[
				'client_id'        => $body['client_id'] ?? $request->get_param( 'client_id' ) ?? '',
				'sensitive_values' => $sensitive_values,
			]
		);

		return $response;
	}

	private static function is_stonewright_mutation( \WP_REST_Request $request ): bool {
		$route  = (string) $request->get_route();
		$method = strtoupper( (string) $request->get_method() );
		if ( ! str_starts_with( $route, '/stonewright/v1' ) ) {
			return false;
		}
		if ( '/stonewright/v1/direct/task-start' === $route ) {
			return false;
		}
		return in_array( $method, [ 'POST', 'PUT', 'PATCH', 'DELETE' ], true );
	}

	/**
	 * Replace free-form mutation bodies with compact, irreversible summaries.
	 * This prevents credentials embedded in PHP, skills, instructions, or
	 * memory text from being copied into the audit table.
	 *
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	private static function compact_audit_params( array $params ): array {
		$body_keys = [
			'body',
			'code',
			'content',
			'contents',
			'correction',
			'evidence',
			'instructions',
			'new_string',
			'old_string',
			'php',
			'text',
			'value',
		];
		$summary   = [];
		foreach ( $params as $key => $value ) {
			$key = (string) $key;
			if ( in_array( strtolower( $key ), $body_keys, true ) ) {
				$summary[ $key ] = self::audit_body_summary( $value );
				continue;
			}
			if ( is_array( $value ) ) {
				$summary[ $key ] = self::compact_audit_params( $value );
				continue;
			}
			$summary[ $key ] = $value;
		}
		return $summary;
	}

	/**
	 * @return array{redacted: true, sha256: string, bytes: int}
	 */
	private static function audit_body_summary( mixed $value ): array {
		if ( is_string( $value ) ) {
			$serialized = $value;
		} else {
			$encoded    = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$serialized = false === $encoded ? get_debug_type( $value ) : $encoded;
		}
		return [
			'redacted' => true,
			'sha256'   => hash( 'sha256', $serialized ),
			'bytes'    => strlen( $serialized ),
		];
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private static function resource_from_params( array $params ): string {
		foreach ( [ 'id', 'post_id', 'name', 'slug', 'ability' ] as $key ) {
			if ( isset( $params[ $key ] ) && is_scalar( $params[ $key ] ) ) {
				return $key . '=' . (string) $params[ $key ];
			}
		}
		return '';
	}
}
