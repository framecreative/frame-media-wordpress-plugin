# Changelog

## 1.1.0

- Tools → Media Kit admin page: version, active state, worker host, secret status, and a live check that fetches one image through the worker.
- Site Health test: critical when the worker does not serve a test image.
- `wp frame-media` scan logic reusable (`Plugin::scan()`); clean-up stays on the CLI by design.

## 1.0.0

- Initial release: attachment URL rewriting to the Frame Media Kit worker, `media` / `media_srcset` / `media_aspect` / `media_widths` / `media_active` Twig helpers with Timber fallback, `wp frame-media status|clean`, face-detection upload ping.
