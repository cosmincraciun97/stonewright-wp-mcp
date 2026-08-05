<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Diagnostics;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/** Read-only delivery diagnostic for Elementor forms and external actions. */
final class FormDeliveryDiagnostic extends AbilityKernel {

	public function name(): string {
		return 'stonewright/form-delivery-diagnostic';
	}

	public function label(): string {
		return __( 'Diagnose form delivery', 'stonewright' );
	}

	public function description(): string {
		return __( 'Inspects form actions, structural mail settings, SMTP/providers, and Newsman dependencies without sending a message or exposing recipients/body content.', 'stonewright' );
	}

	public function category(): string {
		return 'diagnostics';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'post_id' ],
			'properties'           => [ 'post_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'widget_id' => [ 'type' => 'string', 'maxLength' => 96 ] ],
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object', 'additionalProperties' => true, 'properties' => [ 'ok' => [ 'type' => 'boolean' ], 'forms' => [ 'type' => 'array' ], 'providers' => [ 'type' => 'object' ], 'owner_recommendations' => [ 'type' => 'array' ] ] ];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) );
	}

	public function execute( array $args ): array|\WP_Error {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( ! get_post( $post_id ) ) {
			return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ), [ 'status' => 404 ] );
		}
		$widget_id = sanitize_text_field( (string) ( $args['widget_id'] ?? '' ) );
		$forms = [];
		foreach ( ElementorData::flatten( ElementorData::read( $post_id ) ) as $node ) {
			if ( ! is_array( $node ) || 'form' !== (string) ( $node['widgetType'] ?? '' ) || ( '' !== $widget_id && $widget_id !== (string) ( $node['id'] ?? '' ) ) ) {
				continue;
			}
			$settings = is_array( $node['settings'] ?? null ) ? $node['settings'] : [];
			$actions = self::actions( $settings );
			$mail = in_array( 'email', $actions, true ) || in_array( 'mail', $actions, true );
			$from_status = $mail ? self::address_shape_status( $settings['email_from'] ?? $settings['from_email'] ?? '' ) : 'not_applicable';
			$reply_status = $mail ? self::address_shape_status( $settings['email_reply_to'] ?? $settings['reply_to'] ?? '' ) : 'not_applicable';
			$forms[] = [
				'widget_id'             => sanitize_text_field( (string) ( $node['id'] ?? '' ) ),
				'configured_actions'    => $actions,
				'mail_action_present'   => $mail,
				'recipient_domain_hash' => $mail ? self::recipient_domain_hash( $settings ) : '',
				'from_valid'            => 'valid' === $from_status ? true : ( 'invalid' === $from_status ? false : null ),
				'from_status'           => $from_status,
				'reply_to_valid'        => 'valid' === $reply_status ? true : ( 'invalid' === $reply_status ? false : null ),
				'reply_to_status'       => $reply_status,
				'newsman_action_present' => in_array( 'newsman', $actions, true ),
				'external_dependencies' => array_values( array_intersect( $actions, [ 'webhook', 'newsman', 'redirect' ] ) ),
			];
		}
		if ( '' !== $widget_id && [] === $forms ) {
			return $this->error( 'form_widget_not_found', __( 'The requested Elementor form widget was not found on this post.', 'stonewright' ), [ 'status' => 404, 'widget_id' => $widget_id ] );
		}
		$providers = self::providers();
		$owner = [];
		foreach ( $forms as $form ) {
			if ( empty( $form['mail_action_present'] ) ) {
				$owner[] = 'elementor_form_configuration';
			} elseif ( false === $form['from_valid'] || false === $form['reply_to_valid'] ) {
				$owner[] = 'elementor_mail_headers';
			} elseif ( empty( $providers['smtp_plugin_detected'] ) && empty( $providers['wp_mail_provider_hooked'] ) ) {
				$owner[] = 'smtp_or_host_mail_provider';
			}
			if ( ! empty( $form['newsman_action_present'] ) ) {
				$owner[] = 'newsman_or_third_party_action';
			}
		}
		$failure_count = get_option( 'stonewright_mail_failure_count', null );
		return [
			'ok'                   => true,
			'post_id'              => $post_id,
			'forms'                => $forms,
			'providers'            => $providers,
			'recent_failure_count' => is_numeric( $failure_count ) ? min( 1000, max( 0, (int) $failure_count ) ) : null,
			'recent_failure_source'=> is_numeric( $failure_count ) ? 'stonewright_recorded_metric' : 'not_available',
			'mailbox_status'       => 'not_checked',
			'dns_status'           => 'not_checked',
			'owner_recommendations' => array_values( array_unique( $owner ) ),
			'send_attempted'       => false,
		];
	}

	/** @param array<string,mixed> $settings @return list<string> */
	private static function actions( array $settings ): array {
		$raw = $settings['actions_after_submit'] ?? $settings['submit_actions'] ?? $settings['form_actions'] ?? [];
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[,|]+/', $raw ) ?: [];
		}
		$out = [];
		foreach ( is_array( $raw ) ? $raw : [] as $action ) {
			if ( is_scalar( $action ) ) {
				$value = sanitize_key( (string) $action );
				if ( '' !== $value ) {
					$out[] = $value;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function recipient_domain_hash( array $settings ): string {
		$value = (string) ( $settings['email_to'] ?? $settings['to_email'] ?? '' );
		$domains = [];
		foreach ( preg_split( '/[,;]+/', $value ) ?: [] as $recipient ) {
			$recipient = trim( (string) $recipient );
			if ( preg_match( '/<([^<>]+)>/', $recipient, $match ) ) {
				$recipient = trim( (string) $match[1] );
			}
			$parts = explode( '@', $recipient );
			if ( 2 === count( $parts ) && '' !== $parts[1] ) {
				$domains[] = strtolower( trim( $parts[1] ) );
			}
		}
		return [] === $domains ? '' : hash_hmac( 'sha256', implode( '|', array_values( array_unique( $domains ) ) ), wp_salt( 'auth' ) );
	}

	private static function address_shape_status( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return 'invalid';
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 'empty';
		}
		if ( preg_match( '/\{\{|\}\}|\[[^\]]+\]|\[field[^\]]*\]|dynamic|shortcode/i', $value ) ) {
			return 'dynamic';
		}
		if ( preg_match( '/<([^<>]+)>/', $value, $match ) ) {
			$value = trim( (string) $match[1] );
		}
		$valid = function_exists( 'is_email' ) ? (bool) \is_email( $value ) : false !== filter_var( $value, FILTER_VALIDATE_EMAIL );
		return $valid ? 'valid' : 'invalid';
	}

	/** @return array<string,mixed> */
	private static function providers(): array {
		$plugins = self::plugin_names();
		$smtp = false;
		foreach ( $plugins as $plugin ) {
			if ( preg_match( '/smtp|mailgun|sendgrid|postmark|fluent-?smtp|wp-mail-smtp/i', $plugin ) ) {
				$smtp = true;
				break;
			}
		}
		return [
			'smtp_plugin_detected'    => $smtp,
			'wp_mail_provider_hooked' => has_filter( 'wp_mail' ) > 0 || has_action( 'phpmailer_init' ) > 0,
			'active_plugin_count'     => count( $plugins ),
			'newsman_plugin_detected' => (bool) count( preg_grep( '/newsman/i', $plugins ) ?: [] ),
			'provider_configuration_status' => $smtp ? 'detected_not_verified' : 'not_detected',
		];
	}

	/** @return list<string> */
	private static function plugin_names(): array {
		$active = get_option( 'active_plugins', [] );
		if ( ! is_array( $active ) ) {
			return [];
		}
		return array_values( array_map( static fn ( mixed $value ): string => sanitize_text_field( (string) $value ), $active ) );
	}
}
