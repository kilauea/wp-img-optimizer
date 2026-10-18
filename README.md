# WP Image Optimizer

A native-PHP WordPress plugin that converts your media-library images and new
uploads to **WebP** or **AVIF**, non-destructively. It mirrors the feature set
of the `img2webp` command-line tool — format choice, lossless/quality,
aspect-ratio scaling, metadata stripping, and a size-reduction report — but runs
entirely inside WordPress using **Imagick** (preferred) or **GD**.

## How it works

- **Non-destructive:** the original upload is never modified or deleted. The
  plugin writes a sibling file next to each source, e.g. `photo.jpg` →
  `photo.jpg.webp` (or `.avif`). This naming is unambiguous — two different
  sources can never collide on one derivative.
- **New uploads:** when enabled, images are converted automatically after
  WordPress finishes generating their sub-sizes.
- **Existing media:** a **Bulk Convert** admin page processes the whole library
  in batches over AJAX, with a progress bar and a cumulative size report.
- **Engine detection:** the plugin detects Imagick/GD and WebP/AVIF support at
  runtime, prefers Imagick, and falls back to GD. If a requested format is not
  available, those conversions are skipped and the admin UI explains why.

## Requirements

- WordPress 5.8+ and PHP 7.4+ (tested target: PHP 8.4).
- One of:
  - **PHP Imagick** backed by an ImageMagick built with WebP and/or AVIF
    delegates (recommended), or
  - **GD** with WebP support (`imagewebp`) and, for AVIF, PHP 8.1+ with
    `imageavif`.

### Will Imagick/AVIF work on my server?

That depends on how the server's PHP and ImageMagick were built, not on the
WordPress version. Check from the command line:

```bash
php -m | grep -i imagick                 # is the PHP Imagick extension loaded?
php tools/check-capabilities.php         # bundled check: WebP/AVIF via Imagick or GD
# If you have the ImageMagick CLI:
convert -list format | grep -Ei 'webp|avif'
```

ImageMagick has supported WebP for years and AVIF since ImageMagick 7.0.25+
(with libheif/libaom). PHP 8.4 runs Imagick 3.7+ fine. The plugin's Settings
page also shows a live capabilities panel.

## Installation

1. Copy the `wp-img-optimizer` directory into `wp-content/plugins/`.
2. Activate **WP Image Optimizer** in Plugins.
3. Go to **Image Optimizer → Settings**, review the capabilities panel, and
   choose your options.
4. (Optional) Run **Image Optimizer → Bulk Convert** to process existing media.

## Settings

| Setting | Description | Default |
|---------|-------------|---------|
| Output format | `webp` or `avif` | webp |
| Lossless | Lossless encoding (ignores quality) | off |
| Quality | 0–100 for lossy mode | 82 |
| Max width (px) | Downscale cap, preserves aspect ratio (0 = none) | 0 |
| Max height (px) | Downscale cap, preserves aspect ratio (0 = none) | 0 |
| Strip metadata | Remove EXIF/ICC/XMP from generated images | on |
| Convert on upload | Auto-convert new uploads | on |
| Convert sub-sizes | Also convert registered WP thumbnail sizes | on |
| Overwrite | Re-generate derivatives even if they already exist | off |

Scaling matches `img2webp`: when a max width and/or height is set, the image is
scaled to fit inside that box preserving aspect ratio, and is **never** upscaled.

## Size-reduction report

The Bulk Convert page shows a running report of files converted, total original
size, total new size, and the overall reduction percentage, updated live as each
batch completes.

## Serving the generated images

This plugin **creates** the optimized files; it does not rewrite your site's
`<img>` tags or rewrite requests. To serve WebP/AVIF to supporting browsers you
can either:

- use a theme/`<picture>` setup that references the `*.webp` / `*.avif`
  siblings, or
- add a server rule (Apache/Nginx) that serves the sibling when the browser
  sends an `Accept` header advertising the format.

Generating alongside the original keeps this safe and reversible; wiring up
delivery is intentionally left to your server/theme so the plugin stays
non-destructive and conflict-free.

## Uninstall

Deleting the plugin removes its options and per-attachment bookkeeping. Generated
`*.webp` / `*.avif` files are **kept** by default so live pages don't break. To
also delete every generated file on uninstall, add this to `wp-config.php`
before uninstalling:

```php
define( 'WPIO_DELETE_FILES_ON_UNINSTALL', true );
```

## Project structure

```
wp-img-optimizer/
├── wp-img-optimizer.php          # plugin bootstrap (header, constants, hooks)
├── uninstall.php                 # cleanup on delete
├── includes/
│   ├── class-wpio-capabilities.php  # Imagick/GD + WebP/AVIF detection
│   ├── class-wpio-converter.php     # scale + encode (Imagick/GD)
│   ├── class-wpio-settings.php      # options + sanitization
│   ├── class-wpio-media.php         # attachment conversion + cleanup
│   ├── class-wpio-report.php        # size-reduction totals
│   └── class-wpio-plugin.php        # hooks (on-upload, delete)
├── admin/
│   └── class-wpio-admin.php         # settings + bulk pages + AJAX
├── assets/
│   ├── wpio-bulk.js                 # AJAX batch driver + progress
│   └── wpio-admin.css
└── tools/
    └── check-capabilities.php       # standalone server check
```

## Relationship to img2webp

The conversion logic (format, lossless/quality, aspect-ratio no-upscale scaling,
metadata stripping, size reporting) is a PHP re-implementation of the Python
`img2webp` tool, so the two behave consistently. WordPress runs on PHP, so a
native PHP plugin installs and runs anywhere WordPress does — no Python or shell
access required.

## License

MIT.
