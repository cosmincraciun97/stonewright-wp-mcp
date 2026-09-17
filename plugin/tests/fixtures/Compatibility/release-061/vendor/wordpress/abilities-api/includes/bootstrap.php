<?php
if ( ! class_exists( 'WP_Ability', false ) ) {
	require_once __DIR__ . '/abilities-api/class-wp-ability.php';
}
if ( ! class_exists( 'WP_Abilities_Registry', false ) ) {
	require_once __DIR__ . '/abilities-api/class-wp-abilities-registry.php';
}
