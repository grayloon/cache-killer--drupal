<?php

declare(strict_types=1);

namespace Drupal\cloudflare_cache_killer\Exception;

/**
 * Thrown when a Cloudflare cache purge request fails.
 *
 * Messages never contain the API token, so they are safe to log.
 */
final class CloudflarePurgeException extends \RuntimeException {}
