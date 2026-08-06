<?php
/**
 * GCS client and stream wrapper registration.
 *
 * @package Ledoent\AnvilMediaGcs
 */

declare( strict_types = 1 );

namespace Ledoent\AnvilMediaGcs;

use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\Bucket;

/**
 * Owns the StorageClient and the gs:// stream wrapper.
 *
 * No credentials are ever passed to StorageClient. The SDK falls through to
 * Application Default Credentials, which on GKE resolves via the metadata
 * server through Workload Identity. This is the whole point of the plugin:
 * every other GCS offload plugin requires a service-account JSON key or an
 * HMAC pair, and Google blocks the creation of both by default for
 * organizations created on or after 2024-05-03.
 */
final class Storage {

	private ?StorageClient $client = null;
	private ?Bucket $bucket        = null;
	private bool $wrapper_registered = false;

	public function __construct(
		private readonly string $bucket_name,
		private readonly ?string $project_id = null
	) {}

	public function client(): StorageClient {
		if ( null === $this->client ) {
			$config = array();
			if ( null !== $this->project_id ) {
				$config['projectId'] = $this->project_id;
			}
			$this->client = new StorageClient( $config );
		}
		return $this->client;
	}

	public function bucket(): Bucket {
		if ( null === $this->bucket ) {
			$this->bucket = $this->client()->bucket( $this->bucket_name );
		}
		return $this->bucket;
	}

	public function bucket_name(): string {
		return $this->bucket_name;
	}

	/**
	 * Register the gs:// stream wrapper, replacing url_stat with a cached one.
	 *
	 * The SDK's own url_stat() issues three API calls per stat — a LIST, a GET,
	 * and a resumable-upload POST via isWritable() purely to read a mode bit —
	 * with no caching of its own. WordPress calls file_exists() constantly
	 * (wp_mkdir_p on every wp_upload_dir, the wp_unique_filename collision loop,
	 * the image-edit suffix loop), so the unpatched wrapper is unusable.
	 *
	 * Must be called before WordPress touches the filesystem — register from
	 * wp-config.php, not from plugin file scope.
	 */
	public function register_stream_wrapper(): void {
		if ( $this->wrapper_registered ) {
			return;
		}

		$this->client()->registerStreamWrapper();

		// Swap the SDK's wrapper for our caching subclass. Unregistering and
		// re-registering is the only way in: the SDK hardcodes its own class.
		stream_wrapper_unregister( 'gs' );
		StreamWrapper::attach( $this->client() );
		stream_wrapper_register( 'gs', StreamWrapper::class, STREAM_IS_URL );

		$this->wrapper_registered = true;
	}

	/**
	 * Object key for a path relative to the uploads basedir.
	 *
	 * Layout is preserved verbatim (YYYY/MM/file.jpg). That is not cosmetic:
	 * _wp_attached_file stores paths relative to basedir, so preserving layout
	 * means migration needs no database rewrite at all, and
	 * wp_calculate_image_srcset() — which substring-tests the rendered URL and
	 * silently drops srcset entirely on a mismatch — keeps working untouched.
	 */
	public function key_for( string $relative_path ): string {
		return ltrim( $relative_path, '/' );
	}
}
