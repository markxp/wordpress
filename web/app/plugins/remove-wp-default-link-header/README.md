# Remove WP Default Link Header

A lightweight, zero-configuration WordPress plugin designed to clean up your website's HTTP response headers and HTML `<head>` by removing default WordPress REST API, shortlink, XML-RPC (RSD/WLW), and Pingback headers and links.

## Features

- **REST API Header & Link Removal**: Removes `<link rel="https://api.w.org/">` from `wp_head` and strips the REST API `Link` header from HTTP responses.
- **Shortlink Header & Link Removal**: Removes `<link rel="shortlink">` from HTML output and strips the shortlink HTTP `Link` header.
- **XML-RPC & RSD Link Cleanup**: Removes Really Simple Discovery (`rsd_link`) and Windows Live Writer (`wlwmanifest_link`) discovery tags.
- **XML-RPC & Pingback Protection**: Disables XML-RPC (`xmlrpc_enabled`) and strips the `X-Pingback` HTTP header from server responses.

## Requirements

- WordPress 7.1 or higher
- PHP 8.4 or higher

## Installation

1. Upload the `remove-wp-default-link-header` folder to your WordPress `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.
