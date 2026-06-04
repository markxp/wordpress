=== HTTP Link Header Generator ===
Contributors: Antigravity
Tags: security, headers, cache, performance, early-hints
Requires at least: 6.0
Tested up to: 6.5
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Converts enqueued header styles and scripts into HTTP response Link headers to support CDN HTTP 103 Early Hints.

== Description ==

HTTP Link Header Generator is a lightweight, stateless utility designed to improve page speed metrics (such as First Contentful Paint and Largest Contentful Paint) for sites running behind CDNs like Cloudflare.

The plugin intercepts critical, render-blocking styles and scripts printed in the document `<head>` and emits them as HTTP `Link` response headers. CDNs cache these headers and serve them early to client browsers, accelerating asset discovery.

=== Key Features ===
* **Zero Output Buffering**: Inspects queues early at `template_redirect` before template load, avoiding rendering memory overhead.
* **Automatic Critical Path Heuristics**: Analyzes style and script queues automatically, choosing assets critical for rendering (excludes deferred/async scripts and print stylesheets).
* **Congestion Guard**: Enforces a strict limit of 5 preload hints to prevent network choking.

== Installation ==

1. Upload the `http-link-header-generator` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.

== Changelog ==

= 1.0.0 =
* Initial release with enqueued queue heuristics and HTTP Link header generation.
