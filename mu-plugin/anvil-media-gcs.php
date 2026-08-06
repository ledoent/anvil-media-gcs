<?php
/**
 * Plugin Name: Anvil Media GCS
 * Description: Boots the GCS media offload filters. The stream wrapper itself is
 *              registered earlier, from wp-config.php.
 * Version:     0.1.0
 * License:     GPL-2.0-or-later
 *
 * Copy or symlink this into wp-content/mu-plugins/.
 *
 * Two-stage setup is not an accident. The stream wrapper has to be installed
 * before WordPress touches the filesystem, which means wp-config.php — but at
 * that point wp-includes/plugin.php has not loaded and add_filter() does not
 * exist. So wp-config.php builds the objects, and this file adds the filters.
 *
 * @package Ledoent\AnvilMediaGcs
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( isset( $GLOBALS['anvil_media_gcs'] ) && $GLOBALS['anvil_media_gcs'] instanceof Ledoent\AnvilMediaGcs\Plugin ) {
	$GLOBALS['anvil_media_gcs']->boot();
}
