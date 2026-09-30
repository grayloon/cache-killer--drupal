<?php

declare(strict_types=1);

namespace Drupal\cloudflare_cache_killer;

use Drupal\cloudflare_cache_killer\Exception\CloudflarePurgeException;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Purges individual URLs from the Cloudflare cache.
 *
 * This is the only place that talks to the Cloudflare API; both the manual
 * purge form and the automatic node hooks use it. Credentials are read from
 * settings.php so the API token never ends up in exported configuration:
 *
 * @code
 * $settings['cloudflare_cache_killer'] = [
 *   'zone_id' => '...',
 *   'api_token' => getenv('CLOUDFLARE_API_TOKEN'),
 * ];
 * @endcode
 */
final class CloudflarePurgeService {

  private const ENDPOINT = 'https://api.cloudflare.com/client/v4/zones/%s/purge_cache';

  /**
   * The lowest per-request URL limit across Cloudflare plans.
   */
  private const MAX_URLS_PER_REQUEST = 30;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly Settings $settings,
  ) {}

  /**
   * Whether a zone ID and API token have been configured.
   */
  public function isConfigured(): bool {
    $config = $this->settings->get('cloudflare_cache_killer', []);
    return !empty($config['zone_id']) && !empty($config['api_token']);
  }

  /**
   * Purges the given absolute URLs from the Cloudflare cache.
   *
   * @param string[] $urls
   *   Absolute URLs, exactly as Cloudflare caches them.
   *
   * @throws \Drupal\cloudflare_cache_killer\Exception\CloudflarePurgeException
   *   When the module is not configured or Cloudflare rejects the request.
   */
  public function purgeUrls(array $urls): void {
    $urls = array_values(array_unique($urls));
    if (!$urls) {
      return;
    }
    if (!$this->isConfigured()) {
      throw new CloudflarePurgeException('Cloudflare zone ID and API token are not configured in settings.php.');
    }
    $config = $this->settings->get('cloudflare_cache_killer');

    foreach (array_chunk($urls, self::MAX_URLS_PER_REQUEST) as $chunk) {
      try {
        $response = $this->httpClient->request('POST', sprintf(self::ENDPOINT, rawurlencode($config['zone_id'])), [
          'headers' => ['Authorization' => 'Bearer ' . $config['api_token']],
          'json' => ['files' => $chunk],
          'timeout' => 10,
          'connect_timeout' => 5,
          'http_errors' => FALSE,
        ]);
      }
      catch (GuzzleException $e) {
        throw new CloudflarePurgeException('Could not reach the Cloudflare API: ' . $e->getMessage(), 0, $e);
      }

      $body = json_decode((string) $response->getBody(), TRUE);
      if ($response->getStatusCode() !== 200 || empty($body['success'])) {
        $errors = array_map(
          static fn (array $error): string => sprintf('[%s] %s', $error['code'] ?? '?', $error['message'] ?? 'Unknown error'),
          is_array($body['errors'] ?? NULL) ? $body['errors'] : [],
        );
        throw new CloudflarePurgeException(sprintf(
          'Cloudflare API returned HTTP %d: %s',
          $response->getStatusCode(),
          $errors ? implode('; ', $errors) : 'no error details',
        ));
      }
    }
  }

}
