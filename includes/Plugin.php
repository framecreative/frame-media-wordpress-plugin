<?php
/**
 * Frame Media — the WordPress side of the Frame Media Kit.
 *
 * Active only when FRAME_MEDIA_HOST is set (constant or env); otherwise
 * every filter falls back to native WordPress / Timber behaviour.
 *
 * Twig: `{{ src | media({ w: 800, aspect: 0.75 }) }}` for one URL,
 * `{{ src | media_srcset({ width: 2000, aspect: 0.75 }) }}` for a ladder
 * srcset, `media_aspect( '3-2' )` to read a W-H ratio name, plus
 * `media_widths()` and `media_active()`. Inactive → Timber resize. The
 * image macro itself lives in the theme (see the starter's utils.twig) so
 * markup and arguments can vary per site.
 *
 * WP-CLI: `wp frame-media clean [--dry-run]` deletes generated image
 * files (WordPress intermediates and Timber resize output); originals
 * and -scaled files are never touched.
 */

namespace Frame\Media;

class Plugin {

	/** Width rungs the worker snaps to; keep in sync with src/params.js. */
	const LADDER = [ 50, 100, 160, 240, 320, 400, 500, 640, 800, 1000, 1280, 1600, 2000, 2560 ];

	const IMAGE_EXTENSIONS = 'jpe?g|png|gif|webp|avif';

	/** @var string|null Worker base URL without trailing slash, null when inactive. */
	private $base;

	/** @var string|null */
	private $secret;

	/** @var string Uploads base URL on this site. */
	private $upload_url;

	/** @var string Uploads base directory on this site. */
	private $upload_dir;

	/** @var array<int, string> Per-request version-token cache by attachment id. */
	private $versions = [];

	/** @var self|null */
	private static $instance;

	/**
	 * @return self
	 */
	public static function instance() {
		return self::$instance ?? ( self::$instance = new self() );
	}

	public function __construct() {
		$host = self::config( 'FRAME_MEDIA_HOST' );
		$this->secret = self::config( 'FRAME_MEDIA_SECRET' ) ?: null;
		$this->base = $host ? self::normalise_host( $host ) : null;

		$uploads = wp_upload_dir( null, false );
		$this->upload_url = rtrim( $uploads['baseurl'], '/' );
		$this->upload_dir = rtrim( $uploads['basedir'], '/' );

		add_filter( 'timber/twig', [ $this, 'add_to_twig' ] );

		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			\WP_CLI::add_command( 'frame-media', [ $this, 'cli' ] );
		}

		if ( ! $this->base ) {
			return;
		}

		add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 9999 );

		add_filter( 'wp_get_attachment_url', [ $this, 'attachment_url' ], 9999, 2 );
		add_filter( 'image_downsize', [ $this, 'downsize' ], 10, 3 );
		add_filter( 'wp_calculate_image_srcset', [ $this, 'srcset' ], 10, 5 );
		add_filter( 'the_content', [ $this, 'rewrite_content' ], 9999 );
		add_filter( 'widget_text_content', [ $this, 'rewrite_content' ], 9999 );

		if ( $this->secret ) {
			add_filter( 'wp_update_attachment_metadata', [ $this, 'ping_detect' ], 500, 2 );
		}
	}

	/**
	 * Whether the adapter is rewriting URLs.
	 *
	 * @return bool
	 */
	public function is_active() {
		return (bool) $this->base;
	}

	/* ---------------------------------------------------------------- Twig */

	/**
	 * Registers the `media` filter and the `media_widths` / `media_active`
	 * functions.
	 *
	 * @param \Twig\Environment $twig
	 * @return \Twig\Environment
	 */
	public function add_to_twig( $twig ) {
		$twig->addFilter( new \Twig\TwigFilter( 'media', [ $this, 'media' ] ) );
		$twig->addFilter( new \Twig\TwigFilter( 'media_srcset', [ $this, 'media_srcset' ] ) );
		$twig->addFunction( new \Twig\TwigFunction( 'media_aspect', [ $this, 'media_aspect' ] ) );
		$twig->addFunction( new \Twig\TwigFunction( 'media_widths', [ $this, 'media_widths' ] ) );
		$twig->addFunction( new \Twig\TwigFunction( 'media_active', [ $this, 'is_active' ] ) );

		return $twig;
	}

	/**
	 * Worker URL for an upload URL with transform options: w, h, fit, g,
	 * format, q, sat, and `aspect` (height ÷ width) which sets h and
	 * fit=cover from w. Inactive: Timber resize when available, else the
	 * source unchanged.
	 *
	 * @param string $src
	 * @param array  $options
	 * @return string
	 */
	public function media( $src, $options = [] ) {
		if ( ! $src || ! is_string( $src ) ) {
			return $src;
		}

		$options = self::apply_aspect( (array) $options );
		$options = array_filter( $options, fn( $v ) => $v !== null && $v !== '' && $v !== false );

		if ( ! $this->base ) {
			return $this->timber_fallback( $src, $options );
		}

		$path = $this->upload_path( $src );

		if ( $path === null ) {
			return $src;
		}

		$params = [];

		foreach ( [ 'w', 'h', 'fit', 'g', 'format', 'q', 'sat' ] as $key ) {
			if ( isset( $options[ $key ] ) ) {
				$params[ $key ] = (string) $options[ $key ];
			}
		}

		if ( isset( $params['w'] ) && empty( $params['h'] ) ) {
			unset( $params['h'], $params['fit'], $params['g'] );
		}

		if ( ! isset( $params['format'] ) ) {
			$params['format'] = 'auto';
		}

		$version = $this->version_for_path( $path );

		if ( $version ) {
			$params['v'] = $version;
		}

		return $this->worker_url( $path, $params );
	}

	/**
	 * Ladder srcset for an upload URL: one entry per rung up to `width`
	 * (default 2000), each through media() with the same aspect, gravity
	 * and format.
	 *
	 * @param string $src
	 * @param array  $options width, aspect, g, format, q
	 * @return string
	 */
	public function media_srcset( $src, $options = [] ) {
		if ( ! $src || ! is_string( $src ) ) {
			return '';
		}

		$options = (array) $options;
		$width = (int) ( $options['width'] ?? 2000 );
		unset( $options['width'], $options['w'], $options['h'] );
		$entries = [];

		foreach ( $this->media_widths( $width ) as $rung ) {
			$entries[] = $this->media( $src, [ 'w' => $rung ] + $options ) . " {$rung}w";
		}

		return implode( ', ', $entries );
	}

	/**
	 * Crop aspect (height ÷ width) for a W-H ratio name such as "3-2" or
	 * "16-9"; 0 for anything else, meaning "size by width, don't crop".
	 *
	 * @param string|null $variation
	 * @return float
	 */
	public function media_aspect( $variation ) {
		if ( is_string( $variation ) && preg_match( '/^(\d+)-(\d+)$/', $variation, $m ) && (int) $m[1] > 0 ) {
			return (int) $m[2] / (int) $m[1];
		}

		return 0.0;
	}

	/**
	 * Ladder widths up to (and including the first rung at or above) $max.
	 *
	 * @param int      $max
	 * @param int|null $min
	 * @return int[]
	 */
	public function media_widths( $max, $min = 100 ) {
		$max = (int) $max ?: 2000;
		$widths = [];

		foreach ( self::LADDER as $rung ) {
			if ( $rung < $min ) {
				continue;
			}

			$widths[] = $rung;

			if ( $rung >= $max ) {
				break;
			}
		}

		return $widths;
	}

	/**
	 * Turns `aspect` into h and fit=cover from w; drops it otherwise.
	 *
	 * @param array $options
	 * @return array
	 */
	private static function apply_aspect( $options ) {
		$aspect = (float) ( $options['aspect'] ?? 0 );
		unset( $options['aspect'] );

		if ( $aspect > 0 && ! empty( $options['w'] ) ) {
			$options['h'] = (int) round( $options['w'] * $aspect );
			$options['fit'] = $options['fit'] ?? 'cover';
		}

		return $options;
	}

	private function timber_fallback( $src, $options ) {
		if ( ! class_exists( '\\Timber\\ImageHelper' ) || empty( $options['w'] ) ) {
			return $src;
		}

		$h = (int) ( $options['h'] ?? 0 );
		$fit = $options['fit'] ?? 'cover';
		$crop = 'default';

		if ( $h && $fit === 'contain' ) {
			$crop = false;
		} elseif ( isset( $options['g'] ) && in_array( $options['g'], [ 'top', 'bottom', 'left', 'right', 'center' ], true ) ) {
			$crop = $options['g'];
		}

		return \Timber\ImageHelper::resize( $src, (int) $options['w'], $h, $crop );
	}

	/* ------------------------------------------------------ WordPress URLs */

	/**
	 * Host-swaps image attachment URLs.
	 *
	 * @param string $url
	 * @param int    $attachment_id
	 * @return string
	 */
	public function attachment_url( $url, $attachment_id ) {
		if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			return $url;
		}

		$path = $this->upload_path( $url );

		if ( $path === null ) {
			return $url;
		}

		$version = $this->version_for_attachment( $attachment_id );

		return $this->worker_url( $path, $version ? [ 'v' => $version ] : [] );
	}

	/**
	 * Resolves registered sizes to worker URLs instead of generated files.
	 *
	 * @param array|false  $out
	 * @param int          $attachment_id
	 * @param string|int[] $size
	 * @return array|false
	 */
	public function downsize( $out, $attachment_id, $size ) {
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return $out;
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		$full = wp_get_attachment_url( $attachment_id );

		if ( ! $full || empty( $meta['width'] ) || empty( $meta['height'] ) ) {
			return $out;
		}

		$path = $this->upload_path( $full );

		if ( $path === null ) {
			return $out;
		}

		$target = $this->size_target( $size );

		if ( ! $target ) {
			return [ $full, (int) $meta['width'], (int) $meta['height'], false ];
		}

		[ $w, $h, $crop ] = $target;
		$dims = image_resize_dimensions( $meta['width'], $meta['height'], $w, $h, $crop );

		if ( ! $dims ) {
			return [ $full, (int) $meta['width'], (int) $meta['height'], false ];
		}

		$dst_w = (int) $dims[4];
		$dst_h = (int) $dims[5];
		$params = [ 'w' => $dst_w, 'h' => $dst_h, 'fit' => $crop ? 'cover' : 'contain', 'format' => 'auto' ];

		if ( is_array( $crop ) && ( $g = self::crop_gravity( $crop ) ) ) {
			$params['g'] = $g;
		}

		if ( $version = $this->version_for_attachment( $attachment_id ) ) {
			$params['v'] = $version;
		}

		return [ $this->worker_url( $path, $params ), $dst_w, $dst_h, true ];
	}

	/**
	 * Builds srcset candidates on the worker ladder at the rendered aspect.
	 *
	 * @param array  $sources
	 * @param int[]  $size_array
	 * @param string $image_src
	 * @param array  $image_meta
	 * @param int    $attachment_id
	 * @return array
	 */
	public function srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		if ( strpos( $image_src, $this->base . '/' ) !== 0 || empty( $image_meta['width'] ) ) {
			return $sources;
		}

		$parts = wp_parse_url( $image_src );
		$path = $parts['path'] ?? '';
		parse_str( $parts['query'] ?? '', $query );

		[ $render_w, $render_h ] = $size_array;

		if ( ! $render_w ) {
			return $sources;
		}

		$aspect = $render_h ? $render_h / $render_w : 0;
		$cropped = ( $query['fit'] ?? '' ) === 'cover';
		$max = (int) $image_meta['width'];
		$out = [];

		foreach ( $this->media_widths( $max ) as $rung ) {
			if ( $rung > $max ) {
				break;
			}

			$params = [ 'w' => $rung ];

			if ( $cropped && $aspect ) {
				$params['h'] = (int) round( $rung * $aspect );
				$params['fit'] = 'cover';

				if ( isset( $query['g'] ) ) {
					$params['g'] = $query['g'];
				}
			}

			$params['format'] = $query['format'] ?? 'auto';

			if ( isset( $query['v'] ) ) {
				$params['v'] = $query['v'];
			}

			$out[ $rung ] = [
				'url' => $this->worker_url( $path, $params ),
				'descriptor' => 'w',
				'value' => $rung,
			];
		}

		return $out ?: $sources;
	}

	/**
	 * Host-swaps upload image URLs inside HTML content (absolute or
	 * root-relative); non-image files stay on hosting.
	 *
	 * @param string $content
	 * @return string
	 */
	public function rewrite_content( $content ) {
		if ( ! $content || strpos( $content, '/uploads/' ) === false ) {
			return $content;
		}

		$host = preg_quote( wp_parse_url( $this->upload_url, \PHP_URL_HOST ), '#' );
		$path = preg_quote( wp_parse_url( $this->upload_url, \PHP_URL_PATH ), '#' );
		$file = '/[^\s"\'<>()?,]+\.(?:' . self::IMAGE_EXTENSIONS . ')';

		// Absolute URLs on this site's host, then root-relative ones at the
		// start of an attribute value or srcset entry.
		$content = preg_replace( '#(?:https?:)?//' . $host . '(' . $path . $file . ')#i', $this->base . '$1', $content );
		$content = preg_replace( '#(?<=["\'\s(,=])(' . $path . $file . ')#i', $this->base . '$1', $content );

		return $content;
	}

	/* ------------------------------------------------------------ Detect */

	/**
	 * Notifies the worker of an uploaded image so face detection runs
	 * before first view. Fire-and-forget.
	 *
	 * @param array $metadata
	 * @param int   $attachment_id
	 * @return array
	 */
	public function ping_detect( $metadata, $attachment_id ) {
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return $metadata;
		}

		$path = $this->upload_path( wp_get_original_image_url( $attachment_id ) ?: '' );

		if ( $path === null ) {
			return $metadata;
		}

		wp_remote_post( $this->base . '/detect', [
			'timeout' => 2,
			'blocking' => false,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->secret,
				'Content-Type' => 'application/json',
			],
			'body' => wp_json_encode( [ 'paths' => [ $path ] ] ),
		] );

		return $metadata;
	}

	/* ------------------------------------------------------------- WP-CLI */

	/**
	 * `wp frame-media clean [--dry-run] [--limit=<n>]`
	 * `wp frame-media status`
	 *
	 * @param array $args
	 * @param array $assoc
	 */
	public function cli( $args, $assoc ) {
		$command = $args[0] ?? 'status';
		$dry = $command === 'status' || isset( $assoc['dry-run'] );
		$limit = (int) ( $assoc['limit'] ?? 0 );

		if ( ! in_array( $command, [ 'clean', 'status' ], true ) ) {
			\WP_CLI::error( 'Usage: wp frame-media clean [--dry-run] [--limit=<n>] | status' );
		}

		$ids = get_posts( [
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => $limit ?: -1,
			'fields' => 'ids',
			'orderby' => 'ID',
			'order' => 'ASC',
		] );

		$files = 0;
		$bytes = 0;
		$attachments = 0;

		foreach ( $ids as $id ) {
			$generated = $this->generated_files( $id );

			if ( ! $generated ) {
				continue;
			}

			$attachments++;

			foreach ( $generated as $file ) {
				$files++;
				$bytes += filesize( $file ) ?: 0;

				if ( ! $dry ) {
					wp_delete_file( $file );
				}
			}

			if ( ! $dry ) {
				$meta = wp_get_attachment_metadata( $id );
				$meta['sizes'] = [];
				wp_update_attachment_metadata( $id, $meta );
			}
		}

		$mb = round( $bytes / 1048576, 1 );
		$verb = $dry ? 'Would delete' : 'Deleted';

		\WP_CLI::success( "$verb $files generated files ($mb MB) across $attachments of " . count( $ids ) . ' image attachments.' );
	}

	/**
	 * Generated files for an attachment: WordPress intermediates from
	 * metadata plus Timber/WordPress-pattern siblings on disk. Never the
	 * attached file or the original image.
	 *
	 * @param int $attachment_id
	 * @return string[]
	 */
	private function generated_files( $attachment_id ) {
		$file = get_attached_file( $attachment_id );

		if ( ! $file ) {
			return [];
		}

		$dir = dirname( $file );
		$meta = wp_get_attachment_metadata( $attachment_id );
		$keep = [ basename( $file ) ];

		if ( ! empty( $meta['original_image'] ) ) {
			$keep[] = $meta['original_image'];
		}

		$found = [];

		foreach ( (array) ( $meta['sizes'] ?? [] ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$found[ $size['file'] ] = true;
			}
		}

		$base = basename( ! empty( $meta['original_image'] ) ? $meta['original_image'] : $file );
		$stem = preg_quote( pathinfo( $base, \PATHINFO_FILENAME ), '#' );
		$stem_scaled = preg_quote( pathinfo( basename( $file ), \PATHINFO_FILENAME ), '#' );
		$pattern = '#^(?:' . $stem . '|' . $stem_scaled . ')-\d+x\d+(?:-c-[a-z-]+)?\.(?:' . self::IMAGE_EXTENSIONS . ')$#i';

		foreach ( glob( $dir . '/' . str_replace( [ '[', ']' ], [ '\[', '\]' ], pathinfo( $base, \PATHINFO_FILENAME ) ) . '-*' ) ?: [] as $sibling ) {
			if ( preg_match( $pattern, basename( $sibling ) ) ) {
				$found[ basename( $sibling ) ] = true;
			}
		}

		$files = [];

		foreach ( array_keys( $found ) as $name ) {
			if ( in_array( $name, $keep, true ) ) {
				continue;
			}

			$path = $dir . '/' . $name;

			if ( is_file( $path ) ) {
				$files[] = $path;
			}
		}

		return $files;
	}

	/* ------------------------------------------------------------ Helpers */

	private function worker_url( $path, $params = [] ) {
		$url = $this->base . $path;

		return $params ? $url . '?' . http_build_query( $params, '', '&', \PHP_QUERY_RFC3986 ) : $url;
	}

	/**
	 * Site-relative upload path ("/content/uploads/2024/04/photo.jpg") for
	 * an upload URL on this site, or null for anything else.
	 *
	 * @param string $url
	 * @return string|null
	 */
	private function upload_path( $url ) {
		$url = strtok( (string) $url, '?#' );
		$upload_path = wp_parse_url( $this->upload_url, \PHP_URL_PATH );

		if ( strpos( $url, $this->upload_url . '/' ) === 0 ) {
			return substr( $url, strlen( $this->upload_url ) - strlen( $upload_path ) );
		}

		// Already rewritten (wp_get_attachment_url is filtered too).
		if ( $this->base && strpos( $url, $this->base . $upload_path . '/' ) === 0 ) {
			return substr( $url, strlen( $this->base ) );
		}

		if ( strpos( $url, $upload_path . '/' ) === 0 ) {
			return $url;
		}

		return null;
	}

	private function version_for_attachment( $attachment_id ) {
		if ( isset( $this->versions[ $attachment_id ] ) ) {
			return $this->versions[ $attachment_id ];
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		$version = ! empty( $meta['filesize'] ) ? (string) $meta['filesize'] : '';

		if ( ! $version ) {
			$file = get_attached_file( $attachment_id );
			$version = $file && is_file( $file ) ? (string) filemtime( $file ) : '';
		}

		return $this->versions[ $attachment_id ] = $version;
	}

	private function version_for_path( $path ) {
		$upload_path = wp_parse_url( $this->upload_url, \PHP_URL_PATH );
		$file = $this->upload_dir . substr( $path, strlen( $upload_path ) );

		return is_file( $file ) ? (string) filemtime( $file ) : '';
	}

	/**
	 * [w, h, crop] for a registered size name or [w, h] array; null for full.
	 *
	 * @param string|int[] $size
	 * @return array|null
	 */
	private function size_target( $size ) {
		if ( is_array( $size ) ) {
			return [ (int) $size[0], (int) $size[1], false ];
		}

		if ( $size === 'full' ) {
			return null;
		}

		$sizes = wp_get_registered_image_subsizes();

		if ( empty( $sizes[ $size ] ) ) {
			return null;
		}

		return [ (int) $sizes[ $size ]['width'], (int) $sizes[ $size ]['height'], $sizes[ $size ]['crop'] ];
	}

	/**
	 * Maps a WordPress crop position [x, y] to worker gravity.
	 *
	 * @param array $crop
	 * @return string|null
	 */
	private static function crop_gravity( $crop ) {
		$x = [ 'left' => 0, 'center' => 0.5, 'right' => 1 ][ $crop[0] ?? 'center' ] ?? 0.5;
		$y = [ 'top' => 0, 'center' => 0.5, 'bottom' => 1 ][ $crop[1] ?? 'center' ] ?? 0.5;

		if ( $x === 0.5 && $y === 0.5 ) {
			return null;
		}

		if ( $x === 0.5 && $y === 0 ) {
			return 'top';
		}

		return "{$x}x{$y}";
	}

	private static function normalise_host( $host ) {
		$host = rtrim( trim( $host ), '/' );

		if ( preg_match( '#^https?://#i', $host ) ) {
			return $host;
		}

		$scheme = preg_match( '#^(localhost|127\.0\.0\.1)(:|$)#', $host ) ? 'http' : 'https';

		return "$scheme://$host";
	}

	/**
	 * Setting by name: a defined constant first, then the environment.
	 *
	 * @param string     $name
	 * @param mixed|null $default
	 * @return mixed
	 */
	private static function config( $name, $default = null ) {
		if ( defined( $name ) ) {
			return constant( $name );
		}

		$value = getenv( $name );

		return $value === false || $value === '' ? $default : $value;
	}

}
