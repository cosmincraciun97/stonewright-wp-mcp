<?php
/**
 * Writes the plugin performs for itself: bundled pack refresh and verified evidence.
 *
 * @package Stonewright
 * @license GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SkillLibrary\Site;

use Stonewright\WpMcp\SkillLibrary\LifecycleDecisions;
use Stonewright\WpMcp\SkillLibrary\MutationBoundary;
use Stonewright\WpMcp\SkillLibrary\RecordRules;
use Stonewright\WpMcp\SkillLibrary\Repository;

/**
 * Local saves cannot set provenance, verification, or conflict findings. These
 * writes can, so they run only through the boundary's system channel and keep
 * the same order as every other mutation: validate, authorize, write atomically,
 * record the outcome. A record that would be live must pass activation lint.
 */
final class SystemWrites {

	private Repository $repository;

	private MutationBoundary $boundary;

	/** @var callable(): array<int, string> */
	private $tool_names;

	/** @param callable(): array<int, string> $tool_names */
	public function __construct( Repository $repository, MutationBoundary $boundary, callable $tool_names ) {
		$this->repository = $repository;
		$this->boundary   = $boundary;
		$this->tool_names = $tool_names;
	}

	/** @param array<string, mixed> $record */
	public function insert( string $action, array $record ): int|\WP_Error {
		$record = RecordRules::normalize( $record );
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		$ready = $this->ready( $record );
		if ( true !== $ready ) {
			return $ready;
		}
		unset( $record['id'] );
		$record['revision'] = 1;
		$result             = $this->apply( $action, $record, fn() => $this->repository->insert_unique( $record ) );
		return is_int( $result ) && $result > 0 ? $result : ( is_wp_error( $result ) ? $result : self::failed() );
	}

	/**
	 * Replaces a stored record. Withdrawals keep the stored text as it is and are
	 * not validated again, so guidance that a newer check rejects can still be
	 * taken out of service.
	 *
	 * @param array<string, mixed> $previous
	 * @param array<string, mixed> $record
	 */
	public function replace( string $action, array $previous, array $record, bool $validate = true ): bool|\WP_Error {
		$id       = $previous['id'] ?? null;
		$revision = $previous['revision'] ?? null;
		if ( ! is_int( $id ) || $id < 1 || ! is_int( $revision ) || $revision < 1 ) {
			return new \WP_Error( 'stonewright_skill_repository_contract', 'The repository did not supply a positive logical id and revision.' );
		}
		$record = array_replace(
			$record,
			[
				'id'       => $id,
				'slug'     => $previous['slug'] ?? '',
				'revision' => $revision + 1,
			]
		);
		if ( $validate ) {
			$record = RecordRules::normalize( $record );
			if ( is_wp_error( $record ) ) {
				return $record;
			}
			$ready = $this->ready( $record );
			if ( true !== $ready ) {
				return $ready;
			}
		}
		$result = $this->apply( $action, $record, fn() => $this->repository->exchange_record( $id, $record, $revision ) );
		return true === $result ? true : ( is_wp_error( $result ) ? $result : self::failed() );
	}

	/** @param array<string, mixed> $record */
	private function ready( array $record ): bool|\WP_Error {
		if ( empty( $record['enabled'] ) || 'active' !== ( $record['status'] ?? 'active' ) ) {
			return true;
		}
		// Tool references never block shipped text, so the registered tool list is read only for site guidance.
		$tools = LifecycleDecisions::protected_origin( $record ) ? [] : ( $this->tool_names )();
		$lint  = RecordRules::review( $record, $tools );
		if ( [] === RecordRules::blocking( $record, $lint['errors'] ) ) {
			return true;
		}
		return new \WP_Error(
			'stonewright_skill_lint_failed',
			'Resolve lint findings before activating the skill.',
			[
				'status' => 400,
				'lint'   => $lint,
			]
		);
	}

	/**
	 * @param array<string, mixed>                $record
	 * @param callable(): (int|bool|\WP_Error) $write
	 */
	private function apply( string $action, array $record, callable $write ): int|bool|\WP_Error {
		$summary = [
			'skill_id'           => (int) ( $record['id'] ?? 0 ),
			'slug'               => (string) ( $record['slug'] ?? '' ),
			'revision'           => (int) ( $record['revision'] ?? 0 ),
			'source'             => (string) ( $record['source'] ?? '' ),
			'status'             => (string) ( $record['status'] ?? '' ),
			'verification_count' => (int) ( $record['verification_count'] ?? 0 ),
			'content_hash'       => hash( 'sha256', (string) ( $record['content'] ?? '' ) ),
		];
		$permission = $this->boundary->authorize( $action, $summary, '' );
		if ( true !== $permission ) {
			$this->boundary->record( $action, $summary, 'blocked' );
			return is_wp_error( $permission ) ? $permission : new \WP_Error( 'stonewright_skill_permission_denied', 'The authenticated write boundary refused this action.', [ 'status' => 403 ] );
		}
		try {
			$result = $write();
		} catch ( \Throwable $error ) {
			$result = self::failed();
		}
		$this->boundary->record( $action, $summary, is_wp_error( $result ) || false === $result || 0 === $result ? 'error' : 'ok' );
		return $result;
	}

	private static function failed(): \WP_Error {
		return new \WP_Error( 'stonewright_skill_write_failed', 'The repository could not complete the atomic skill mutation.', [ 'status' => 500 ] );
	}
}
