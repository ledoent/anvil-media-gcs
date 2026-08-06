<?php
/**
 * Hook-contract tests.
 *
 * These run without WordPress or a network. WordPress functions used by the
 * code under test are stubbed in bootstrap.php; what is asserted here is the
 * behaviour that WordPress's filter contracts actually depend on — which is
 * where the subtle bugs live.
 *
 * @package Ledoent\AnvilMediaGcs
 */

declare( strict_types = 1 );

namespace Ledoent\AnvilMediaGcs\Tests;

use Ledoent\AnvilMediaGcs\Plugin;
use Ledoent\AnvilMediaGcs\Storage;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase {

	private function plugin( string $base = 'https://cdn.example.com/' ): Plugin {
		return new Plugin( new Storage( 'test-bucket', 'test-project' ), $base );
	}

	public function test_upload_dir_points_at_bucket_and_preserves_layout(): void {
		$dirs = $this->plugin()->filter_upload_dir(
			array(
				'basedir' => '/var/www/html/wp-content/uploads',
				'baseurl' => 'https://site.test/wp-content/uploads',
				'subdir'  => '/2026/08',
				'path'    => '/var/www/html/wp-content/uploads/2026/08',
				'url'     => 'https://site.test/wp-content/uploads/2026/08',
				'error'   => false,
			)
		);

		$this->assertSame( 'gs://test-bucket', $dirs['basedir'] );
		// The YYYY/MM subdir must survive: _wp_attached_file stores paths
		// relative to basedir, so preserving it is what makes migration an
		// rsync and keeps wp_calculate_image_srcset() matching.
		$this->assertSame( 'gs://test-bucket/2026/08', $dirs['path'] );
		$this->assertSame( 'https://cdn.example.com/2026/08', $dirs['url'] );
		$this->assertFalse( $dirs['error'], 'unrelated keys must pass through' );
	}

	public function test_trailing_slash_on_base_url_does_not_double(): void {
		$dirs = $this->plugin( 'https://cdn.example.com/' )->filter_upload_dir(
			array(
				'basedir' => '',
				'baseurl' => '',
				'subdir'  => '/2026/08',
				'path'    => '',
				'url'     => '',
			)
		);
		$this->assertSame( 'https://cdn.example.com/2026/08', $dirs['url'] );
		$this->assertStringNotContainsString( '//2026', $dirs['url'] );
	}

	/**
	 * Regression: returning array() here short-circuits core's own scandir()
	 * with an EMPTY list, defeating collision detection for local uploads.
	 * Null must pass through untouched.
	 */
	public function test_unique_filename_passes_through_for_non_gcs_paths(): void {
		$this->assertNull(
			$this->plugin()->unique_filename_file_list( null, '/var/www/uploads/2026/08', 'photo.jpg' ),
			'null must stay null so core runs its own scandir()'
		);

		$existing = array( 'a.jpg', 'b.jpg' );
		$this->assertSame(
			$existing,
			$this->plugin()->unique_filename_file_list( $existing, '/var/www/uploads', 'photo.jpg' ),
			'an upstream filter\'s list must not be discarded'
		);
	}

	public function test_attachment_url_to_postid_defers_when_already_resolved(): void {
		$this->assertSame(
			42,
			$this->plugin()->attachment_url_to_postid( 42, 'https://cdn.example.com/2026/08/x.jpg' ),
			'an already-resolved id must never be overwritten'
		);
	}

	public function test_attachment_url_to_postid_ignores_foreign_hosts(): void {
		$this->assertNull(
			$this->plugin()->attachment_url_to_postid( null, 'https://elsewhere.test/2026/08/x.jpg' )
		);
	}

	/**
	 * Reporting 0 space used silently disables multisite upload quotas, so it
	 * must not happen unless explicitly requested.
	 */
	public function test_space_calculation_is_not_skipped_by_default(): void {
		$GLOBALS['_test_filters'] = array();
		$this->plugin()->boot();
		$this->assertArrayNotHasKey( 'pre_get_space_used', $GLOBALS['_test_filters'] );

		$GLOBALS['_test_filters'] = array();
		( new Plugin( new Storage( 'b', 'p' ), 'https://x.test', true ) )->boot();
		$this->assertArrayHasKey( 'pre_get_space_used', $GLOBALS['_test_filters'] );
	}
}
