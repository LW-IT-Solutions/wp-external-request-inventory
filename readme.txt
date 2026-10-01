=== External Request Inventory ===
Contributors: lwitsolutions
Tags: privacy, gdpr, external requests, third party, http api
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

List the external hosts your site contacts, on the server and in visitors' browsers, and check each one against your privacy policy page.

== Description ==

A privacy policy should name the services a site uses. Over time, themes, plugins and embeds add hosts that nobody
wrote down: a font service, a map, a video, an update check, an API a plugin calls in the background.

External Request Inventory collects these hosts from two sides and puts them in one list under Tools > External
Requests:

* **Server:** every request that WordPress, a plugin or the theme makes through the WordPress HTTP API, with the plugin or theme that made it, how often, when last, and in which context (cron, admin, front end, AJAX, REST API, WP-CLI). Recording starts on activation and can be paused.
* **Browser:** scripts, stylesheets, fonts, images, audio and video, iframes, embeds, form targets and preconnect hints that point to other hosts in your public pages. The scan loads the home page, the privacy policy and the latest posts and pages, plus any further pages of the site you enter. Pages are loaded without cookies, the way a first-time visitor receives them before any consent. Stylesheets of your own site are read as well, so fonts imported from CSS are found.

Each host is checked against the published privacy policy page from Settings > Privacy: is the host, its domain or
the name of a well-known service (for example "Google Fonts" or "YouTube") mentioned? Hosts that are not named and
hosts that are new since the previous scan come first. The list can be exported as CSV, for example for a record of
processing activities.

= Privacy =

The plugin stores host names, counts, dates, the names of the components that made server requests, and the paths of
the scanned pages. It never stores full URLs, query strings, request bodies or any visitor data. The browser scan runs
in the administrator's browser, loads pages of this site only and never requests an external file. The plugin makes no
external requests and sets no cookies. Uninstalling removes all stored data.

= Limits =

* Resources that scripts add after the page has loaded, or only after consent, are not visible to the scan. Hosts written in inline scripts are reported as "named in inline script".
* Requests that bypass the WordPress HTTP API (for example direct cURL calls) are not recorded.
* The privacy policy check is a text match and a prompt for review, not legal advice.
* Lists keep up to 300 hosts.

== Installation ==

1. Upload the external-request-inventory folder to wp-content/plugins, or install the ZIP, and activate it.
2. Open Tools > External Requests as an administrator and click "Scan pages".
3. Server requests appear as WordPress, plugins and the theme make them. Come back after a day to see cron jobs and update checks.

== Frequently Asked Questions ==

= Does the scan slow down my site? =

No. The browser scan runs only when you click the button. Server recording adds a host to a list in memory and saves
the list once at the end of a request that contacted an external host.

= Why is a host from my privacy policy shown as "not named"? =

The check looks for the host, its domain and known service names as whole words. If your policy names a service
differently, the host is shown as not named. Treat it as a prompt to review.

= Why does a host appear that is not on my pages? =

Server requests are made by WordPress and plugins, for example update checks or API calls. They do not reach visitors'
browsers, but they can still transfer data such as the site address.

= What does uninstalling remove? =

All three options the plugin uses. Nothing else is stored.

== Screenshots ==

1. The host list: a video host that is new since the previous scan and that WordPress also contacted during a cron job, a plugin's API, core update checks, and scripts, fonts and embeds from the public pages, each checked against the privacy policy.
2. Tools > External Requests: browser scan of the public pages and the server-side request log.

== Changelog ==

= 0.1.0 =
* First version: server-side request log, browser scan of public pages, privacy policy check, CSV export.
