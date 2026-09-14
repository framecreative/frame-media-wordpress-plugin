<?php
/**
 * Plugin Name: Frame Media
 * Plugin URI: https://github.com/framecreative/frame-media-wordpress-plugin
 * Description: Serves image variants from the Frame Media Kit worker. Inert until FRAME_MEDIA_HOST is set; Twig helpers and WP-CLI are always available.
 * Version: 1.0.0
 * Author: Frame
 * Author URI: https://framecreative.com.au
 * Requires PHP: 8.1
 */

defined( 'ABSPATH' ) || exit;

define( 'FRAME_MEDIA_VERSION', '1.0.0' );
define( 'FRAME_MEDIA_DIR', __DIR__ );

require_once __DIR__ . '/includes/Plugin.php';

add_action( 'plugins_loaded', function () {
	\Frame\Media\Plugin::instance();
}, 5 );
