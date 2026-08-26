<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\CustomCode;

/**
 * Optional contract for custom-code providers that own WordPress post types.
 */
interface OwnsPostTypesInterface {

	/**
	 * Registered post types this provider exclusively owns.
	 *
	 * @return list<string>
	 */
	public function owned_post_types(): array;
}
