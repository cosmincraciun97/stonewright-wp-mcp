<?php
/**
 * Script handles WordPress loads before edit.js.
 *
 * The editor script reads wp.blocks, wp.element, wp.blockEditor, wp.components
 * and wp.i18n, so each of them is declared here.
 *
 * @package Stonewright
 */

return [
	'dependencies' => [ 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-element', 'wp-i18n' ],
	'version'      => '1.0.0',
];
