Blog Duplicator
===============

For multisite only. Introduces a WP-CLI command for duplicating a blog on a network.

Install and activate as a normal plugin. Then in the command line, from your site's root directory, run `wp help`. You should see `duplicate` as a registered command, then you can continue:

`wp duplicate <new-site-slug>` will duplicate your current site

To see which site you're currently in: `wp option get siteurl`

To duplicate a different site on the network, pass the `--url` flag: `wp duplicate <new-site-slug> --url=domain.tld/somesite`

Definitely open to better command names here. :)