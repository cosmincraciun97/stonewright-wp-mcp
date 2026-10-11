<?php
/**
 * List the change history of the site.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Security;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Admin\ChangesPage;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ChangeHistoryView;

/**
 * One short row per change, newest first, with the filters of the Changes page and paging. It reads the ledger and nothing
 * else: a row holds no stored content, no blob name and no hash.
 *
 * @stonewright-status stable
 */
final class ChangeHistoryList extends AbilityKernel {

	public function name(): string {
		return 'stonewright/change-history-list';
	}

	public function label(): string {
		return __( 'Change history list', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists the changes Stonewright recorded on this site, newest first, one short row each: change_id, time, kind (change, rollback or redo), family, resource label, ability, actor, status, summary, whether it can be restored and why not, parent_id and how many rollbacks and redos follow it. Filters as on Stonewright > Activity > Changes: family, resource, ability, actor (user id or login), status, from and to (YYYY-MM-DD, UTC), restorable and kind. Paged with page and per_page (1 to 100, default 25). Read-only and never returns stored content: call stonewright-change-diff-get for the diff of one change, then stonewright-change-rollback to undo it. Needs manage_options.', 'stonewright' );
	}

	public function category(): string {
		return 'security';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'family'     => [
					'type' => 'string',
					'enum' => ChangeLedger::FAMILIES,
				],
				'resource'   => [
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 200,
					'description' => 'The id or name of the changed resource: a post id, an option name, a theme file path.',
				],
				'ability'    => [
					'type'        => 'string',
					'maxLength'   => 120,
					'description' => 'The ability that made the change, such as stonewright/content-update-page. The prefix may be left out.',
				],
				'actor'      => [
					'type'        => [ 'integer', 'string' ],
					'description' => 'A user id or a login name. An account that does not exist matches no change.',
				],
				'status'     => [
					'type' => 'string',
					'enum' => array_keys( ChangesPage::STATUS_GROUPS ),
				],
				'from'       => [
					'type'        => 'string',
					'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
					'description' => 'First day to list, YYYY-MM-DD in UTC.',
				],
				'to'         => [
					'type'        => 'string',
					'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
					'description' => 'Last day to list, YYYY-MM-DD in UTC.',
				],
				'restorable' => [
					'type'        => 'boolean',
					'description' => 'true lists changes that can be undone, false the ones that cannot.',
				],
				'kind'       => [
					'type' => 'string',
					'enum' => ChangeLedger::KINDS,
				],
				'page'       => [
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				],
				'per_page'   => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => ChangeHistoryView::MAX_PER_PAGE,
					'default' => ChangeHistoryView::DEFAULT_PER_PAGE,
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'       => [ 'type' => 'boolean' ],
				'items'    => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'change_id'         => [ 'type' => 'string' ],
							'time'              => [ 'type' => 'string', 'description' => 'When the change was recorded, UTC.' ],
							'kind'              => [ 'type' => 'string', 'enum' => ChangeLedger::KINDS ],
							'family'            => [ 'type' => 'string' ],
							'resource_type'     => [ 'type' => 'string' ],
							'resource_label'    => [ 'type' => 'string' ],
							'ability'           => [ 'type' => 'string' ],
							'actor'             => [ 'type' => 'integer', 'description' => 'The user id, 0 when there was none.' ],
							'actor_name'        => [ 'type' => 'string' ],
							'status'            => [ 'type' => 'string' ],
							'summary'           => [ 'type' => 'string' ],
							'restorable'        => [ 'type' => 'boolean' ],
							'restorable_reason' => [ 'type' => 'string', 'description' => 'Why it cannot be restored; empty when it can.' ],
							'parent_id'         => [ 'type' => 'string', 'description' => 'The change a rollback or redo acts on; empty for a change.' ],
							'children'          => [ 'type' => 'integer', 'description' => 'How many rollbacks and redos act on this row.' ],
						],
					],
				],
				'total'    => [ 'type' => 'integer' ],
				'page'     => [ 'type' => 'integer' ],
				'per_page' => [ 'type' => 'integer' ],
				'pages'    => [ 'type' => 'integer' ],
				'has_more' => [ 'type' => 'boolean' ],
			],
			'required'   => [ 'ok', 'items', 'total', 'page', 'per_page', 'pages' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_read(
			$args,
			function ( array $a ): array|\WP_Error {
				$input = ChangeHistoryView::list_input( $a );
				if ( $input instanceof \WP_Error ) {
					return $input;
				}
				$list = ChangeLedger::list( $input['filters'], $input['per_page'], $input['page'] );

				return $this->ok(
					[
						'items'    => array_map( [ ChangeHistoryView::class, 'row' ], $list['items'] ),
						'total'    => $list['total'],
						'page'     => $list['page'],
						'per_page' => $list['per_page'],
						'pages'    => $list['pages'],
						'has_more' => $list['page'] < $list['pages'],
					]
				);
			}
		);
	}
}
