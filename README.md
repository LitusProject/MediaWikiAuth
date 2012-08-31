MediaWikiAuth
=============

Custom MediaWiki module that authenticates against Litus.

## Requierements

* A working [Litus](https://github.com/LitusProject/Litus) webserver with a working API.
* A MediaWiki installation, version 1.13+
* The domain of the wiki has to be a subdomain of the domain of the Litus server, or the same domain (e.g. if the Litus server is litus.cc, wiki.litus.cc or litus.cc/wiki will both work, lituswiki.cc won't)
* Optional: the PHP curl module (on ubuntu: install the php5-curl package)

## Installation

You have to have mediawiki installed and configured.
To use curl instead of the standard PHP http requests, make sure php5-curl (on ubuntu) is installed.

Execute in a shell:
```bash
cd <path to your mediawiki folder>/extensions
git clone https://github.com/LitusProject/MediaWikiAuth.git LitusAuth
```

Edit your LocalSettings.php, add somewhere at the bottom:
```php
require_once($IP . '/extensions/LitusAuth/LitusAuthPlugin.php');

/* The Litus API Server to use for authentication, without trailing slash. */
$wgLitusAPIServer = 'https://api.litus.cc';

/* The API key */
$wgLitusAPIKey = 'abcdefghijklmnopqrstuvwxyz';

/* The Litus server itself, for the login link, without trailing slash! */
$wgLitusServer = 'https://litus.cc';
```

Replace the $wgLitusAPIServer, $wgLitusAPIKey and $wgLitusServer values with the real values for your Litus installation.
