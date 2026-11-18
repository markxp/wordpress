=== Remove WP Default Link Header ===
Contributors: markxp
Tags: header, cleanup, security, rest-api, shortlink
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Removes default WordPress REST API, shortlink, RSD, Windows Live Writer, and X-Pingback links and HTTP headers, and disables XML-RPC.

== Description ==

Remove WP Default Link Header is a lightweight, zero-configuration plugin that cleans up your WordPress site's HTTP response headers and HTML `<head>` output. It removes default links and response headers for:

* WordPress REST API links (`wp_head` & HTTP `Link` headers)
* Shortlinks (`wp_shortlink_wp_head` & HTTP `Link` headers)
* RSD (Really Simple Discovery) links
* Windows Live Writer manifest links
* `X-Pingback` HTTP response headers

It also disables XML-RPC requests (`xmlrpc_enabled`) to reduce attack surface.

== Installation ==

1. Upload the `remove-wp-default-link-header` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.

== Changelog ==

= 1.0.0 =
* Initial release.
