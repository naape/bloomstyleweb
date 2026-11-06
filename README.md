# Bloom Style custom code

The custom WordPress plugin and child theme for the Bloom Style store. This is
not a full site: WordPress, WooCommerce, other plugins, images and the database
are not stored here.

- `plugins/bloomstyle-store`: store features
- `themes/bloomstyle-astra`: Astra child theme
- `.cpanel.yml`: copies both folders into `public_html/wp-content` when deployed

## Updating the live site

1. Back up the site.
2. Test the change on the local copy.
3. Commit and push.
4. In cPanel, open Git Version Control, then Manage, then Pull or Deploy.
5. Click Update from Remote, then Deploy HEAD Commit.

Deploying adds and overwrites files. It does not delete files removed from this
repository, so remove those on the server by hand.

Never commit `wp-config.php`, database exports, login files or customer images.
