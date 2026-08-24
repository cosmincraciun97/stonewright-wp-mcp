<?php
declare( strict_types=1 );

/** Synthetic package runtime with an incompatible immutable registry ABI. */
class WP_Ability {
	public function __construct( private string $name, private array $properties ) {}
	public function get_name(): string { return $this->name; }
	public function get_label(): string { return $this->name; }
	public function get_description(): string { return $this->name; }
	public function get_meta(): array { return $this->properties; }
	public function get_input_schema(): array { return []; }
	public function get_output_schema(): array { return []; }
}

class WP_Abilities_Registry {
	public static int $invocations = 0;
	private function __construct() {}
	public static function get_instance(): self { ++self::$invocations; return new self(); }
	public function register( string $name, array $properties, string $unexpected ): ?WP_Ability {
		++self::$invocations;
		unset( $unexpected );
		return new WP_Ability( $name, $properties );
	}
}
