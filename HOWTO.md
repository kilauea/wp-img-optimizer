# HOWTO: Test WP Image Optimizer on macOS

Instructions for testing this plugin on a MacBook Pro (written for
**macOS Ventura 13.7.8**, PHP **8.4** to match the production server).

Testing has two layers:

1. **Static checks** — PHP syntax lint and (optionally) WordPress coding
   standards. Fast, no WordPress required.
2. **Runtime testing** — run the plugin inside a real WordPress install and
   click through the admin pages, converting real images.

---

## 1. Install PHP and the imaging extensions (Homebrew)

[Homebrew](https://brew.sh) is the cleanest route on Ventura. This plugin needs
**Imagick with WebP/AVIF delegates** (preferred) or **GD** with those formats —
which is exactly what the bundled capability checker verifies.

```bash
# Install Homebrew if you don't have it:
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"

# PHP 8.4 (matches the server) + ImageMagick (ships with webp + AVIF/libheif)
brew install php
brew install imagemagick

# The PHP Imagick extension via PECL (more reliable than bundled builds)
pecl install imagick

# Verify
php -v
php -m | grep -iE 'imagick|gd'
```

> If `pecl install imagick` fails to load, add it to your php.ini:
> ```bash
> echo "extension=imagick.so" >> "$(php --ini | awk '/Loaded Configuration/{print $4}')"
> ```

### Confirm WebP/AVIF are actually available

Run the plugin's standalone checker — this is the quickest first test and needs
no WordPress:

```bash
php wp-img-optimizer/tools/check-capabilities.php
```

Expected output lists `WebP output usable: YES` and ideally
`AVIF output usable: YES`. If AVIF is `no`, that is a server-delegate limitation,
not a plugin bug — WebP will still work, and the plugin degrades gracefully.

You can also confirm ImageMagick's own delegates:

```bash
magick -list format | grep -Ei 'webp|avif'   # ImageMagick 7
# or: convert -list format | grep -Ei 'webp|avif'
```

---

## 2. Static checks (no WordPress needed)

### Syntax lint every PHP file

```bash
cd ~/dev/wp_img_optimizer
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Every file should print `No syntax errors detected`. Run this **first** — it
catches parse-level problems before you load the plugin into WordPress.

### WordPress Coding Standards (optional, recommended)

```bash
composer global require squizlabs/php_codesniffer \
  wp-coding-standards/wpcs dealerdirect/phpcodesniffer-composer-installer

~/.composer/vendor/bin/phpcs --standard=WordPress wp-img-optimizer/
```

---

## 3. Run it in a real WordPress

Pick whichever fits your workflow.

### Option A — `wp-env` (Docker, official, recommended)

Zero manual WordPress setup; disposable; mounts the plugin directly. Requires
[Docker Desktop for Mac](https://www.docker.com/products/docker-desktop/) and
Node.js (`brew install node`).

```bash
npm -g install @wordpress/env

cd ~/dev/wp_img_optimizer/wp-img-optimizer   # the plugin dir (has the plugin header)
cat > .wp-env.json <<'JSON'
{ "plugins": [ "." ] }
JSON

wp-env start
```

WordPress is now at **http://localhost:8888** (user `admin`, password
`password`). Activate the plugin under **Plugins**, then test (see §4).

Stop/clean up with `wp-env stop` / `wp-env destroy`.

> Caveat: the Docker image's Imagick/AVIF support is whatever that image ships.
> Great for exercising the admin flow and WebP; to test against your **server's
> exact** Imagick/AVIF build, use Option C.

### Option B — Local by Flywheel / DevKinsta / MAMP (GUI, no terminal)

1. Install [Local](https://localwp.com/), DevKinsta, or MAMP and create a site.
2. Copy or symlink the `wp-img-optimizer` folder into the site's
   `wp-content/plugins/` directory.
3. Activate **WP Image Optimizer** in the WordPress admin.

### Option C — wp-cli + Homebrew PHP (closest to production)

Runs on the **same** PHP/Imagick you installed in §1, so capability behavior
matches your target server most closely.

```bash
brew install wp-cli mysql
brew services start mysql

mkdir ~/wp-test && cd ~/wp-test
wp core download
wp config create --dbname=wptest --dbuser=root --dbpass=
wp db create
wp core install --url=localhost:8080 --title="WPIO Test" \
  --admin_user=admin --admin_password=admin --admin_email=you@example.com

# Symlink the plugin from this repo into the test site
ln -s ~/dev/wp_img_optimizer/wp-img-optimizer wp-content/plugins/wp-img-optimizer
wp plugin activate wp-img-optimizer

# Serve it (PHP built-in server is fine for testing)
php -S localhost:8080
```

Browse **http://localhost:8080/wp-admin**.

---

## 4. What to test in the plugin

Once it is active in WordPress:

1. **Capabilities panel** — go to **Image Optimizer → Settings**. The engine /
   WebP / AVIF status should match `check-capabilities.php` from §1.
2. **New upload** — upload a JPEG and a PNG via **Media → Add New**. In
   `wp-content/uploads/<year>/<month>/`, confirm a `*.webp` (or `*.avif`)
   sibling appears next to each original, and next to the generated sub-size
   files when "Convert sub-sizes" is on.
3. **Bulk Convert** — **Image Optimizer → Bulk Convert → Start conversion**.
   The progress bar should advance, the log should list each attachment, and the
   size-reduction report should show sensible original → new totals and a
   reduction percentage.
4. **Options** — try:
   - **AVIF** format (verify it is skipped gracefully if unsupported);
   - **Lossless** on/off;
   - **Max width/height** (confirm large images shrink, small ones are not
     upscaled);
   - **Overwrite** (re-run and confirm derivatives are regenerated).
5. **Delete cleanup** — delete an attachment and confirm its `*.webp`/`*.avif`
   derivatives are removed from disk.
6. **Metadata** — install `exiftool` (`brew install exiftool`). With
   "Strip metadata" **off**, `exiftool uploads/.../photo.jpg.webp` should show
   EXIF; with it **on**, it should not.

---

## 5. Troubleshooting

| Symptom | Likely cause / fix |
|---------|--------------------|
| `check-capabilities.php` shows Imagick `no` | PHP Imagick extension not loaded — `pecl install imagick`, then add `extension=imagick.so` to php.ini |
| WebP `YES` but AVIF `no` | ImageMagick/GD built without AVIF delegate; use WebP, or install a build with libheif/libaom |
| No derivatives after upload | Check **Convert on upload** is enabled, and that the uploads dir is writable |
| Bulk page button does nothing | Open the browser console; a 403 means the AJAX nonce/capability check failed — ensure you are logged in as an admin |
| Parse/500 error on activation | Run `find . -name '*.php' -print0 \| xargs -0 -n1 php -l` and fix the reported file |

---

## Recommended quick path

```bash
# 1. engine + lint
brew install php imagemagick && pecl install imagick
php wp-img-optimizer/tools/check-capabilities.php
find . -name '*.php' -print0 | xargs -0 -n1 php -l

# 2. runtime
npm -g install @wordpress/env
cd wp-img-optimizer && echo '{ "plugins": [ "." ] }' > .wp-env.json && wp-env start
# -> http://localhost:8888/wp-admin  (admin / password)
```
