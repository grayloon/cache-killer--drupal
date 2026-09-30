<?php

declare(strict_types=1);

namespace Drupal\cloudflare_cache_killer\Form;

use Drupal\cloudflare_cache_killer\CloudflarePurgeService;
use Drupal\cloudflare_cache_killer\Exception\CloudflarePurgeException;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Confirms and performs a manual Cloudflare purge of a node's URL.
 */
final class NodePurgeForm extends ConfirmFormBase {

  /**
   * The node being purged.
   */
  private NodeInterface $node;

  public function __construct(
    private readonly CloudflarePurgeService $purger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'cloudflare_cache_killer_node_purge';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $this->node = $node;
    if (!$this->purger->isConfigured()) {
      $this->messenger()->addError($this->t('Cloudflare is not configured. Add a zone ID and API token to settings.php.'));
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Purge the Cloudflare cache for %title?', ['%title' => $this->node->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('Cloudflare will discard its cached copy of @url. Other pages are not affected.', [
      '@url' => $this->canonicalUrl(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Purge');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return $this->node->toUrl();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $url = $this->canonicalUrl();
    try {
      $this->purger->purgeUrls([$url]);
      $this->logger('cloudflare_cache_killer')->info('Manually purged Cloudflare cache for node @nid: @url', [
        '@nid' => $this->node->id(),
        '@url' => $url,
      ]);
      $this->messenger()->addStatus($this->t('Purged @url from the Cloudflare cache.', ['@url' => $url]));
    }
    catch (CloudflarePurgeException $e) {
      $this->logger('cloudflare_cache_killer')->error('Manual Cloudflare cache purge failed for node @nid: @message', [
        '@nid' => $this->node->id(),
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Cloudflare cache purge failed. See the site log for details.'));
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * Returns the absolute, alias-aware canonical URL of the node.
   */
  private function canonicalUrl(): string {
    return $this->node->toUrl('canonical', ['absolute' => TRUE])->toString();
  }

}
