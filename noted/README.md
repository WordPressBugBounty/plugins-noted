# Noted!

![Noted! banner](https://ps.w.org/noted/assets/banner-1544x500.png)

A simple, lightweight note-taking system inside the WordPress admin. Keep general, page-level, and block-level notes all in one place.

[Watch the intro video on YouTube](https://youtu.be/w8L9smQBA6k)

## About

Noted! is a lightweight note-taking plugin for WordPress. Capture project notes, attach reminders to specific posts and pages, and leave notes on individual blocks in the editor — all without leaving the admin.

It is built to stay out of the way: there when you need it, gone when you don't.

## Features

- Quick general notes from the admin bar.
- Page-level notes attached to any post or page.
- Block-level notes that travel with the block when copied or pasted.
- Pin important notes and see them on your dashboard.

## Installation

### From the WordPress plugin directory

1. In your WordPress admin, go to **Plugins → Add New**.
2. Search for **Noted!**.
3. Click **Install Now**, then **Activate**.

### Manual install

1. Download or clone this repository into `wp-content/plugins/noted`.
2. Activate **Noted!** under **Plugins** in the WordPress admin.

Once activated, open notes from the **Noted!** button in the admin bar.

## Usage

- **General notes** — click the Noted! button in the admin bar from any screen.
- **Page-level notes** — open a post or page and use the Noted! panel in the block editor sidebar (or the meta box in the classic editor).
- **Block-level notes** — select a block and add a note from the block toolbar. The note travels with the block when copied or pasted.
- **Pinning** — pin any note to keep it at the top of the list and show it in the Pinned Notes dashboard widget.
- **Settings** — visit **Tools → Noted!** to toggle features, control who can see and manage notes, and export or import your settings.

## FAQ

**Who can see notes?**
By default, only administrators. You can change this under **Tools → Noted!** to allow editors, authors, or contributors to view or manage notes.

**How do I attach a note to a specific page?**
Open the post or page in the editor. In the block editor, use the Noted! panel in the sidebar. In the classic editor, use the Noted! meta box.

**How do I leave a note on a single block?**
Select a block in the block editor and use the Noted! option in the block toolbar or sidebar.

**Does uninstalling the plugin delete my notes?**
Only if you opt in. There is a "delete all data on uninstall" toggle on the settings page, off by default.

## Reporting bugs and requesting features

Please use the [GitHub issue tracker](../../issues) to report bugs or request features. When filing a bug, include:

- WordPress version
- PHP version
- Plugin version
- Steps to reproduce
- What you expected vs. what happened

## Changelog

See [changelog.txt](changelog.txt) for the full release history.

### 2.0.1

- Fix block notes on server-rendered (dynamic) blocks.
- Fix panel width on narrow viewports.
- Fix settings wireframe assets on Windows paths.

### 2.0

- Page-level notes via the block editor sidebar or classic editor.
- Block-level notes that travel with the block on copy/paste.
- Pin notes and view them in a Pinned Notes dashboard widget.
- Settings page under **Tools → Noted!** with role-based access controls.
- Markdown reference tab.
- Import and export settings as JSON.
- Optional "delete all data on uninstall" toggle.
- Translation-ready.
- Now respects your WordPress admin color scheme.
- Improved compatibility with plugins that dequeue admin scripts.
- Cleaner note panel and tidier date format.
- Empty notes are hidden from the list automatically.
- Stronger permission checks on per-post notes.

### 1.0

- Initial release.

## Credits

- Author: [Kyle Van Deusen](https://profiles.wordpress.org/skvandeusen/)
- Contributor: [Taylor Drayson](https://profiles.wordpress.org/tdrayson/)

## License

GPLv2 or later. See [LICENSE](LICENSE).
