<?php
/**
 * Caching stream wrapper.
 *
 * @package Ledoent\AnvilMediaGcs
 */

declare( strict_types = 1 );

namespace Ledoent\AnvilMediaGcs;

use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\StreamWrapper as SdkStreamWrapper;

/**
 * The SDK stream wrapper with a survivable url_stat().
 *
 * THE PROBLEM
 *
 * Google\Cloud\Storage\StreamWrapper::url_stat() costs three API calls per
 * stat, with no caching:
 *
 *   1. getDirectoryInfo()  -> objects(['prefix' => …, 'resultLimit' => 1])
 *   2. urlStatFile()       -> $object->info()
 *   3. $bucket->isWritable() -> getResumableUploader()->getResumeUri()
 *
 * The third is the egregious one: a live POST initiating a resumable upload
 * session, issued on every stat, purely to decide a permission bit that is
 * then thrown away.
 *
 * WordPress stats constantly. wp_mkdir_p() runs on every wp_upload_dir() call
 * with $create_dir true; wp_unique_filename() loops file_exists() against
 * candidate names; _load_image_to_edit_path() and the image-edit suffix loop
 * do the same. Left alone this turns a page load into dozens of round trips.
 *
 * THE FIX
 *
 * A per-request LRU keyed on the path, plus a static mode template rather than
 * a live writability probe. PHP's own stat cache absorbs repeated stats of the
 * *same* path within a request, but not distinct paths — which is exactly what
 * the collision loops generate — so this cache is still load-bearing.
 *
 * Both udx/wp-stateless and WordPress.org's photo-directory plugin subclass
 * url_stat() for the same reason; this is the known-correct shape.
 */
class StreamWrapper extends SdkStreamWrapper {

	/**
	 * Directory mode returned for anything that looks like a prefix.
	 *
	 * @var int
	 */
	private const MODE_DIR = 0040777;

	/**
	 * File mode returned for objects.
	 *
	 * @var int
	 */
	private const MODE_FILE = 0100777;

	/**
	 * Bounded LRU of path => stat array, or false for a known miss.
	 *
	 * @var array<string,array<int|string,int>|false>
	 */
	private static array $stat_cache = array();

	/**
	 * Cache ceiling. Generous — entries are tiny — but bounded.
	 *
	 * @var int
	 */
	private static int $stat_cache_limit = 512;

	/**
	 * Client used for stat lookups.
	 *
	 * @var StorageClient|null
	 */
	private static ?StorageClient $client = null;

	/**
	 * Supply the client used by cached stat lookups.
	 *
	 * @param StorageClient $client Configured storage client.
	 * @return void
	 */
	public static function attach( StorageClient $client ): void {
		self::$client = $client;
	}

	/**
	 * Cached stat.
	 *
	 * Returns a synthesised stat array rather than asking GCS for a mode.
	 * Callers in WordPress only ever consult existence, size and mtime.
	 *
	 * @param string $path  gs:// URL.
	 * @param int    $flags Stream API flags.
	 * @return array<int|string,int>|false
	 */
	public function url_stat( $path, $flags ) {
		if ( array_key_exists( $path, self::$stat_cache ) ) {
			// Refresh recency.
			$hit = self::$stat_cache[ $path ];
			unset( self::$stat_cache[ $path ] );
			self::$stat_cache[ $path ] = $hit;
			return $hit;
		}

		$stat = $this->compute_stat( $path, $flags );

		if ( count( self::$stat_cache ) >= self::$stat_cache_limit ) {
			array_shift( self::$stat_cache );
		}
		self::$stat_cache[ $path ] = $stat;

		return $stat;
	}

	/**
	 * One API call, not three.
	 *
	 * @param string $path  gs:// URL.
	 * @param int    $flags Stream API flags.
	 * @return array<int|string,int>|false
	 */
	private function compute_stat( string $path, int $flags ) {
		$parsed = self::parse_path( $path );
		if ( null === $parsed ) {
			return false;
		}
		[ $bucket_name, $key ] = $parsed;

		// A bare bucket root, or a trailing slash, is a directory by definition.
		if ( '' === $key || str_ends_with( $key, '/' ) ) {
			return $this->stat_array( self::MODE_DIR, 0, time() );
		}

		try {
			$bucket = self::$client?->bucket( $bucket_name );
			if ( null === $bucket ) {
				return false;
			}

			$object = $bucket->object( $key );
			if ( $object->exists() ) {
				$info = $object->info();
				return $this->stat_array(
					self::MODE_FILE,
					(int) ( $info['size'] ?? 0 ),
					isset( $info['updated'] ) ? strtotime( $info['updated'] ) : time()
				);
			}

			// Not an object. It may still be a prefix (a "directory"), which
			// WordPress checks before creating upload subdirectories. One
			// bounded LIST answers that.
			foreach ( $bucket->objects(
				array(
					'prefix'      => $key . '/',
					'resultLimit' => 1,
				)
			) as $ignored ) {
				return $this->stat_array( self::MODE_DIR, 0, time() );
			}

			return false;
		} catch ( \Throwable $e ) {
			// Quiet unless the caller asked to be told, matching stream API
			// semantics: file_exists() on an unreachable backend is false, not
			// an exception thrown through WordPress.
			if ( 0 === ( $flags & STREAM_URL_STAT_QUIET ) ) {
				trigger_error(
					sprintf( 'anvil-media-gcs: stat failed for %s: %s', $path, $e->getMessage() ),
					E_USER_WARNING
				);
			}
			return false;
		}
	}

	/**
	 * Both numeric and string keys, as stat() callers expect.
	 *
	 * @param int $mode  Stat mode bits.
	 * @param int $size  Object size in bytes.
	 * @param int $mtime Modification time as a Unix timestamp.
	 * @return array<int|string,int>
	 */
	private function stat_array( int $mode, int $size, int $mtime ): array {
		$values = array(
			'dev'     => 0,
			'ino'     => 0,
			'mode'    => $mode,
			'nlink'   => 0,
			'uid'     => 0,
			'gid'     => 0,
			'rdev'    => 0,
			'size'    => $size,
			'atime'   => $mtime,
			'mtime'   => $mtime,
			'ctime'   => $mtime,
			'blksize' => 0,
			'blocks'  => 0,
		);

		return array_merge( array_values( $values ), $values );
	}

	/**
	 * Split a gs:// URL into bucket and object key.
	 *
	 * @param string $path gs:// URL.
	 * @return array{0:string,1:string}|null Bucket and key, or null if not gs://.
	 */
	private static function parse_path( string $path ): ?array {
		if ( ! str_starts_with( $path, 'gs://' ) ) {
			return null;
		}
		$rest  = substr( $path, 5 );
		$slash = strpos( $rest, '/' );
		if ( false === $slash ) {
			return array( $rest, '' );
		}
		return array( substr( $rest, 0, $slash ), substr( $rest, $slash + 1 ) );
	}

	/**
	 * Empty the stat cache. Test seam.
	 *
	 * @return void
	 */
	public static function flush_stat_cache(): void {
		self::$stat_cache = array();
	}

	/**
	 * Number of cached stat entries. Test seam.
	 *
	 * @return int
	 */
	public static function stat_cache_size(): int {
		return count( self::$stat_cache );
	}
}
