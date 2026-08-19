=== HTTP 103 Early Hints & Link Header Generator ===
Contributors: Antigravity
Tags: performance, early-hints, headers, cdn, http103
Requires at least: 6.8
Requires PHP: 8.3
Tested up to: 6.9
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Converts enqueued header styles and scripts into HTTP response Link headers to support CDN HTTP 103 Early Hints.

== Description ==

HTTP 103 Early Hints & Link Header Generator is a lightweight, stateless utility designed to improve page speed metrics (such as First Contentful Paint and Largest Contentful Paint) for sites running behind CDNs like Cloudflare.

The plugin intercepts critical, render-blocking styles and scripts printed in the document `<head>` and emits them as HTTP `Link` response headers. CDNs cache these headers and serve them early to client browsers, accelerating asset discovery via HTTP 103 Early Hints.

=== Key Features ===
* **Zero Output Buffering**: Inspects queues early at `template_redirect` before template load, avoiding rendering memory overhead.
* **Automatic Critical Path Heuristics**: Analyzes style and script queues automatically, choosing assets critical for rendering (excludes deferred/async scripts and print stylesheets).
* **Block Theme (FSE) Optimization**: Forces separate loading of core block styles on demand and disables stylesheet inlining so block styles can be cached and preloaded via Early Hints.
* **Congestion Guard**: Enforces a strict limit of 5 preload hints to prevent network choking.

== Installation ==

1. Upload the `http-103-early-hints` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure FSE settings in the admin dashboard under **Settings > HTTP 103 Early Hints**.

== Changelog ==

= 1.0.0 =
* Initial release with enqueued queue heuristics and HTTP 103 Early Hints Link header generation.
