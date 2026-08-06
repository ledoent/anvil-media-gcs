<?php
/**
 * Wiring appended to wp-config.php by the harness.
 *
 * Mirrors the README's documented setup exactly — if this drifts from the
 * README, the README is wrong.
 *
 * @package Ledoent\AnvilMediaGcs
 */

require_once '/anvil/vendor/autoload.php';
require_once '/anvil/src/StreamWrapper.php';
require_once '/anvil/src/Storage.php';
require_once '/anvil/src/Plugin.php';

define( 'FS_METHOD', 'direct' );

// Stage 1, here in wp-config.php: the wrapper must exist before WordPress
// touches the filesystem.
$anvil_storage = new Ledoent\AnvilMediaGcs\Storage(
	getenv( 'ANVIL_BUCKET' ),
	'test-project',
	getenv( 'STORAGE_EMULATOR_HOST' ) ?: null
);
$anvil_storage->register_stream_wrapper();

// Stage 2 happens in the mu-plugin: add_filter() does not exist yet.
$GLOBALS['anvil_media_gcs'] = new Ledoent\AnvilMediaGcs\Plugin(
	$anvil_storage,
	getenv( 'ANVIL_BASE_URL' )
);
