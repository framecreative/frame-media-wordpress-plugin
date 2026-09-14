# Frame Media

The WordPress side of the [Frame Media Kit](https://github.com/framecreative/frame-media-kit):
one Cloudflare Worker serves every image variant for opted-in Frame sites,
originals stay on hosting. This plugin makes WordPress ask the worker for
its images.

Installs as an mu-plugin (`mu-plugins/frame-media/`, loaded by the Bedrock
autoloader). Inert until `FRAME_MEDIA_HOST` is set; the Twig helpers and
the CLI command are always available, so a theme can adopt the macro before
the site opts in.

## Onboarding a site

1. Add the site to the kit's `sites.json` and run `npm run provision -- <site>`
   there. It prints the env line(s).
2. `composer require framecreative/frame-media`.
3. Replace the theme's image macro with `docs/starter-image-macro.twig` (or
   apply its two substitutions to the site's own macro: `resize( width )` →
   `media({ w, aspect })`, the srcset loop → `media_srcset({ width, aspect })`).
   The macro renders through Timber's `resize` while the kit is off.
4. Add the printed env line(s) to the site's environment. Remove them to
   opt out; no database change either way.
5. After a soak, `wp frame-media status` then `wp frame-media clean` to
   remove the generated files still on hosting.

## What it does when active

- Host-swaps every image attachment URL WordPress emits (`wp_get_attachment_url`,
  registered sizes via `image_downsize`, srcsets, inline upload URLs in content),
  appending `v=` so a replaced file is a new key.
- Stops WordPress generating intermediate sizes.
- Pings `/detect` on upload when `FRAME_MEDIA_SECRET` is set (faces sites).
- `wp frame-media status` / `clean [--dry-run] [--limit=<n>]`: generated files
  only — WordPress intermediates and Timber resize output — never the attached
  file or `original_image`.

## Twig

- `{{ src | media({ w: 800, aspect: 0.75, g: 'top' }) }}` → one worker URL.
  `aspect` (height ÷ width) sets `h` and `fit=cover`; without it, width only.
- `{{ src | media_srcset({ width: 2000, aspect: 0.75 }) }}` → ladder srcset.
- `media_aspect( '3-2' )` → `0.667`; anything that is not a `W-H` name → `0`.
- `media_widths( max )`, `media_active()`.

With the kit off these fall back to Timber's `resize`. Cropped placements are
centred on plain sites and face-placed on faces sites; no template mentions
faces.

## Configuration

Read from a defined constant first, then the environment:

| Name | Purpose |
| --- | --- |
| `FRAME_MEDIA_HOST` | The site's worker hostname. Setting it activates the plugin. |
| `FRAME_MEDIA_SECRET` | Faces sites only: bearer for the upload ping. |
