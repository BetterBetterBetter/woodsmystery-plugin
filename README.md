# Woods Mystery Plugin

Dedicated WordPress plugin for Woods Mystery.

This plugin is intentionally site-specific. It should contain behavior that belongs to this website and should not be added to the child theme or shared plugins.

## Dependency

Requires WooCommerce to be installed and active.

## Structure

- `woodsmystery-plugin.php` - Minimal plugin entrypoint, constants, file loading, updater setup, and activation hooks.
- `includes/class-plugin.php` - Main loader, admin menu, dependency checks, and feature registration.
- `includes/class-dependencies.php` - External dependency checks.
- `includes/contracts/interface-feature.php` - Contract for isolated features.
- `includes/class-admin-settings.php` - Admin settings/status page.
- `includes/features/mystery-mailchimp/class-mystery-mailchimp.php` - Mystery Party Mailchimp sync and QC admin page.
- `assets/admin/` - Shared admin page assets.
- `plugin-update-checker/` - Bundled GitHub update checker library used for WordPress dashboard updates.
- `mystery-party-mailchimp-qc.md` - Client/admin QC SOP for Mystery Party Mailchimp automations.
- `CHANGELOG.md` - Version history and release notes.
- `UPDATER_GUIDE.md` - Release workflow for dashboard updates.

## Feature Pattern

Each client-specific feature should live in its own folder under `includes/features/{feature-name}/` and implement `WMP_Site_Feature`.

Feature assets should live in `assets/features/{feature-name}/`. Register the feature in `WoodsMysteryPlugin::register_features()`.

## Mystery Mailchimp

The Mystery Mailchimp feature maps WooCommerce party products to Mailchimp audiences, validates Couple attendee fields, syncs Single/Couple attendee emails to the mapped audience when orders become processing or completed, and provides an order action to resync a failed or corrected order.

Admins select the Mailchimp audience from the `Mystery Mailchimp` tab in the WooCommerce product data panel. Variable product variations inherit the parent product audience mapping.

Admins can manage and test the feature in WordPress under `Woods Mystery > Mystery Mailchimp`, which includes tabs for audience status and the QC SOP. A compatibility submenu is also available under `WooCommerce > Mystery Mailchimp`.

## Development

The source plugin lives in:

`/Users/ryan/Sites/thrice-agency/tomwoods/woodsmystery-plugin`

Symlink that directory into a WordPress site's `wp-content/plugins` directory, then activate "Woods Mystery Plugin" in WordPress.

## Releases

Dashboard updates are powered by GitHub releases through the bundled Plugin Update Checker library. See `UPDATER_GUIDE.md` before publishing a new version.
