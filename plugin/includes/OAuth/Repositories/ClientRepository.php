<?php
/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Derived from includes/oauth/repositories/client-repository.php
 * Source SHA-256: db363947de4e5d1a9b2ef98b3ada525a4c5513e5d976c9a820296f79f2cc2796
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\OAuth\Repositories;

// League interfaces use camelCase parameter names and pair entities with repositories.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;

defined( 'ABSPATH' ) || exit;

final class ClientEntity implements ClientEntityInterface {
	use ClientTrait;
	use EntityTrait;

	public function setName( string $name ): void {
		$this->name = $name;
	}

	/**
	 * @param list<string> $uris Redirect URIs.
	 */
	public function setRedirectUri( array $uris ): void {
		$this->redirectUri = $uris;
	}

	public function setIsConfidential( bool $confidential ): void {
		$this->isConfidential = $confidential;
	}
}

final class ClientRepository implements ClientRepositoryInterface {

	/**
	 * @param list<string> $redirect_uris Redirect URIs.
	 */
	public function create(
		string $client_name,
		array $redirect_uris,
		string $registered_by_ip,
		bool $admin_created = false
	): string {
		global $wpdb;

		$client_id = bin2hex( random_bytes( 16 ) );
		$wpdb->insert(
			$wpdb->prefix . 'stonewright_oauth_clients',
			[
				'client_id'             => $client_id,
				'client_name'           => $client_name,
				'redirect_uris'         => wp_json_encode( $redirect_uris ),
				'is_confidential'       => 0,
				'client_secret_hash'    => null,
				'created_at'            => gmdate( 'Y-m-d H:i:s' ),
				'last_used_at'          => null,
				'registered_by_ip_hash' => hash( 'sha256', $registered_by_ip ),
				'admin_created'         => $admin_created ? 1 : 0,
			]
		);
		return $client_id;
	}

	public function getClientEntity( mixed $clientIdentifier ): ?ClientEntityInterface {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT client_id, client_name, redirect_uris, is_confidential
				FROM {$wpdb->prefix}stonewright_oauth_clients
				WHERE client_id = %s",
				(string) $clientIdentifier
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}

		$uris = json_decode( (string) ( $row['redirect_uris'] ?? '[]' ), true );
		if ( ! is_array( $uris ) ) {
			$uris = [];
		}

		$entity = new ClientEntity();
		$entity->setIdentifier( (string) ( $row['client_id'] ?? '' ) );
		$entity->setName( (string) ( $row['client_name'] ?? '' ) );
		$entity->setRedirectUri( array_values( array_map( 'strval', $uris ) ) );
		$entity->setIsConfidential( (bool) ( $row['is_confidential'] ?? false ) );
		return $entity;
	}

	public function validateClient( mixed $clientIdentifier, mixed $clientSecret, mixed $grantType ): bool {
		unset( $clientSecret, $grantType );
		return null !== $this->getClientEntity( $clientIdentifier );
	}

	public function touchLastUsed( string $client_id ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'stonewright_oauth_clients',
			[ 'last_used_at' => gmdate( 'Y-m-d H:i:s' ) ],
			[ 'client_id' => $client_id ]
		);
	}

	public function revoke( string $client_id ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'stonewright_oauth_clients', [ 'client_id' => $client_id ] );
	}
}
