<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities;

/**
 * Contract every Stonewright ability implements.
 */
interface Ability {

	public function name(): string;

	public function label(): string;

	public function description(): string;

	public function category(): string;

	/**
	 * @return array<string, mixed>
	 */
	public function input_schema(): array;

	/**
	 * @return array<string, mixed>
	 */
	public function output_schema(): array;

	/**
	 * @return array<string, mixed>
	 */
	public function meta(): array;

	/**
	 * @param array<string, mixed> $args
	 */
	public function permission_callback( array $args ): bool|\WP_Error;

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $args );
}
