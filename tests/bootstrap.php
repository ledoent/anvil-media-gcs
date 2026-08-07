<?php
/**
 * Minimal WordPress stubs so hook-contract tests run with no WP install.
 * Only the functions the code under test actually calls.
 *
 * @package Ledoent\AnvilMediaGcs
 */
declare( strict_types = 1 );

require_once __DIR__ . '/../vendor/autoload.php';

$GLOBALS['_test_filters'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, $cb, int $priority = 10, int $args = 1 ): bool {
		$GLOBALS['_test_filters'][ $hook ][] = array( $cb, $priority, $args );
		return true;
	}
}
if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( string $hook, $cb, int $priority = 10 ): bool {
		unset( $GLOBALS['_test_filters'][ $hook ] );
		return true;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $id, string $key, bool $single = false ) {
		return $GLOBALS['_test_postmeta'][ $id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( string $show = '' ) {
        return 'version' === $show ? ( $GLOBALS['_test_wp_version'] ?? '6.7' ) : '';
    }
}
if ( ! function_exists( 'wp_tempnam' ) ) {
	function wp_tempnam( string $filename = '' ) {
		return tempnam( sys_get_temp_dir(), 'anvil' ); }
}
if ( ! function_exists( 'wp_read_image_metadata' ) ) {
	function wp_read_image_metadata( string $file ) {
		return array( 'stub' => true ); }
}
