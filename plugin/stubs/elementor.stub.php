<?php
/**
 * Elementor PHPStan stubs.
 * These minimal declarations allow PHPStan to resolve Elementor types used in
 * Stonewright code without requiring a full Elementor installation.
 */
declare( strict_types=1 );

namespace Elementor;

abstract class Widget_Base {
	abstract public function get_name(): string;
	abstract public function get_title(): string;
	/** @return list<string> */
	abstract public function get_categories(): array;
	abstract protected function render(): void;
	protected function register_controls(): void {}
	public function get_icon(): string { return ''; }
	/** @return array<string, mixed> */
	public function get_settings_for_display(): array { return []; }
	/** @param array<string, mixed> $args */
	public function start_controls_section( string $id, array $args = [] ): void {}
	/** @param array<string, mixed> $args */
	public function add_control( string $id, array $args = [] ): void {}
	public function end_controls_section(): void {}
}

class Widgets_Manager {
	/** @return array<string, object>|object|null */
	public function get_widget_types( ?string $name = null ): array|object|null { return null; }
	public function register( Widget_Base $widget ): void {}
}

class Controls_Manager {
	public const TEXT        = 'text';
	public const TEXTAREA    = 'textarea';
	public const NUMBER      = 'number';
	public const COLOR       = 'color';
	public const SELECT      = 'select';
	public const URL         = 'url';
	public const MEDIA       = 'media';
	public const SWITCHER    = 'switcher';
	public const TAB_CONTENT = 'content';
}
