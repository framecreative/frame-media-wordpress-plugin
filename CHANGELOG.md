# Changelog

## 1.3.2

- `clean` / `status`: every attachment's own file and original image are protected from every other attachment's sibling match (an uploaded file named like a generated size is never removed), and each file is counted once, so dry-run and real-run figures agree.

## 1.3.1

- Admin: the image attachment count no longer carries the "no file on disk" figure, which was misleading on offloaded sites.

## 1.3.0

- Offloaded media: URLs on WP Offload Media's delivery domain (CloudFront or the bucket) are recognised as this site's uploads, so sites serving from S3 work without configuration; the worker's origin is then the delivery domain and `uploadPrefix` its object prefix. `FRAME_MEDIA_UPLOAD_BASES` and the `frame_media/upload_bases` filter cover other CDNs.
- URL filters run at late priority so they take over after offload plugins have rewritten URLs.

## 1.2.0

- Admin page: library figures (image attachments, originals on disk, generated files still on disk, attachments still carrying size metadata), computed on demand and cached for a day; last clean recorded by the CLI; templates still calling Timber resize filters listed in the status table; simplified status rows.
- Attachment details: read-only Media Kit row with the worker URL, a sample crop link, the version token and whether generated sizes remain.

## 1.1.0

- Tools → Media Kit admin page: version, active state, worker host, secret status, and a live check that fetches one image through the worker.
- Site Health test: critical when the worker does not serve a test image.
- `wp frame-media` scan logic reusable (`Plugin::scan()`); clean-up stays on the CLI by design.

## 1.0.0

- Initial release: attachment URL rewriting to the Frame Media Kit worker, `media` / `media_srcset` / `media_aspect` / `media_widths` / `media_active` Twig helpers with Timber fallback, `wp frame-media status|clean`, face-detection upload ping.
