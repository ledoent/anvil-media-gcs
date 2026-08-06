<?php
/**
 * WordPress integration.
 *
 * @package Ledoent\AnvilMediaGcs
 */

declare( strict_types = 1 );

namespace Ledoent\AnvilMediaGcs;

/**
 * Wires WordPress at the smallest number of points that actually matter.
 *
 * Design rule: never rewrite the database. Every URL is filtered at render
 * time, so deactivating the plugin restores local behaviour with no migration
 * and no repair step. That is the difference between a reversible change and a
 * one-way door.
 */
final class Plugin {

	public function __construct(
		private readonly Storage $storage,
		/** Public base URL objects are served from (CDN host or storage.googleapis.com). */
		private readonly string $public_base_url
	) {}

	public function boot(): void {
		// Point uploads at the bucket. Layout is preserved (YYYY/MM/), which is
		// what lets migration be an rsync and keeps srcset working untouched.
		add_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );

		// Serve from the bucket.
		add_filter( 'wp_get_attachment_url', array( $this, 'filter_attachment_url' ), 99, 2 );

		// EXIF/IPTC are C-level and cannot read a PHP stream. Copy locally first.
		add_filter( 'wp_read_image_metadata', array( $this, 'read_image_metadata_locally' ), 10, 5 );

		// wp_unique_filename() otherwise scandir()s the whole uploads prefix and
		// filters in PHP — on a large library that is dozens of LIST calls per
		// upload. A prefix query answers the same question in one.
		add_filter( 'pre_wp_unique_filename_file_list', array( $this, 'unique_filename_file_list' ), 10, 3 );

		// Exact-string-match against _wp_attached_file fails when served from a
		// CDN host. Core added this short-circuit in 6.7.
		add_filter( 'pre_attachment_url_to_postid', array( $this, 'attachment_url_to_postid' ), 10, 2 );

		// A bucket-wide recursive walk to compute site space is never acceptable.
		add_filter( 'pre_get_space_used', array( $this, 'skip_space_used' ) );
	}

	/**
	 * @param array<string,mixed> $dirs
	 * @return array<string,mixed>
	 */
	public function filter_upload_dir( array $dirs ): array {
		$bucket = $this->storage->bucket_name();

		$dirs['basedir'] = "gs://{$bucket}";
		$dirs['path']    = $dirs['basedir'] . $dirs['subdir'];
		$dirs['baseurl'] = rtrim( $this->public_base_url, '/' );
		$dirs['url']     = $dirs['baseurl'] . $dirs['subdir'];

		return $dirs;
	}

	public function filter_attachment_url( string $url, int $attachment_id ): string {
		$file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! is_string( $file ) || '' === $file ) {
			return $url;
		}
		return rtrim( $this->public_base_url, '/' ) . '/' . ltrim( $file, '/' );
	}

	/**
	 * exif_read_data() and iptcparse() are implemented in C against real file
	 * descriptors and will never work through a stream wrapper. Pull the object
	 * to a temp file, read there, delete.
	 *
	 * @param array<string,mixed>|null $meta
	 * @param array<string,mixed>|null $iptc
	 * @return array<string,mixed>|null
	 */
	public function read_image_metadata_locally( $meta, string $file, int $image_type, $iptc, $attachment_id = null ) {
		if ( ! str_starts_with( $file, 'gs://' ) ) {
			return $meta;
		}

		$tmp = wp_tempnam( basename( $file ) );
		if ( ! $tmp ) {
			return $meta;
		}

		try {
			if ( false === copy( $file, $tmp ) ) {
				return $meta;
			}
			// Re-enter core with a local path. remove/add avoids infinite recursion.
			remove_filter( 'wp_read_image_metadata', array( $this, 'read_image_metadata_locally' ), 10 );
			$result = wp_read_image_metadata( $tmp );
			add_filter( 'wp_read_image_metadata', array( $this, 'read_image_metadata_locally' ), 10, 5 );
			return $result;
		} finally {
			// unlink, not wp_delete_file — the latter re-enters the filter stack.
			if ( file_exists( $tmp ) ) {
				unlink( $tmp );
			}
		}
	}

	/**
	 * One prefix query instead of a full-bucket scandir().
	 *
	 * @return string[]
	 */
	public function unique_filename_file_list( $files, string $dir, string $filename ): array {
		if ( ! str_starts_with( $dir, 'gs://' ) ) {
			return is_array( $files ) ? $files : array();
		}

		$bucket = $this->storage->bucket_name();
		$prefix = ltrim( substr( $dir, strlen( "gs://{$bucket}" ) ), '/' );
		$prefix = '' === $prefix ? '' : rtrim( $prefix, '/' ) . '/';

		// Only names colliding on the stem matter to wp_unique_filename().
		$stem  = pathinfo( $filename, PATHINFO_FILENAME );
		$names = array();

		foreach ( $this->storage->bucket()->objects( array( 'prefix' => $prefix . $stem ) ) as $object ) {
			$names[] = basename( $object->name() );
		}

		return $names;
	}

	/**
	 * @return int|null
	 */
	public function attachment_url_to_postid( $post_id, string $url ) {
		if ( null !== $post_id && 0 !== $post_id ) {
			return $post_id;
		}

		$base = rtrim( $this->public_base_url, '/' ) . '/';
		if ( ! str_starts_with( $url, $base ) ) {
			return $post_id;
		}

		global $wpdb;
		$relative = substr( $url, strlen( $base ) );

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
				$relative
			)
		);

		return $found ? (int) $found : $post_id;
	}

	/**
	 * @return int
	 */
	public function skip_space_used() {
		// Computing this means walking the bucket. Report 0 rather than stall.
		return 0;
	}
}
