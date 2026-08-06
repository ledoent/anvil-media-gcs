<?php
/**
 * Cache-behaviour tests for the stream wrapper.
 *
 * No network: with no client attached every stat resolves to false, which
 * still exercises the cache path — and negative caching is exactly what
 * matters, because wp_unique_filename()'s collision loop stats names that do
 * not exist.
 *
 * @package Ledoent\AnvilMediaGcs
 */

declare( strict_types = 1 );

namespace Ledoent\AnvilMediaGcs\Tests;

use Ledoent\AnvilMediaGcs\StreamWrapper;
use PHPUnit\Framework\TestCase;

final class StreamWrapperTest extends TestCase {

	protected function setUp(): void {
		StreamWrapper::flush_stat_cache();
	}

	public function test_cache_starts_empty_and_records_lookups(): void {
		$this->assertSame( 0, StreamWrapper::stat_cache_size() );

		$w = new StreamWrapper();
		$w->url_stat( 'gs://bucket/2026/08/a.jpg', STREAM_URL_STAT_QUIET );

		$this->assertSame( 1, StreamWrapper::stat_cache_size() );
	}

	/**
	 * Misses must be cached too. The collision loop stats names that do not
	 * exist; if only hits were cached it would re-query on every iteration.
	 */
	public function test_negative_results_are_cached(): void {
		$w    = new StreamWrapper();
		$path = 'gs://bucket/2026/08/does-not-exist.jpg';

		$this->assertFalse( $w->url_stat( $path, STREAM_URL_STAT_QUIET ) );
		$this->assertSame( 1, StreamWrapper::stat_cache_size() );
		$this->assertFalse( $w->url_stat( $path, STREAM_URL_STAT_QUIET ) );
		$this->assertSame( 1, StreamWrapper::stat_cache_size(), 'repeat must not add an entry' );
	}

	public function test_distinct_paths_each_cache(): void {
		$w = new StreamWrapper();
		for ( $i = 0; $i < 5; $i++ ) {
			$w->url_stat( "gs://bucket/2026/08/f-{$i}.jpg", STREAM_URL_STAT_QUIET );
		}
		$this->assertSame( 5, StreamWrapper::stat_cache_size() );
	}

	public function test_cache_is_bounded(): void {
		$w = new StreamWrapper();
		for ( $i = 0; $i < 600; $i++ ) {
			$w->url_stat( "gs://bucket/2026/08/f-{$i}.jpg", STREAM_URL_STAT_QUIET );
		}
		$this->assertLessThanOrEqual( 512, StreamWrapper::stat_cache_size(), 'LRU must evict' );
	}

	/**
	 * A trailing slash is a directory by definition and must resolve without
	 * any API call — wp_mkdir_p() hits this on every wp_upload_dir().
	 */
	public function test_directory_paths_stat_as_directories_without_a_client(): void {
		$w    = new StreamWrapper();
		$stat = $w->url_stat( 'gs://bucket/2026/08/', STREAM_URL_STAT_QUIET );

		$this->assertIsArray( $stat );
		$this->assertSame( 0040777, $stat['mode'] );
		$this->assertArrayHasKey( 2, $stat, 'stat() callers index numerically as well as by name' );
		$this->assertSame( $stat['mode'], $stat[2] );
	}

	public function test_non_gs_paths_are_rejected(): void {
		$w = new StreamWrapper();
		$this->assertFalse( $w->url_stat( '/var/www/uploads/a.jpg', STREAM_URL_STAT_QUIET ) );
	}
}
