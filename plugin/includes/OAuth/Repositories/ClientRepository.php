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

	/**
	 * Resolve client display names in one query for audit/admin views.
	 *
	 * @param list<string> $client_ids OAuth client identifiers.
	 * @return array<string, string> Client id to sanitized display name.
	 */
	public function names_by_ids( array $client_ids ): array {
		global $wpdb;

		$client_ids = array_values(
			array_unique(
				array_filter(
					array_map( static fn( mixed $id ): string => sanitize_text_field( (string) $id ), $client_ids )
				)
			)
		);
		if ( [] === $client_ids ) {
			return [];
		}

		$placeholders = implode( ', ', array_fill( 0, count( $client_ids ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared -- The placeholder list is generated from the bounded id count and every value is still passed to prepare().
		$query = $wpdb->prepare(
			"SELECT client_id, client_name
			FROM {$wpdb->prefix}stonewright_oauth_clients
			WHERE client_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only generated %s placeholders are interpolated.
			...$client_ids
		);
		$rows  = $wpdb->get_results( $query, ARRAY_A );
		// phpcs:enable
		$names = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$id   = sanitize_text_field( (string) ( $row['client_id'] ?? '' ) );
			$name = sanitize_text_field( (string) ( $row['client_name'] ?? '' ) );
			if ( '' !== $id && '' !== $name ) {
				$names[ $id ] = $name;
			}
		}

		return $names;
	}

	/**
	 * @return list<array{client_id:string,client_name:string,created_at:string,last_used_at:string|null}>
	 */
	public function list_recent( int $limit = 50 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT client_id, client_name, created_at, last_used_at
				FROM {$wpdb->prefix}stonewright_oauth_clients
				ORDER BY created_at DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : [];
	}

	public function find_admin_client_id( string $client_name ): ?string {
		global $wpdb;

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT client_id FROM {$wpdb->prefix}stonewright_oauth_clients
				WHERE admin_created = 1 AND client_name = %s
				ORDER BY created_at DESC LIMIT 1",
				$client_name
			)
		);
		return is_string( $id ) && '' !== $id ? $id : null;
	}

	/**
	 * @return list<array{client_id:string,client_name:string,created_at:string,last_used_at:string|null}>
	 */
	public function list_admin_clients(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT client_id, client_name, created_at, last_used_at
			FROM {$wpdb->prefix}stonewright_oauth_clients
			WHERE admin_created = 1
			ORDER BY created_at DESC",
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : [];
	}
}
