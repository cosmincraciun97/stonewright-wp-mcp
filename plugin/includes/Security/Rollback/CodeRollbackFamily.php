<?php
/**
 * Rollback handler for code: theme files, Customizer CSS, snippets and sandbox files.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Rollback;

use Stonewright\WpMcp\Security\Adapters\CodeAdapter;

/**
 * Restores go through CodeAdapter::restore(), which writes through the path the original write used, so the path
 * allowlist, the PHP syntax check, the byte budget and the readback of that path still apply. The adapter writes its
 * own rollback row. Code needs a person: the engine requires human approval for every row of these families.
 */
final class CodeRollbackFamily implements RollbackFamilyHandler {

	public function families(): array {
		return CodeAdapter::FAMILIES;
	}

	public function live_image( array $row ): string|array|\WP_Error|null {
		$live = CodeAdapter::live_image( $row );
		return null === $live ? FamilySupport::unreadable() : $live['image'];
	}

	public function restore( array $row, string|array|null $image, array $options ): array {
		$result = CodeAdapter::restore(
			(string) $row['change_id'],
			[
				'expected_current_sha256' => (string) ( $options['expected_current_sha256'] ?? '' ),
				'kind'                   => 'redo' === ( $options['kind'] ?? '' ) ? 'redo' : 'rollback',
			]
		);
		return [
			'status'             => 'not_available' === $result['status'] ? 'failed' : $result['status'],
			'detail'             => $result['detail'],
			'rollback_change_id' => $result['rollback_change_id'],
		];
	}

	public function describe( array $row, string|array|null $image ): string {
		return null === $image
			? __( 'Removes the file or snippet that the change created.', 'stonewright' )
			: __( 'Writes the code back as it was, through the same checks as any code change.', 'stonewright' );
	}

	public function records_own_row( array $row ): bool {
		return true;
	}

	public function requires_human( array $row ): bool {
		return true;
	}
}
