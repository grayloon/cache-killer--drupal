# Cloudflare Cache Killer

Purges individual node URLs from the Cloudflare cache using Cloudflare's
single-file purge API. Only the node's own URL is purged, never the whole zone.

## Configuration

Add the credentials to `settings.php` (not config, so the token is never
exported):

```php
$settings['cloudflare_cache_killer'] = [
  'zone_id' => 'your-zone-id',
  'api_token' => getenv('CLOUDFLARE_API_TOKEN'),
];
```

The API token needs the **Zone → Cache Purge** permission.

URLs must match what Cloudflare caches, including the scheme. Drupal builds
absolute URLs from the incoming request, so behind Cloudflare or another proxy
make sure `reverse_proxy` settings are configured so Drupal sees HTTPS. For
Drush or cron, pass the public URL (`drush --uri=https://example.com`).

## Manual purge

Users with the **Purge Cloudflare cache** permission get a "Purge Cloudflare
cache" tab on each node. Use it for stale content, content that changed
indirectly, troubleshooting, or retrying a failed automatic purge.

## Automatic purge on update

Whenever an existing node is saved, its canonical URL is purged. This happens
whatever the resulting publication state is: a newly unpublished node should
not keep being served from cache, and a newly published one may have a stale
cached response. Field changes are not inspected.

New nodes, deleted nodes and all other entity types (users, media, terms,
paragraphs, config) are not purged automatically.

### Changed path aliases

If an edit changes the node's alias, both the old and new URLs are purged. The
old URL is recorded in `hook_node_presave()`, before the path field saves the
new alias. Older historical aliases are not purged.

### Failures

A Cloudflare failure never prevents the node from saving. The error is logged
to the `cloudflare_cache_killer` channel, and the editor sees a warning that the
content was saved but the cache could not be cleared. Successful purges are
logged at `info` level with the node ID and URL. The API token is never logged.

If credentials are not configured (for example on a local environment),
automatic purging is silently skipped.

### Limitations

- Only the URL of the translation being saved is purged, not other translations.
- Other pages that show the node (listings, the front page, views) are not
  purged.
- The purge request runs synchronously during the save, with a 10-second
  timeout.
