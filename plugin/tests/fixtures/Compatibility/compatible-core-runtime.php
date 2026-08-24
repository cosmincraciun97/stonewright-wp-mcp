<?php
declare( strict_types=1 );

/** Synthetic WordPress core runtime fixtures for immutable compatibility targets. */
class WP_Ability {
	public function __construct( private string $name, private array $properties ) {}
	public function get_name(): string { return $this->name; }
	public function get_label(): string { return $this->name; }
	public function get_description(): string { return $this->name; }
	public function get_category(): string { return 'core'; }
	public function get_meta(): array { return $this->properties; }
	public function get_input_schema(): array { return []; }
	public function get_output_schema(): array { return []; }
}

class WP_Abilities_Registry {
	private function __construct() {}
	public static function get_instance(): ?self { return new self(); }
	public function register( string $name, array $properties ): ?WP_Ability {
		return new WP_Ability( $name, $properties );
	}
}
