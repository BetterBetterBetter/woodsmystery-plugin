# GitHub Updater Guide

This plugin uses the Plugin Update Checker library to enable WordPress dashboard updates from GitHub releases.

## How It Works

1. WordPress loads the bundled `plugin-update-checker` library.
2. The plugin checks `https://github.com/BetterBetterBetter/woodsmystery-plugin` for newer GitHub releases.
3. If the latest release version is higher than the installed plugin header version, WordPress shows an update in the Plugins dashboard.
4. The update installs the release asset ZIP when one is attached to the GitHub release.

## Creating a New Release

### Step 1: Update Version Numbers

Update these two places in `woodsmystery-plugin.php`:

1. Plugin header: `Version: 0.1.2`
2. Constant: `define( 'WMP_SITE_PLUGIN_VERSION', '0.1.2' );`

Use semantic versioning: `MAJOR.MINOR.PATCH`.

Also add a matching entry to `CHANGELOG.md`.

### Step 2: Commit and Push

```bash
git add .
git commit -m "Bump version to 0.1.2"
git push origin main
```

### Step 3: Build the Release ZIP

From the parent directory of the plugin folder:

```bash
cd /Users/ryan/Sites/thrice-agency/tomwoods
zip -r woodsmystery-plugin-0.1.2.zip woodsmystery-plugin \
  -x "woodsmystery-plugin/.git/*" \
  -x "woodsmystery-plugin/.DS_Store" \
  -x "woodsmystery-plugin/*.zip"
```

The ZIP should contain a top-level `woodsmystery-plugin/` folder with `woodsmystery-plugin.php` inside it.

### Step 4: Create a GitHub Release

Option A: GitHub web interface

1. Go to `https://github.com/BetterBetterBetter/woodsmystery-plugin/releases/new`.
2. Create a tag named `v0.1.2`.
3. Release title: `Version 0.1.2`.
4. Add release notes.
5. Upload `woodsmystery-plugin-0.1.2.zip` as a release asset.
6. Publish the release.

Option B: GitHub CLI

```bash
gh release create v0.1.2 woodsmystery-plugin-0.1.2.zip \
  --repo BetterBetterBetter/woodsmystery-plugin \
  --title "Version 0.1.2" \
  --notes "Release notes here."
```

## Testing Updates

1. Install an older version of the plugin on a test WordPress site.
2. Publish a newer GitHub release.
3. In WordPress, go to `Dashboard > Updates` or `Plugins`.
4. If needed, clear the update cache:

```bash
wp transient delete --network update_plugins
```

5. Confirm WordPress shows the plugin update and that `Update now` installs the release ZIP.

## Private Repository Note

If the GitHub repository is private, unauthenticated WordPress sites will not be able to check or download updates. Either keep the repository public or add Plugin Update Checker authentication before relying on dashboard updates.

## Release Checklist

- [ ] Plugin header `Version:` updated.
- [ ] `WMP_SITE_PLUGIN_VERSION` updated.
- [ ] `CHANGELOG.md` updated.
- [ ] Changes committed and pushed.
- [ ] Tag created with `v` prefix.
- [ ] GitHub release created.
- [ ] Release ZIP uploaded as an asset.
- [ ] Test site sees the update in the WordPress dashboard.
