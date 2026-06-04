=== Remove WP Default Link Header ===
Contributors: Antigravity
Tags: header, cleanup, security, rest-api, shortlink
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Removes default WordPress RESTful API, shortlink, and XML-RPC links and headers.

== Description ==

Remove WP Default Link Header is a lightweight, high-performance plugin that cleans up your WordPress site's HTTP headers and HTML header output. It removes default links and response headers for:

* WordPress REST API links (`wp_head` & HTTP Link headers)
* Shortlinks (`wp_shortlink_wp_head` & HTTP Link headers)
* RSD (Really Simple Discovery) links
* Windows Live Writer manifest links
* X-Pingback headers

It also disables XML-RPC requests to enhance the security posture of your website.

== Installation ==

1. Upload the `remove-wp-default-link-header` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.

== Changelog ==

= 1.0.0 =
* Initial release.
