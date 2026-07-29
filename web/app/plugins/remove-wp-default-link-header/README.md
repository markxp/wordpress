# Remove WP Default Link Header

A WordPress plugin designed to clean up your website's HTTP headers and HTML `<head>` by removing default WordPress REST API, shortlink, XML-RPC (RSD/WLW), and Pingback headers and links. 

## Features

- **REST API Header & Link Removal**: Removes `<link rel="https://api.w.org/">` from the page head and the `Link` header from HTTP responses.
- **Shortlink Header & Link Removal**: Cleans up `<link rel="shortlink">` from HTML and the shortlink HTTP header response.
- **XML-RPC & RSD Link Cleanup**: Removes Really Simple Discovery (`rsd_link`) and Windows Live Writer (`wlwmanifest_link`) links.
- **Pingback Header Removal**: Disables XML-RPC entirely and strips the `X-Pingback` HTTP header from server responses.

## Installation
1. Upload the `remove-wp-default-link-header` folder to your WordPress `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.
