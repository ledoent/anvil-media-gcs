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

	/**
	 * Public base URL, normalised once rather than at each call site.
	 *
	 * @var string
	 */
	private readonly string $base_url;

	/**
	 * Construct the integration.
	 *
	 * @param Storage $storage                 GCS client and bucket handle.
	 * @param string  $public_base_url         Base URL objects are served from
	 *                                         (a CDN host, or
	 *                                         storage.googleapis.com/bucket).
	 * @param bool    $skip_space_calculation  Report zero space used instead of
	 *                                         walking the bucket. Disables
	 *                                         multisite upload quotas.
	 */
	public function __construct(
		private readonly Storage $storage,
		string $public_base_url,
		private readonly bool $skip_space_calculation = false
	) {
		$this->base_url = rtrim( $public_base_url, '/' );
	}

	/**
	 * Register every filter.
	 *
	 * @throws \RuntimeException If called before WordPress loads its plugin API.
	 * @return void
	 */
	public function boot(): void {
		// wp-config.php runs before wp-includes/plugin.php, so add_filter() does
		// not exist yet. The stream wrapper must be registered that early; these
		// filters must not be. Fail loudly rather than fatally, and say where the
		// call belongs.
		if ( ! function_exists( 'add_filter' ) ) {
			throw new \RuntimeException(
				'Ledoent\\AnvilMediaGcs\\Plugin::boot() was called before WordPress loaded its '
				. 'plugin API. Register the stream wrapper in wp-config.php, but call boot() from '
				. 'an mu-plugin — see mu-plugin/anvil-media-gcs.php.'
			);
		}

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
		// CDN host. Core only added this short-circuit in 6.7, so register it
		// conditionally rather than making 6.7 a hard floor — everything else
		// here works back to 5.3, and WordPress install bases move slowly.
		// Without it, attachment_url_to_postid() simply stays as core wrote it.
		if ( version_compare( get_bloginfo( 'version' ), '6.7', '>=' ) ) {
			add_filter( 'pre_attachment_url_to_postid', array( $this, 'attachment_url_to_postid' ), 10, 2 );
		}

		// Only short-circuit space accounting when asked. Reporting 0 is a lie
		// that silently disables multisite upload quotas, so it must be opt-in.
		if ( $this->skip_space_calculation ) {
			add_filter( 'pre_get_space_used', array( $this, 'skip_space_used' ) );
		}
	}

	/**
	 * Point uploads at the bucket, preserving the YYYY/MM layout.
	 *
	 * @param array<string,mixed> $dirs Upload directory data from core.
	 * @return array<string,mixed> Directory data rewritten for GCS.
	 */
	public function filter_upload_dir( array $dirs ): array {
		$bucket = $this->storage->bucket_name();

		$dirs['basedir'] = "gs://{$bucket}";
		$dirs['path']    = $dirs['basedir'] . $dirs['subdir'];
		$dirs['baseurl'] = $this->base_url;
		$dirs['url']     = $dirs['baseurl'] . $dirs['subdir'];

		return $dirs;
	}

	/**
	 * Serve an attachment from the bucket or CDN.
	 *
	 * @param string $url           URL as core computed it.
	 * @param int    $attachment_id Attachment post ID.
	 * @return string Public URL for the object.
	 */
	public function filter_attachment_url( string $url, int $attachment_id ): string {
		$file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! is_string( $file ) || '' === $file ) {
			return $url;
		}
		return $this->base_url . '/' . ltrim( $file, '/' );
	}

	/**
	 * Read EXIF/IPTC from a local copy of the object.
	 *
	 * EXIF and IPTC parsing are implemented in C against real file
	 * descriptors and will never work through a stream wrapper. Pull the object
	 * to a temp file, read there, delete.
	 *
	 * Every parameter after $file is deliberately untyped. Core's docblock for
	 * this filter does not match what it actually passes: wp-admin/includes/
	 * image.php calls it with null for $image_type, and the trailing arguments
	 * vary by WordPress version — an int in some, an array in others. Declaring
	 * scalar types here throws a TypeError inside core's hook dispatcher and
	 * takes the whole site down on upload. Match the behaviour, not the docs.
	 *
	 * @param array<string,mixed>|null $meta          Metadata as core read it.
	 * @param string                   $file          Path to the image.
	 * @param mixed                    $image_type    IMAGETYPE_* constant, or null.
	 * @param mixed                    $iptc          IPTC data, unused here.
	 * @param mixed                    $attachment_id Attachment ID or context; unused.
	 * @return array<string,mixed>|null Metadata read from a local copy.
	 */
	public function read_image_metadata_locally( $meta, $file, $image_type = null, $iptc = null, $attachment_id = null ) {
		if ( ! is_string( $file ) || ! str_starts_with( $file, 'gs://' ) ) {
			return $meta;
		}

		$tmp = wp_tempnam( basename( $file ) );
		if ( ! $tmp ) {
			return $meta;
		}

		// Detach before re-entering core so we do not recurse. This MUST be
		// restored in finally: an exception inside wp_read_image_metadata()
		// would otherwise leave the filter detached for the rest of the
		// request, silently disabling metadata reads for every later upload.
		remove_filter( 'wp_read_image_metadata', array( $this, 'read_image_metadata_locally' ), 10 );

		try {
			if ( false === copy( $file, $tmp ) ) {
				return $meta;
			}
			return wp_read_image_metadata( $tmp );
		} finally {
			add_filter( 'wp_read_image_metadata', array( $this, 'read_image_metadata_locally' ), 10, 5 );
			// unlink, not wp_delete_file — the latter re-enters the filter stack.
			if ( file_exists( $tmp ) ) {
				unlink( $tmp );
			}
		}
	}

	/**
	 * One prefix query instead of a full-bucket scandir().
	 *
	 * Returning null lets core run its own scandir(); returning an array
	 * short-circuits it. Passing $files through untouched for non-gs paths is
	 * therefore load-bearing — returning array() here would short-circuit core
	 * with an EMPTY list and defeat collision detection on local uploads.
	 *
	 * @param string[]|null $files    Candidate list, or null to let core scan.
	 * @param string        $dir      Directory being written to.
	 * @param string        $filename Proposed filename.
	 * @return string[]|null Existing names sharing the stem, or $files untouched.
	 */
	public function unique_filename_file_list( $files, string $dir, string $filename ) {
		if ( ! str_starts_with( $dir, 'gs://' ) ) {
			return $files;
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
	 * Resolve an attachment ID from a bucket or CDN URL.
	 *
	 * Core's own lookup is an exact string match against _wp_attached_file and
	 * so fails whenever media is served from a different host.
	 *
	 * @param int|null $post_id Post ID resolved so far, if any.
	 * @param string   $url     URL to resolve.
	 * @return int|null Attachment ID, or the incoming value if not ours.
	 */
	public function attachment_url_to_postid( $post_id, string $url ) {
		if ( null !== $post_id && 0 !== $post_id ) {
			return $post_id;
		}

		$base     = $this->base_url . '/';
		$relative = self::relative_to_base( $url, $base );
		if ( null === $relative ) {
			return $post_id;
		}

		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
				$relative
			)
		);

		return $found ? (int) $found : $post_id;
	}

	/**
	 * The part of $url below $base, or null if $url is not under it.
	 *
	 * A plain str_starts_with() is not enough, because the URLs stored in a real
	 * database are not all written the way core writes them:
	 *
	 *   //cdn.example.com/2026/08/x.jpg   protocol-relative
	 *   http://cdn.example.com/…          http, against an https base
	 *
	 * Page builders are the main source of the first form. Slider Revolution
	 * persists every slide image protocol-relative in data-thumb/data-lazyload,
	 * and WPBakery does the same in saved layout markup — a single real site
	 * audited during this plugin's development held 1,614 of them. Those are
	 * exactly the sites this plugin targets, so failing to resolve their media
	 * is not an edge case.
	 *
	 * The comparison drops the scheme from both sides. The host and path still
	 * have to match exactly, so this widens what is recognised without making
	 * the match loose.
	 *
	 * @param string $url  URL to test.
	 * @param string $base Base URL, with a trailing slash.
	 * @return string|null Path below the base, or null when not a match.
	 */
	private static function relative_to_base( string $url, string $base ): ?string {
		if ( str_starts_with( $url, $base ) ) {
			return substr( $url, strlen( $base ) );
		}

		$strip = static function ( string $value ): string {
			return preg_replace( '#^(?:https?:)?//#i', '', $value ) ?? $value;
		};

		$bare_base = $strip( $base );
		$bare_url  = $strip( $url );

		// Only a scheme may differ. If stripping changed neither string then the
		// URL was relative to begin with and is not ours to claim.
		if ( $bare_base === $base && $bare_url === $url ) {
			return null;
		}

		if ( ! str_starts_with( $bare_url, $bare_base ) ) {
			return null;
		}

		return substr( $bare_url, strlen( $bare_base ) );
	}

	/**
	 * Report zero rather than walk the entire bucket.
	 *
	 * Only registered when $skip_space_calculation is true. Enabling it
	 * disables multisite upload quota enforcement — that is the trade.
	 *
	 * @return int
	 */
	public function skip_space_used(): int {
		return 0;
	}
}
