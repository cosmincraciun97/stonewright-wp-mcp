<?php
// FIXTURE: a clean WordPress hook registration. Should pass cleanly.

add_filter( 'the_content', function ( $content ) {
	return $content . "\n<!-- via stonewright sandbox -->";
} );

add_action( 'init', 'my_stonewright_init', 10, 1 );
