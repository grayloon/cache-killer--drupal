<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_cache_killer\Kernel;

use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;

/**
 * Tests automatic Cloudflare purging when nodes are updated.
 */
#[Group('cloudflare_cache_killer')]
#[RunTestsInSeparateProcesses]
final class AutomaticPurgeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'cloudflare_cache_killer',
    'node',
    'path',
    'path_alias',
    'system',
    'user',
  ];

  /**
   * Queued responses returned to the purge service.
   */
  private MockHandler $responses;

  /**
   * Requests sent to the HTTP client.
   *
   * @var array<int, array{request: \Psr\Http\Message\RequestInterface}>
   */
  private array $history = [];

  /**
   * Log messages with placeholders replaced.
   *
   * @var string[]
   */
  private array $logs = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();

    $this->setSetting('cloudflare_cache_killer', [
      'zone_id' => 'zone123',
      'api_token' => 'secret-token',
    ]);

    $this->responses = new MockHandler();
    $stack = HandlerStack::create($this->responses);
    $stack->push(Middleware::history($this->history));
    $this->container->set('http_client', new Client(['handler' => $stack]));

    $logs = &$this->logs;
    $this->container->get('logger.factory')->addLogger(new class($logs) implements LoggerInterface {
      use RfcLoggerTrait;

      public function __construct(private array &$logs) {}

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $placeholders = array_filter($context, static fn ($key) => str_starts_with((string) $key, '@'), ARRAY_FILTER_USE_KEY);
        $this->logs[] = strtr((string) $message, $placeholders);
      }

    });
  }

  /**
   * Tests that updates purge the canonical URL and inserts do not.
   */
  public function testUpdatePurgesCanonicalUrl(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Widget', 'path' => ['alias' => '/products/widget']]);
    $node->save();
    $this->assertCount(0, $this->history, 'Creating a node does not purge.');

    $this->responses->append($this->success());
    $node->setUnpublished()->save();

    $this->assertSame([['http://localhost/products/widget']], $this->purgedUrlSets());
    $request = $this->history[0]['request'];
    $this->assertSame('https://api.cloudflare.com/client/v4/zones/zone123/purge_cache', (string) $request->getUri());
    $this->assertSame('Bearer secret-token', $request->getHeaderLine('Authorization'));
    $this->assertContains("Automatically purged Cloudflare cache for node {$node->id()}: http://localhost/products/widget", $this->logs);
  }

  /**
   * Tests that changing the alias purges both the old and new URLs.
   */
  public function testAliasChangePurgesOldAndNewUrls(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Widget', 'path' => ['alias' => '/products/widget']]);
    $node->save();

    $this->responses->append($this->success());
    $node->set('path', ['alias' => '/products/new-widget', 'pid' => $node->get('path')->pid]);
    $node->save();

    $this->assertEqualsCanonicalizing(
      ['http://localhost/products/new-widget', 'http://localhost/products/widget'],
      $this->purgedUrlSets()[0],
    );
  }

  /**
   * Tests that a Cloudflare failure does not stop the node from saving.
   */
  public function testFailureDoesNotBlockSave(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Widget']);
    $node->save();

    $this->responses->append(new Response(403, [], json_encode([
      'success' => FALSE,
      'errors' => [['code' => 10000, 'message' => 'Authentication error']],
    ])));
    $node->setTitle('Changed')->save();

    $this->assertSame('Changed', Node::load($node->id())->label());
    $warnings = $this->container->get('messenger')->messagesByType('warning');
    $this->assertCount(1, $warnings);
    $this->assertStringContainsString('content was saved', (string) $warnings[0]);
    $this->assertContains("Automatic Cloudflare cache purge failed for node {$node->id()}: Cloudflare API returned HTTP 403: [10000] Authentication error", $this->logs);
    foreach ($this->logs as $log) {
      $this->assertStringNotContainsString('secret-token', $log);
    }
  }

  /**
   * Tests that non-node entity updates are ignored.
   */
  public function testOtherEntityTypesAreIgnored(): void {
    $user = User::create(['name' => 'editor']);
    $user->save();
    $user->setEmail('editor@example.com')->save();

    $this->assertCount(0, $this->history);
  }

  /**
   * Tests that nothing is sent when credentials are not configured.
   */
  public function testUnconfiguredIsSkipped(): void {
    $this->setSetting('cloudflare_cache_killer', []);
    $node = Node::create(['type' => 'page', 'title' => 'Widget']);
    $node->save();
    $node->setTitle('Changed')->save();

    $this->assertCount(0, $this->history);
    $this->assertEmpty($this->container->get('messenger')->all());
  }

  /**
   * Returns the list of URLs sent in each purge request.
   *
   * @return string[][]
   *   One array of URLs per request.
   */
  private function purgedUrlSets(): array {
    return array_map(
      static fn (array $entry): array => json_decode((string) $entry['request']->getBody(), TRUE)['files'],
      $this->history,
    );
  }

  /**
   * Returns a successful Cloudflare API response.
   */
  private function success(): Response {
    return new Response(200, [], json_encode(['success' => TRUE, 'errors' => [], 'result' => ['id' => 'x']]));
  }

}
