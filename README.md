# External Request Inventory

List the external hosts your WordPress site contacts, on the server and in visitors' browsers, and check each one against your privacy policy page.

- Server: every request through the WordPress HTTP API, with the plugin or theme that made it and the context (cron, admin, front end, AJAX, REST API, WP-CLI)
- Browser: scripts, stylesheets, fonts, images, media, iframes, embeds, form targets and preconnects in your public pages, loaded without cookies
- Privacy policy check by host, domain and well-known service name; hosts new since the previous scan are marked
- CSV export; stores host names only, never full URLs or visitor data; no external requests

Tools > External Requests. Requires WordPress 6.2 and PHP 7.4. Details in [readme.txt](readme.txt).

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
