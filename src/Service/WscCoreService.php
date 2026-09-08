<?php

namespace Drupal\wsc_analytics\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use Exception;

/**
 * Core business logic for WSC Analytics in Drupal:
 * Queue management, AES-256 encryption, HMAC signing, Gateway sync, and report aggregation.
 */
class WscCoreService {

  public const GATEWAY_API_DEFAULT = 'https://api.worldseocouncil.com';

  /** @var \Drupal\wsc_analytics\Service\WscDatabaseManager */
  protected $dbManager;

  /** @var \Drupal\wsc_analytics\Service\WscBotTracker */
  protected $botTracker;

  /** @var \Drupal\Core\Config\ConfigFactoryInterface */
  protected $configFactory;

  /** @var \GuzzleHttp\ClientInterface */
  protected $httpClient;

  /** @var \Drupal\Core\Logger\LoggerChannelInterface */
  protected $logger;

  /** @var \Drupal\Core\State\StateInterface */
  protected $state;

  public function __construct(
    WscDatabaseManager $db_manager,
    WscBotTracker $bot_tracker,
    ConfigFactoryInterface $config_factory,
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory,
    StateInterface $state
  ) {
    $this->dbManager = $db_manager;
    $this->botTracker = $bot_tracker;
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('wsc_analytics');
    $this->state = $state;

    $this->ensureProjectCredentials();
  }

  /**
   * Get or initialize local project credentials (Project ID + AES-256 Encryption Key).
   */
  public function getProjectCredentials(): array {
    $project_id = $this->state->get('wsc_analytics.project_id');
    $local_key  = $this->state->get('wsc_analytics.local_key');

    if (empty($project_id) || empty($local_key)) {
      $project_id = 'wsc_drp_' . bin2hex(random_bytes(8));
      $local_key  = bin2hex(random_bytes(32)); // 256-bit key

      $this->state->set('wsc_analytics.project_id', $project_id);
      $this->state->set('wsc_analytics.local_key', $local_key);

      $this->registerWithGateway($project_id, $local_key);
    }

    return [
      'project_id' => $project_id,
      'local_key'  => $local_key,
    ];
  }

  protected function ensureProjectCredentials(): void {
    $this->getProjectCredentials();
  }

  /**
   * Register local project key with WSC Gateway.
   */
  public function registerWithGateway(string $project_id, string $local_key): bool {
    $gateway_url = $this->getGatewayUrl();
    try {
      $response = $this->httpClient->request('POST', rtrim($gateway_url, '/') . '/v1/register', [
        'json' => [
          'project_id' => $project_id,
          'local_key'  => $local_key,
          'cms'        => 'drupal',
          'version'    => '1.5.0',
        ],
        'timeout' => 8.0,
      ]);

      if ($response->getStatusCode() === 200 || $response->getStatusCode() === 201) {
        $this->logger->info('Successfully registered with WSC Gateway: @id', ['@id' => $project_id]);
        return true;
      }
    }
    catch (Exception $e) {
      $this->logger->warning('WSC Gateway registration deferred: @msg', ['@msg' => $e->getMessage()]);
    }

    return false;
  }

  /**
   * Get configured gateway URL.
   */
  public function getGatewayUrl(): string {
    $config = $this->configFactory->get('wsc_analytics.settings');
    return $config->get('gateway_url') ?: self::GATEWAY_API_DEFAULT;
  }

  /**
   * Queue an analytics event in local SQLite.
   */
  public function trackEvent(string $type, string $path, ?string $referrer = '', array $meta = []): bool {
    try {
      $pdo = $this->dbManager->getQueuePdo();
      $stmt = $pdo->prepare("
        INSERT INTO wsc_events_queue (created_at, event_type, url_path, referrer, payload_json, synced)
        VALUES (:created_at, :event_type, :url_path, :referrer, :payload_json, 0)
      ");

      $stmt->execute([
        ':created_at'   => time(),
        ':event_type'   => $type,
        ':url_path'     => $path ?: '/',
        ':referrer'     => $referrer ?: '',
        ':payload_json' => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      ]);

      return true;
    }
    catch (Exception $e) {
      $this->logger->error('Error writing to WSC event queue: @msg', ['@msg' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * Process pending batch export from SQLite to Gateway.
   */
  public function processBatchExport(int $batch_size = 500): int {
    $config = $this->configFactory->get('wsc_analytics.settings');
    if ($config->get('hybrid_enabled') === false) {
      return 0; // Local-only mode
    }

    $creds = $this->getProjectCredentials();
    $pdo = $this->dbManager->getQueuePdo();

    // Fetch un-synced events
    $stmt = $pdo->prepare("SELECT * FROM wsc_events_queue WHERE synced = 0 ORDER BY id ASC LIMIT :limit");
    $stmt->bindValue(':limit', $batch_size, \PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
      return 0;
    }

    $ids = [];
    $events = [];
    foreach ($rows as $row) {
      $ids[] = (int) $row['id'];
      $events[] = [
        't' => (int) $row['created_at'],
        'e' => $row['event_type'],
        'p' => $row['url_path'],
        'r' => $row['referrer'],
        'm' => json_decode($row['payload_json'], true) ?: [],
      ];
    }

    $payload_json = json_encode(['events' => $events], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // Encrypt payload with AES-256-CBC
    $key_binary = hex2bin($creds['local_key']);
    $iv = random_bytes(16);
    $ciphertext = openssl_encrypt($payload_json, 'AES-256-CBC', $key_binary, OPENSSL_RAW_DATA, $iv);
    $encrypted_body = base64_encode($iv . $ciphertext);

    // Generate HMAC signature
    $signature = hash_hmac('sha256', $encrypted_body, $creds['local_key']);

    try {
      $response = $this->httpClient->request('POST', rtrim($this->getGatewayUrl(), '/') . '/v1/ingest', [
        'headers' => [
          'Content-Type' => 'application/json',
          'X-WSC-Project' => $creds['project_id'],
          'X-WSC-Sig'     => $signature,
        ],
        'body' => $encrypted_body,
        'timeout' => 10.0,
      ]);

      if ($response->getStatusCode() === 200 || $response->getStatusCode() === 202) {
        $in_clause = implode(',', $ids);
        $pdo->exec("UPDATE wsc_events_queue SET synced = 1 WHERE id IN ($in_clause)");
        $this->state->set('wsc_analytics.last_sync', time());
        return count($ids);
      }
    }
    catch (Exception $e) {
      $this->logger->warning('WSC Batch Export failed: @msg', ['@msg' => $e->getMessage()]);
    }

    return 0;
  }

  /**
   * Save incoming 7-Card reports from WSC Gateway Webhook.
   */
  public function saveWebhookReport(array $data): bool {
    try {
      $pdo = $this->dbManager->getReportsPdo();

      $stmt = $pdo->prepare("
        INSERT INTO wsc_dashboard_reports (id, report_type, period_type, updated_at, payload)
        VALUES (:id, :report_type, :period_type, :updated_at, :payload)
        ON CONFLICT(id) DO UPDATE SET
          updated_at = excluded.updated_at,
          payload = excluded.payload
      ");

      $now = time();

      $cardMappings = [
        'card1'           => ['traffic', $data['card1'] ?? $data['card1_traffic'] ?? null],
        'card1_traffic'   => ['traffic', $data['card1_traffic'] ?? $data['card1'] ?? null],
        'card2'           => ['insights', $data['card2'] ?? $data['card2_insights'] ?? null],
        'card2_insights'  => ['insights', $data['card2_insights'] ?? $data['card2'] ?? null],
        'card3'           => ['bots', $data['card3'] ?? $data['card3_bots'] ?? null],
        'card3_bots'      => ['bots', $data['card3_bots'] ?? $data['card3'] ?? null],
        'card_engagement' => ['engagement', $data['card_engagement'] ?? $data['card4'] ?? null],
        'card_outbound'   => ['outbound', $data['card_outbound'] ?? $data['card5'] ?? null],
        'card_ecommerce'  => ['ecommerce', $data['card_ecommerce'] ?? $data['card6'] ?? null],
        'domain_health'   => ['sentinel', $data['domain_health'] ?? $data['sentinel'] ?? $data['card7'] ?? null],
        'pagespeed'       => ['pagespeed', $data['pagespeed'] ?? null],
      ];

      foreach ($cardMappings as $cardId => $meta) {
        $type = $meta[0];
        $payload = $meta[1];
        if ($payload !== null) {
          $stmt->execute([
            ':id'          => $cardId,
            ':report_type' => $type,
            ':period_type' => 'multi',
            ':updated_at'  => $now,
            ':payload'     => is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
          ]);
        }
      }

      // Sync into Views aggregated table (wsc_daily_overview)
      if (isset($data['daily_overview']) && is_array($data['daily_overview'])) {
        $overview_stmt = $pdo->prepare("
          INSERT INTO wsc_daily_overview (date, human_visitors, human_pageviews, bot_hits, ai_training_hits, ai_rag_hits, interaction_events, human_pct)
          VALUES (:date, :human_visitors, :human_pageviews, :bot_hits, :ai_training_hits, :ai_rag_hits, :interaction_events, :human_pct)
          ON CONFLICT(date) DO UPDATE SET
            human_visitors = excluded.human_visitors,
            human_pageviews = excluded.human_pageviews,
            bot_hits = excluded.bot_hits,
            ai_training_hits = excluded.ai_training_hits,
            ai_rag_hits = excluded.ai_rag_hits,
            interaction_events = excluded.interaction_events,
            human_pct = excluded.human_pct
        ");

        foreach ($data['daily_overview'] as $day) {
          $overview_stmt->execute([
            ':date'               => $day['date'],
            ':human_visitors'     => $day['human_visitors'] ?? 0,
            ':human_pageviews'    => $day['human_pageviews'] ?? 0,
            ':bot_hits'           => $day['bot_hits'] ?? 0,
            ':ai_training_hits'   => $day['ai_training_hits'] ?? 0,
            ':ai_rag_hits'        => $day['ai_rag_hits'] ?? 0,
            ':interaction_events' => $day['interaction_events'] ?? 0,
            ':human_pct'          => $day['human_pct'] ?? 100.0,
          ]);
        }
      }

      return true;
    }
    catch (Exception $e) {
      $this->logger->error('Failed to save WSC webhook report: @msg', ['@msg' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * Fetch all 7 Canonical Cards for Dashboard rendering.
   */
  public function getDashboardCards(): array {
    $cards = [
      'card1'           => null,
      'card2'           => null,
      'card3'           => null,
      'card1_traffic'   => null,
      'card2_insights'  => null,
      'card3_bots'      => null,
      'card_engagement' => null,
      'card_outbound'   => null,
      'card_ecommerce'  => null,
      'domain_health'   => null,
      'pagespeed'       => null,
    ];

    try {
      $pdo = $this->dbManager->getReportsPdo();
      $stmt = $pdo->query("SELECT id, payload FROM wsc_dashboard_reports");
      $rows = $stmt->fetchAll();

      foreach ($rows as $row) {
        $id = $row['id'];
        $decoded = json_decode($row['payload'], true);
        $cards[$id] = $decoded;

        // Populate alias mappings
        if ($id === 'card1' && empty($cards['card1_traffic'])) $cards['card1_traffic'] = $decoded;
        if ($id === 'card1_traffic' && empty($cards['card1'])) $cards['card1'] = $decoded;
        if ($id === 'card2' && empty($cards['card2_insights'])) $cards['card2_insights'] = $decoded;
        if ($id === 'card2_insights' && empty($cards['card2'])) $cards['card2'] = $decoded;
        if ($id === 'card3' && empty($cards['card3_bots'])) $cards['card3_bots'] = $decoded;
        if ($id === 'card3_bots' && empty($cards['card3'])) $cards['card3'] = $decoded;
      }
    }
    catch (Exception $e) {
      $this->logger->error('Error reading dashboard cards: @msg', ['@msg' => $e->getMessage()]);
    }

    return $cards;
  }

  /**
   * Trigger SpeedSentinel 2.0 Core Web Vitals Lab Audit.
   */
  public function triggerSpeedSentinelAudit(string $url, string $strategy = 'mobile'): array {
    $creds = $this->getProjectCredentials();
    $gatewayUrl = $this->getGatewayUrl();

    $body = json_encode(['url' => $url, 'strategy' => $strategy]);
    $sig = hash_hmac('sha256', $body, $creds['local_key']);

    try {
      $response = $this->httpClient->request('POST', rtrim($gatewayUrl, '/') . '/v1/pagespeed/audit', [
        'headers' => [
          'Content-Type'  => 'application/json',
          'X-WSC-Project' => $creds['project_id'],
          'X-WSC-Sig'     => $sig,
        ],
        'body' => $body,
        'timeout' => 15.0,
      ]);

      return json_decode($response->getBody()->getContents(), true) ?: ['ok' => true, 'status' => 'queued'];
    }
    catch (Exception $e) {
      $this->logger->warning('SpeedSentinel audit request deferred: @msg', ['@msg' => $e->getMessage()]);
      return ['ok' => false, 'error' => $e->getMessage()];
    }
  }

  /**
   * Trigger Sentinel Domain Health Scan.
   */
  public function triggerSentinelScan(string $domain): array {
    $creds = $this->getProjectCredentials();
    $gatewayUrl = $this->getGatewayUrl();

    $body = json_encode(['domain' => $domain, 'sync' => false]);
    $sig = hash_hmac('sha256', $body, $creds['local_key']);

    try {
      $response = $this->httpClient->request('POST', rtrim($gatewayUrl, '/') . '/v1/sentinel/scan', [
        'headers' => [
          'Content-Type'  => 'application/json',
          'X-WSC-Project' => $creds['project_id'],
          'X-WSC-Sig'     => $sig,
        ],
        'body' => $body,
        'timeout' => 15.0,
      ]);

      return json_decode($response->getBody()->getContents(), true) ?: ['ok' => true, 'status' => 'queued'];
    }
    catch (Exception $e) {
      $this->logger->warning('Sentinel scan request deferred: @msg', ['@msg' => $e->getMessage()]);
      return ['ok' => false, 'error' => $e->getMessage()];
    }
  }

  /**
   * Retention cleanup and SQLite optimization.
   */
  public function cleanupOldData(): void {
    $config = $this->configFactory->get('wsc_analytics.settings');
    $retention_days = (int) ($config->get('retention_days') ?: 30);
    $threshold = time() - ($retention_days * 86400);

    try {
      $queue_pdo = $this->dbManager->getQueuePdo();
      $queue_pdo->exec("DELETE FROM wsc_events_queue WHERE synced = 1 AND created_at < $threshold");

      $this->dbManager->optimizeDatabases();
      $this->logger->info('WSC SQLite cleanup & optimize completed successfully.');
    }
    catch (Exception $e) {
      $this->logger->error('WSC Cleanup error: @msg', ['@msg' => $e->getMessage()]);
    }
  }
}
