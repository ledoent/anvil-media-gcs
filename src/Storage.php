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

	/**
	 * Lazily constructed client.
	 *
	 * @var StorageClient|null
	 */
	private ?StorageClient $client = null;

	/**
	 * Lazily constructed bucket handle.
	 *
	 * @var Bucket|null
	 */
	private ?Bucket $bucket = null;

	/**
	 * Whether the stream wrapper has already been installed.
	 *
	 * @var bool
	 */
	private bool $wrapper_registered = false;

	/**
	 * Construct the storage handle.
	 *
	 * @param string      $bucket_name  Bucket objects are stored in.
	 * @param string|null $project_id   Project ID, where it cannot be inferred.
	 * @param string|null $api_endpoint Override the storage endpoint. Intended
	 *                                  for pointing at an emulator in tests;
	 *                                  leave null in production. The SDK does
	 *                                  not honour STORAGE_EMULATOR_HOST, so an
	 *                                  explicit override is the only way in.
	 */
	public function __construct(
		private readonly string $bucket_name,
		private readonly ?string $project_id = null,
		private readonly ?string $api_endpoint = null
	) {}

	/**
	 * The storage client.
	 *
	 * No credentials are passed: the SDK resolves Application Default
	 * Credentials, which on GKE means Workload Identity.
	 *
	 * @return StorageClient
	 */
	public function client(): StorageClient {
		if ( null === $this->client ) {
			$config = array();
			if ( null !== $this->project_id ) {
				$config['projectId'] = $this->project_id;
			}
			if ( null !== $this->api_endpoint ) {
				$config['apiEndpoint'] = $this->api_endpoint;
				// An emulator has no credentials to resolve; asking the SDK to
				// find ADC would fail before a request is ever made.
				$config['credentials'] = array( 'type' => 'anonymous' );
			}
			$this->client = new StorageClient( $config );
		}
		return $this->client;
	}

	/**
	 * The bucket handle.
	 *
	 * @return Bucket
	 */
	public function bucket(): Bucket {
		if ( null === $this->bucket ) {
			$this->bucket = $this->client()->bucket( $this->bucket_name );
		}
		return $this->bucket;
	}

	/**
	 * Name of the configured bucket.
	 *
	 * @return string
	 */
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

		// Register the SDK's wrapper FIRST, even though we immediately replace
		// it. This looks redundant and is not: StreamWrapper::register() also
		// populates a *private* static client registry keyed by protocol, and
		// the inherited parent methods (stream_open, stream_read, …) read it
		// via self::$clients. Skip this call and every read/write fails.
		// The class itself cannot be substituted here — StreamWrapper::register()
		// hardcodes `StreamWrapper::class` with no late static binding — so the
		// only way to install a subclass is to unregister and re-register.
		$this->client()->registerStreamWrapper();

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
	 *
	 * @param string $relative_path Path relative to the uploads basedir.
	 * @return string Object key.
	 */
	public function key_for( string $relative_path ): string {
		return ltrim( $relative_path, '/' );
	}
}
