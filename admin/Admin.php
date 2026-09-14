<?php
/**
 * Admin surface: Tools → Media Kit (status and a live check) and a Site
 * Health test. Reports only — configuration stays in the environment and
 * clean-up stays on the command line.
 */

namespace Frame\Media;

class Admin {

	const PAGE = 'frame-media';
	const CAP = 'manage_options';

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_post_frame_media_refresh', [ $this, 'handle_refresh' ] );
		add_filter( 'site_status_tests', [ $this, 'site_health' ] );
	}

	public function menu() {
		add_management_page( 'Media Kit', 'Media Kit', self::CAP, self::PAGE, [ $this, 'render' ] );
	}

	/**
	 * Recomputes the cached library figures (read-only scan).
	 */
	public function handle_refresh() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}

		check_admin_referer( 'frame_media_refresh' );
		$this->plugin->library_stats( true );
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE ) );
		exit;
	}

	private static function mb( $bytes ) {
		return $bytes >= 1073741824 ? round( $bytes / 1073741824, 2 ) . ' GB' : round( $bytes / 1048576, 1 ) . ' MB';
	}

	public function render() {
		$summary = $this->plugin->summary();
		$probe = $summary['active'] ? $this->plugin->probe() : null;
		$stats = $this->plugin->library_stats();
		$last_clean = get_option( 'frame_media_last_clean' );
		$audit = $this->plugin->resize_audit();
		?>
		<div class="wrap">
			<h1>Media Kit</h1>

			<h2>Status</h2>
			<table class="widefat striped" style="max-width: 720px">
				<tbody>
					<tr><th style="width: 200px">Plugin</th><td><?php echo esc_html( FRAME_MEDIA_VERSION ); ?></td></tr>
					<tr><th>State</th><td><strong style="color:<?php echo $summary['active'] ? '#00733e' : '#b32d2e'; ?>"><?php echo $summary['active'] ? 'Active' : 'Inactive'; ?></strong></td></tr>
					<tr><th>Worker host</th><td><?php echo $summary['host'] ? '<code>' . esc_html( $summary['host'] ) . '</code>' : '—'; ?></td></tr>
					<tr><th>Face detection secret</th><td><strong style="color:<?php echo $summary['secret_set'] ? '#00733e' : '#b32d2e'; ?>"><?php echo $summary['secret_set'] ? 'Set' : 'Unset'; ?></strong></td></tr>
					<tr><th>Templates using Timber resize</th><td>
						<?php if ( $audit ) : ?>
							<strong style="color:#b32d2e"><?php echo esc_html( implode( ', ', array_map( fn( $f, $n ) => "$f ($n)", array_keys( $audit ), $audit ) ) ); ?></strong>
						<?php else : ?>
							<strong style="color:#00733e">None</strong>
						<?php endif; ?>
					</td></tr>
					<?php if ( $probe ) : ?>
					<tr><th>Live check</th><td>
						<?php echo $probe['ok'] ? '<span style="color:#00733e">✓</span> ' : '<span style="color:#b32d2e">✗</span> '; ?>
						<?php echo esc_html( $probe['message'] ); ?>
						<?php if ( $probe['url'] ) : ?><br><a href="<?php echo esc_url( $probe['url'] ); ?>" target="_blank"><code><?php echo esc_html( $probe['url'] ); ?></code></a><?php endif; ?>
					</td></tr>
					<?php endif; ?>
				</tbody>
			</table>
			<p class="description">Configuration comes from the environment (<code>FRAME_MEDIA_HOST</code>, <code>FRAME_MEDIA_SECRET</code>).</p>

			<h2>Library</h2>
			<?php if ( $stats ) : ?>
			<table class="widefat striped" style="max-width: 720px">
				<tbody>
					<tr><th style="width: 200px">Image attachments</th><td><?php echo number_format_i18n( $stats['images'] ); ?></td></tr>
					<tr><th>Originals on disk</th><td><?php echo esc_html( self::mb( $stats['attached_bytes'] ) ); ?></td></tr>
					<tr><th>Generated files on disk</th><td>
						<?php echo number_format_i18n( $stats['generated_files'] ); ?> files, <?php echo esc_html( self::mb( $stats['generated_bytes'] ) ); ?>
						across <?php echo number_format_i18n( $stats['with_sizes'] ); ?> attachments still carrying size metadata
						<?php if ( $stats['generated_files'] && $summary['active'] ) : ?><br><span style="color:#996800">A clean is due — see below.</span><?php elseif ( ! $stats['generated_files'] ) : ?><br><span style="color:#00733e">Nothing to clean.</span><?php endif; ?>
					</td></tr>
					<tr><th>Last clean</th><td><?php echo $last_clean ? esc_html( sprintf( '%s — removed %s files (%s) across %s attachments', wp_date( 'j M Y H:i', $last_clean['time'] ), number_format_i18n( $last_clean['files'] ), self::mb( $last_clean['bytes'] ), number_format_i18n( $last_clean['attachments'] ) ) ) : 'Never on this environment'; ?></td></tr>
				</tbody>
			</table>
			<p class="description">Figures computed <?php echo esc_html( human_time_diff( $stats['time'] ) ); ?> ago and cached for a day.</p>
			<?php else : ?>
			<p>Library figures have not been computed on this environment yet.</p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'frame_media_refresh' ); ?>
				<input type="hidden" name="action" value="frame_media_refresh">
				<?php submit_button( $stats ? 'Refresh figures' : 'Compute figures', 'secondary', 'submit', false ); ?>
				<span class="description" style="margin-left:8px">Read-only scan of every image attachment; large libraries take a while.</span>
			</form>

			<h2>Removing generated image files</h2>
			<p>Once the kit is active, WordPress intermediate sizes and Timber resize output on this host are no longer used and can be removed:</p>
			<pre style="background:#f6f7f7; padding:12px; max-width:720px">wp frame-media status        # count and size, nothing deleted
wp frame-media clean --dry-run
wp frame-media clean</pre>
		</div>
		<?php
	}

	/**
	 * Site Health: one image fetched through the worker.
	 *
	 * @param array $tests
	 * @return array
	 */
	public function site_health( $tests ) {
		$tests['direct']['frame_media'] = [
			'label' => 'Frame Media Kit',
			'test' => function () {
				$summary = $this->plugin->summary();
				$base = [ 'label' => 'Frame Media Kit', 'badge' => [ 'label' => 'Media', 'color' => 'blue' ], 'test' => 'frame_media', 'actions' => '' ];

				if ( ! $summary['active'] ) {
					return $base + [ 'status' => 'good', 'description' => '<p>Not active on this environment (no <code>FRAME_MEDIA_HOST</code>); images are served from this host.</p>' ];
				}

				$probe = $this->plugin->probe();

				return $base + [
					'status' => $probe['ok'] ? 'good' : 'critical',
					'description' => '<p>' . esc_html( $probe['ok'] ? "Images are being served by the worker at {$summary['host']} ({$probe['served_by']})." : "The worker at {$summary['host']} did not serve a test image: {$probe['message']} Visitors may see broken images." ) . '</p>',
				];
			},
		];

		return $tests;
	}

}
