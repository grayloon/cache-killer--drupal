<?php

declare(strict_types=1);

namespace Drupal\cloudflare_cache_killer\Hook;

use Drupal\cloudflare_cache_killer\CloudflarePurgeService;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Automatically purges node URLs from Cloudflare when nodes are updated.
 *
 * Only node hooks are implemented, so users, media, terms, paragraphs and
 * config entities never trigger a purge. Insert, delete or publish-transition
 * support can be added as further hook methods that call purgeNode().
 */
final class NodeHooks {

  use StringTranslationTrait;

  /**
   * Canonical URLs captured before a node is saved, keyed by node ID.
   *
   * By the time hook_node_update() runs, the path field has already saved
   * any new alias, so the previous URL has to be recorded during presave.
   *
   * @var array<int|string, string>
   */
  private array $urlsBeforeSave = [];

  public function __construct(
    private readonly CloudflarePurgeService $purger,
    private readonly MessengerInterface $messenger,
    #[Autowire(service: 'logger.channel.cloudflare_cache_killer')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_presave() for node entities.
   */
  #[Hook('node_presave')]
  public function nodePresave(NodeInterface $node): void {
    $original = $node->getOriginal();
    if ($node->isNew() || !$original || !$this->purger->isConfigured()) {
      return;
    }
    try {
      $this->urlsBeforeSave[$node->id()] = $this->canonicalUrl($original);
    }
    catch (\Exception $e) {
      // Losing the previous URL only means an old alias is not purged.
      $this->logger->warning('Could not determine the previous URL of node @nid: @message', [
        '@nid' => $node->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for node entities.
   *
   * Purges regardless of publication state: an unpublished node must stop
   * being served from cache, and a newly published one may have a stale
   * cached response.
   */
  #[Hook('node_update')]
  public function nodeUpdate(NodeInterface $node): void {
    $previous_url = $this->urlsBeforeSave[$node->id()] ?? NULL;
    unset($this->urlsBeforeSave[$node->id()]);

    if (!$this->purger->isConfigured()) {
      return;
    }
    $this->purgeNode($node, array_filter([$previous_url]));
  }

  /**
   * Purges a node's current canonical URL plus any extra URLs.
   *
   * Never throws: this runs inside the entity save transaction, and a
   * Cloudflare failure must not roll back or interrupt the content save.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string[] $extra_urls
   *   Additional absolute URLs, such as the node's URL before an alias change.
   */
  private function purgeNode(NodeInterface $node, array $extra_urls = []): void {
    try {
      $urls = array_values(array_unique([$this->canonicalUrl($node), ...$extra_urls]));
      $this->purger->purgeUrls($urls);
    }
    catch (\Exception $e) {
      $this->logger->error('Automatic Cloudflare cache purge failed for node @nid: @message', [
        '@nid' => $node->id(),
        '@message' => $e->getMessage(),
      ]);
      $this->messenger->addWarning($this->t('The content was saved, but its Cloudflare cache could not be cleared. Visitors may see the previous version until the cache expires. Use the "Purge Cloudflare cache" tab to retry.'));
      return;
    }

    foreach ($urls as $url) {
      $this->logger->info('Automatically purged Cloudflare cache for node @nid: @url', [
        '@nid' => $node->id(),
        '@url' => $url,
      ]);
    }
  }

  /**
   * Returns the absolute, alias-aware canonical URL of a node.
   */
  private function canonicalUrl(NodeInterface $node): string {
    return $node->toUrl('canonical', ['absolute' => TRUE])->toString();
  }

}
