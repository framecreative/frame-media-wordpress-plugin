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
		add_filter( 'site_status_tests', [ $this, 'site_health' ] );
	}

	public function menu() {
		add_management_page( 'Media Kit', 'Media Kit', self::CAP, self::PAGE, [ $this, 'render' ] );
	}

	public function render() {
		$summary = $this->plugin->summary();
		$probe = $summary['active'] ? $this->plugin->probe() : null;
		?>
		<div class="wrap">
			<h1>Media Kit</h1>

			<h2>Status</h2>
			<table class="widefat striped" style="max-width: 720px">
				<tbody>
					<tr><th style="width: 200px">Plugin</th><td><?php echo esc_html( FRAME_MEDIA_VERSION ); ?></td></tr>
					<tr><th>State</th><td><?php echo $summary['active'] ? '<strong style="color:#00733e">Active</strong> — image variants are served from the worker' : '<strong>Inert</strong> — <code>FRAME_MEDIA_HOST</code> is not set; images are served from this host'; ?></td></tr>
					<tr><th>Worker host</th><td><?php echo $summary['host'] ? '<code>' . esc_html( $summary['host'] ) . '</code>' : '—'; ?></td></tr>
					<tr><th>Face detection secret</th><td><?php echo $summary['secret_set'] ? 'Set — uploads are pinged for detection' : 'Not set'; ?></td></tr>
					<?php if ( $probe ) : ?>
					<tr><th>Live check</th><td>
						<?php echo $probe['ok'] ? '<span style="color:#00733e">✓</span> ' : '<span style="color:#b32d2e">✗</span> '; ?>
						<?php echo esc_html( $probe['message'] ); ?>
						<?php if ( $probe['url'] ) : ?><br><a href="<?php echo esc_url( $probe['url'] ); ?>" target="_blank"><code><?php echo esc_html( $probe['url'] ); ?></code></a><?php endif; ?>
					</td></tr>
					<?php endif; ?>
				</tbody>
			</table>
			<p class="description">Configuration comes from the environment (<code>FRAME_MEDIA_HOST</code>, <code>FRAME_MEDIA_SECRET</code>). Remove the host line to opt out; nothing is stored in the database.</p>

			<h2>Removing generated image files</h2>
			<p>Once the kit is active, WordPress intermediate sizes and Timber resize output on this host are no longer used. They are removed once, from the command line, where large libraries can run without a request timeout:</p>
			<pre style="background:#f6f7f7; padding:12px; max-width:720px">wp frame-media status        # count and size, nothing deleted
wp frame-media clean --dry-run
wp frame-media clean</pre>
			<p class="description">Originals and <code>-scaled</code> files are never touched. Sizes regenerate on demand if the kit is turned off.</p>
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
