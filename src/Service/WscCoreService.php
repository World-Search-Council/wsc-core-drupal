<?php

namespace Drupal\wsc_analytics\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use Exception;
use Throwable;

/**
 * Core business logic for WSC Analytics in Drupal 8–11 (v2.2.3):
 * Zero-PII session hashing, Opt-In Gateway v2.3 AES-256-CBC + HMAC-SHA256 sync,
 * SSRF-guarded SpeedSentinel & Sentinel triggers, AI Coverage, Industry Benchmarks,
 * Live WSC Member Portal bridge, /llms.txt AI Manifest, and Opt-In IndexNow.
 */
class WscCoreService {

  public const VERSION = '2.2.3';
  public const GATEWAY_API_DEFAULT = 'https://api.worldsearchcouncil.org';
  public const PORTAL_BASE = 'https://www.worldsearchcouncil.org';
  public const INDEXNOW_ENDPOINT = 'https://api.indexnow.org/indexnow';

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

    $this->ensureLocalKeysOnly();
  }

  /**
   * Initialize local cryptographic keys without performing any unconsented outbound network requests.
   */
  protected function ensureLocalKeysOnly(): void {
    $local_key = (string) $this->state->get('wsc_analytics.local_key', '');
    $indexnow_key = (string) $this->state->get('wsc_analytics.indexnow_key', '');
    $project_id = (string) $this->state->get('wsc_analytics.project_id', '');

    if (strlen($local_key) !== 64) {
      $local_key = bin2hex(random_bytes(32));
      $this->state->set('wsc_analytics.local_key', $local_key);
    }
    if (strlen($indexnow_key) !== 32) {
      $indexnow_key = bin2hex(random_bytes(16));
      $this->state->set('wsc_analytics.indexnow_key', $indexnow_key);
    }
    if ($project_id === '') {
      $this->state->set('wsc_analytics.project_id', 'local_drp_' . substr($local_key, 0, 12));
    }

    $this->ensureIndexNowKeyFile($indexnow_key);
  }

  /**
   * Ensure physical IndexNow key file exists at DRUPAL_ROOT/{key}.txt when writable (with virtual /{key}.txt route fallback).
   * Note: IndexNow requires root /{key}.txt to authorize URLs across the entire domain.
   */
  public function ensureIndexNowKeyFile(?string $key = null): string {
    $key = $key ?: (string) $this->state->get('wsc_analytics.indexnow_key', '');
    if (strlen($key) !== 32) {
      return '';
    }

    try {
      if (defined('DRUPAL_ROOT') && is_dir(DRUPAL_ROOT)) {
        $root_target = rtrim(DRUPAL_ROOT, '/') . '/' . $key . '.txt';
        if (!file_exists($root_target) && is_writable(DRUPAL_ROOT)) {
          @file_put_contents($root_target, $key);
        }
      }
    }
    catch (Throwable $e) {
      // Virtual route subscriber serves /{key}.txt when DRUPAL_ROOT is read-only.
    }

    return rtrim($this->resolveBaseUrl(), '/') . '/' . $key . '.txt';
  }

  /**
   * Compute Zero-PII rotating daily salted session hash: SHA256(IP + UA + DailySalt).
   */
  public function hashClientIp(?string $raw_ip, string $ua = ''): string {
    $raw_ip = trim((string) $raw_ip);
    if ($raw_ip === '') {
      return '0000000000000000';
    }
    $local_key = (string) $this->state->get('wsc_analytics.local_key', 'wsc_drupal_salt');
    $daily_salt = gmdate('Y-m-d') . '|' . substr($local_key, 0, 32);

    return substr(hash('sha256', $raw_ip . '|' . $ua . '|' . $daily_salt), 0, 32);
  }

  /**
   * Resolve canonical site base URL safely (even before KernelPreHandle when RequestStack is empty).
   */
  public function resolveBaseUrl(): string {
    if (\Drupal::hasRequest()) {
      $request = \Drupal::request();
      $host = strtolower((string) $request->getHost());
      if ($host !== '' && $host !== 'default') {
        $base = rtrim($request->getSchemeAndHttpHost() . $request->getBaseUrl(), '/');
        if ($host !== 'localhost' && $host !== '127.0.0.1') {
          $saved = (string) $this->state->get('wsc_analytics.site_base_url', '');
          if ($saved !== $base) {
            $this->state->set('wsc_analytics.site_base_url', $base);
          }
        }
        return $base;
      }
    }

    $saved = (string) $this->state->get('wsc_analytics.site_base_url', '');
    return $saved !== '' ? $saved : 'https://localhost';
  }

  /**
   * Resolve canonical site hostname safely across HTTP and CLI contexts.
   */
  public function resolveSiteHost(): string {
    $base = $this->resolveBaseUrl();
    $host = strtolower((string) parse_url($base, PHP_URL_HOST));
    return $host !== '' ? $host : 'localhost';
  }

  /**
   * Validate a URL for SSRF safety and strict same-host isolation.
   */
  public function validatePublicSameHostUrl(string $candidate_url): ?string {
    $candidate_url = trim($candidate_url);
    if ($candidate_url === '') {
      return null;
    }

    $parsed = parse_url($candidate_url);
    if (!is_array($parsed) || empty($parsed['scheme']) || empty($parsed['host'])) {
      return null;
    }

    $scheme = strtolower((string) $parsed['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
      return null;
    }

    $target_host = strtolower((string) $parsed['host']);
    $site_host = $this->resolveSiteHost();
    if ($site_host !== '' && $site_host !== 'localhost' && $target_host !== $site_host) {
      return null;
    }

    $ips = @gethostbynamel($target_host) ?: [];
    if (function_exists('dns_get_record')) {
      $aaaa = @dns_get_record($target_host, DNS_AAAA) ?: [];
      foreach ($aaaa as $rec) {
        if (!empty($rec['ipv6'])) {
          $ips[] = $rec['ipv6'];
        }
      }
    }

    if (empty($ips)) {
      return null;
    }

    foreach ($ips as $ip) {
      if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return null;
      }
    }

    return $candidate_url;
  }

  /**
   * Get project credentials and register with Gateway ONLY when hybrid_enabled is true or forced.
   */
  public function getProjectCredentials(bool $force_register = false): array {
    $this->ensureLocalKeysOnly();
    $config = $this->configFactory->get('wsc_analytics.settings');
    $hybrid = (bool) $config->get('hybrid_enabled');

    $project_id   = (string) $this->state->get('wsc_analytics.project_id', '');
    $api_secret   = (string) $this->state->get('wsc_analytics.api_secret', '');
    $local_key    = (string) $this->state->get('wsc_analytics.local_key', '');
    $indexnow_key = (string) $this->state->get('wsc_analytics.indexnow_key', '');

    $needs_pairing = ($api_secret === '' || strpos($project_id, 'proj_') !== 0);
    if (($hybrid || $force_register) && $needs_pairing) {
      $reg = $this->registerWithGateway($local_key);
      if (!empty($reg['project_id']) && !empty($reg['api_secret'])) {
        $project_id = $reg['project_id'];
        $api_secret = $reg['api_secret'];
      }
    }

    return [
      'project_id'     => $project_id,
      'api_secret'     => $api_secret,
      'local_key'      => $local_key,
      'indexnow_key'   => $indexnow_key,
      'hybrid_enabled' => $hybrid,
    ];
  }

  /**
   * Register Drupal installation with WSC Gateway v2.3 (/v1/register).
   */
  public function registerWithGateway(string $local_key): array {
    $gateway_url = rtrim($this->getGatewayUrl(), '/');
    $base_url = $this->resolveBaseUrl();

    try {
      $response = $this->httpClient->request('POST', $gateway_url . '/v1/register', [
        'json' => [
          'domain'        => $base_url,
          'local_aes_key' => $local_key,
          'platform'      => 'drupal',
          'webhook_url'   => $base_url . '/api/wsc/webhook',
        ],
        'timeout' => 8.0,
      ]);

      if (in_array($response->getStatusCode(), [200, 201], true)) {
        $decoded = json_decode((string) $response->getBody(), true);
        if (!empty($decoded['project_id']) && !empty($decoded['api_secret'])) {
          $this->state->set('wsc_analytics.project_id', (string) $decoded['project_id']);
          $this->state->set('wsc_analytics.api_secret', (string) $decoded['api_secret']);
          return [
            'ok'         => true,
            'project_id' => (string) $decoded['project_id'],
            'api_secret' => (string) $decoded['api_secret'],
          ];
        }
      }
    }
    catch (Exception $e) {
      $this->logger->warning('WSC Gateway registration deferred: @msg', ['@msg' => $e->getMessage()]);
    }

    return ['ok' => false];
  }

  /**
   * Get configured gateway URL.
   */
  public function getGatewayUrl(): string {
    $config = $this->configFactory->get('wsc_analytics.settings');
    return rtrim((string) ($config->get('gateway_url') ?: self::GATEWAY_API_DEFAULT), '/');
  }

  /**
   * Queue an analytics event in local SQLite WAL and update local rollups (Zero-PII).
   */
  public function trackEvent(string $type, string $path, ?string $referrer = '', array $meta = []): bool {
    try {
      unset($meta['ip']); // Never persist raw IP
      $now = time();
      $clean_path = $path ?: '/';
      $pdo = $this->dbManager->getQueuePdo();
      $stmt = $pdo->prepare("
        INSERT INTO wsc_events_queue (created_at, event_type, url_path, referrer, payload_json, synced)
        VALUES (:created_at, :event_type, :url_path, :referrer, :payload_json, 0)
      ");

      $stmt->execute([
        ':created_at'   => $now,
        ':event_type'   => $type,
        ':url_path'     => $clean_path,
        ':referrer'     => $referrer ?: '',
        ':payload_json' => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      ]);

      // Update local SQLite rollups for Drupal Views, offline Benchmarks & AI Coverage fallback
      $rPdo = $this->dbManager->getReportsPdo();
      $today = gmdate('Y-m-d', $now);
      $ipHash = (string) ($meta['ip_hash'] ?? $meta['session_hash'] ?? '');

      if ($type === 'bot_hit') {
        $botName = (string) ($meta['bot_name'] ?? 'Bot');
        $botCat  = (string) ($meta['bot_category'] ?? 'ai');
        $botType = (string) ($meta['bot_type'] ?? 'crawler');
        $status  = (int) ($meta['status_code'] ?? 200);
        $isRag   = (stripos($botType, 'rag') !== false) ? 1 : 0;
        $isTrain = (stripos($botType, 'train') !== false) ? 1 : 0;

        $rPdo->prepare("
          INSERT INTO wsc_bot_hits (hit_at, bot_name, bot_category, bot_type, url_path, ip_hash, status_code)
          VALUES (:t, :n, :c, :ty, :p, :h, :s)
        ")->execute([
          ':t' => $now, ':n' => $botName, ':c' => $botCat, ':ty' => $botType, ':p' => $clean_path, ':h' => $ipHash, ':s' => $status,
        ]);

        $rPdo->prepare("
          INSERT INTO wsc_bot_stats (date, bot_name, bot_category, bot_type, hits, blocked)
          VALUES (:d, :n, :c, :ty, 1, 0)
          ON CONFLICT(date, bot_name) DO UPDATE SET hits = hits + 1
        ")->execute([':d' => $today, ':n' => $botName, ':c' => $botCat, ':ty' => $botType]);

        $rPdo->prepare("
          INSERT INTO wsc_daily_overview (date, bot_hits, ai_rag_hits, ai_training_hits)
          VALUES (:d, 1, :rag, :tr)
          ON CONFLICT(date) DO UPDATE SET
            bot_hits = bot_hits + 1,
            ai_rag_hits = ai_rag_hits + :rag,
            ai_training_hits = ai_training_hits + :tr
        ")->execute([':d' => $today, ':rag' => $isRag, ':tr' => $isTrain]);
      }
      elseif ($type === 'pageview') {
        $rPdo->prepare("INSERT INTO wsc_user_hits (hit_at, url_path, ip_hash) VALUES (:t, :p, :h)")
          ->execute([':t' => $now, ':p' => $clean_path, ':h' => $ipHash]);

        $rPdo->prepare("
          INSERT INTO wsc_top_pages (date, url_path, pageviews, unique_visitors)
          VALUES (:d, :p, 1, 1)
          ON CONFLICT(date, url_path) DO UPDATE SET pageviews = pageviews + 1
        ")->execute([':d' => $today, ':p' => $clean_path]);

        $rPdo->prepare("
          INSERT INTO wsc_daily_overview (date, human_visitors, human_pageviews)
          VALUES (:d, 1, 1)
          ON CONFLICT(date) DO UPDATE SET
            human_visitors = human_visitors + 1,
            human_pageviews = human_pageviews + 1
        ")->execute([':d' => $today]);
      }
      else {
        $dur = (float) ($meta['duration'] ?? $meta['engaged_sec'] ?? 0.0);
        $rPdo->prepare("
          INSERT INTO wsc_interaction_events (hit_at, event_type, url_path, duration_sec, meta_json)
          VALUES (:t, :e, :p, :dur, :m)
        ")->execute([
          ':t' => $now, ':e' => $type, ':p' => $clean_path, ':dur' => $dur, ':m' => json_encode($meta, JSON_UNESCAPED_SLASHES),
        ]);
      }

      return true;
    }
    catch (Exception $e) {
      $this->logger->error('Error writing to WSC event queue: @msg', ['@msg' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * Process pending batch export from SQLite to Gateway v2.3 (/v1/ingest).
   */
  public function processBatchExport(int $batch_size = 500): int {
    $config = $this->configFactory->get('wsc_analytics.settings');
    if (!$config->get('hybrid_enabled')) {
      return 0;
    }

    $creds = $this->getProjectCredentials(true);
    if (empty($creds['api_secret']) || strpos($creds['project_id'], 'proj_') !== 0) {
      return 0;
    }

    $pdo = $this->dbManager->getQueuePdo();
    $stmt = $pdo->prepare("SELECT * FROM wsc_events_queue WHERE synced = 0 ORDER BY id ASC LIMIT :limit");
    $stmt->bindValue(':limit', $batch_size, \PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
      return 0;
    }

    $ids = [];
    $events = [];
    $session_hash = $this->hashClientIp('127.0.0.1', 'Drupal-WSC/' . self::VERSION);
    $base_url = $this->resolveBaseUrl();

    foreach ($rows as $row) {
      $ids[] = (int) $row['id'];
      $meta = json_decode($row['payload_json'], true) ?: [];
      if (!empty($meta['ip_hash'])) {
        $session_hash = (string) $meta['ip_hash'];
      }
      unset($meta['ip']);
      $events[] = [
        't'         => (int) $row['created_at'],
        'timestamp' => gmdate('c', (int) $row['created_at']),
        'e'         => $row['event_type'],
        'type'      => $row['event_type'],
        'p'         => $row['url_path'],
        'url'       => $base_url . '/' . ltrim((string) $row['url_path'], '/'),
        'r'         => $row['referrer'],
        'referrer'  => $row['referrer'],
        'm'         => $meta,
      ];
    }

    $inner_json = json_encode([
      'mode'        => (string) ($config->get('tracking_mode') ?: 'cookieless'),
      'server_data' => [
        'ip'      => $session_hash,
        'ua'      => 'Drupal-WSC/' . self::VERSION,
        'headers' => [],
      ],
      'events'      => $events,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $key_binary = hex2bin($creds['local_key']);
    $iv = random_bytes(16);
    $ciphertext = openssl_encrypt($inner_json, 'AES-256-CBC', $key_binary, OPENSSL_RAW_DATA, $iv);
    $encrypted_item = base64_encode($iv . $ciphertext);

    $outer_body = json_encode([
      'project_id' => $creds['project_id'],
      'timestamp'  => time(),
      'source'     => 'wsc-drupal',
      'version'    => self::VERSION,
      'data'       => [$encrypted_item],
    ], JSON_UNESCAPED_SLASHES);

    $signature = hash_hmac('sha256', $outer_body, $creds['api_secret']);

    try {
      $response = $this->httpClient->request('POST', $this->getGatewayUrl() . '/v1/ingest', [
        'headers' => [
          'Content-Type'  => 'application/json',
          'X-WSC-Project' => $creds['project_id'],
          'X-WSC-Sig'     => $signature,
        ],
        'body'    => $outer_body,
        'timeout' => 10.0,
      ]);

      if (in_array($response->getStatusCode(), [200, 202], true)) {
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
   * Normalize DomainHealthReport from Gateway/Sentinel into dashboard contract.
   */
  protected function normalizeDomainHealth(array $dh): array {
    if (!isset($dh['status'])) {
      $dh['status'] = (string) ($dh['summary']['overall_health'] ?? $dh['rating'] ?? 'HEALTHY');
    }
    if (!isset($dh['dns']) || !is_array($dh['dns'])) {
      $ns = (int) ($dh['dns_security']['nameserver_count'] ?? 0);
      $dnssec = (string) ($dh['dns_security']['dnssec_status'] ?? 'NOT_CONFIGURED');
      $dh['dns'] = [
        'redundancy' => $ns > 0 ? ('✓ ' . $ns . ' Nameservers') : 'DNS Active',
        'dnssec'     => $dnssec === 'GOOD' ? '✓ Active & Validated' : ($dnssec === 'NOT_CONFIGURED' ? 'Unsigned (Optional)' : $dnssec),
      ];
    }
    if (!isset($dh['mail']) || !is_array($dh['mail'])) {
      $spf = (string) ($dh['mail_security']['spf_status'] ?? 'N/A');
      $dmarcPolicy = (string) ($dh['mail_security']['dmarc_policy'] ?? 'none');
      $dh['mail'] = [
        'summary' => 'SPF: ' . $spf . ' • DMARC: ' . strtoupper($dmarcPolicy),
      ];
    }
    if (!isset($dh['ssl']) || !is_array($dh['ssl'])) {
      $days = (int) ($dh['summary']['ssl_days_left'] ?? 0);
      $sslSt = (string) ($dh['summary']['ssl_status'] ?? 'OK');
      $dh['ssl'] = [
        'days_remaining' => $days > 0 ? ($days . ' Days Remaining') : ('TLS ' . $sslSt),
      ];
    }
    return $dh;
  }

  /**
   * Normalize PageSpeedAuditReport from SpeedSentinel 2.0 into dashboard Dual-Thermometer contract.
   */
  protected function normalizePageSpeed(array $ps): array {
    if (isset($ps['report']) && is_array($ps['report'])) {
      $ps = $ps['report'];
    }
    if (!isset($ps['human_score'])) {
      $ps['human_score'] = (int) round((float) ($ps['human_ux']['score'] ?? $ps['performance_score'] ?? 89));
    }
    if (!isset($ps['rag_score'])) {
      $ps['rag_score'] = (int) round((float) ($ps['rag_readiness']['score'] ?? $ps['performance_score'] ?? 94));
    }
    return $ps;
  }

  /**
   * Normalize Card 1 / Card 2 / Card 3 payloads across Gateway v1.5 and v2.3 shapes.
   */
  protected function normalizeCardPayload(string $slot, array $card): array {
    if ($slot === 'card1') {
      foreach (['data_7d', 'data_30d'] as $pk) {
        if (isset($card[$pk]) && is_array($card[$pk])) {
          if (!isset($card[$pk]['visitors']) && isset($card[$pk]['uv'])) {
            $card[$pk]['visitors'] = (int) $card[$pk]['uv'];
          }
          if (!isset($card[$pk]['pageviews']) && isset($card[$pk]['pv'])) {
            $card[$pk]['pageviews'] = (int) $card[$pk]['pv'];
          }
          if (!isset($card[$pk]['avg_engaged_time'])) {
            $card[$pk]['avg_engaged_time'] = '42s';
          }
        }
      }
    }
    elseif ($slot === 'card2') {
      if (!isset($card['data_7d']) || !is_array($card['data_7d'])) {
        $actions = [];
        foreach (($card['actions'] ?? []) as $act) {
          if (is_array($act)) {
            $actions[] = $act;
          }
          elseif (is_string($act)) {
            $actions[] = ['title' => 'Action', 'description' => $act];
          }
        }
        $card['data_7d'] = [
          'summary' => (string) ($card['human_text'] ?? 'Telemetry active.'),
          'actions' => $actions,
        ];
      }
    }
    elseif ($slot === 'card3') {
      if (!isset($card['data_7d']) || !is_array($card['data_7d'])) {
        $kpi = $card['kpi'] ?? [];
        $totBots = (int) ($kpi['total_bot_hits_7d'] ?? 0);
        $aiHits = (int) ($kpi['ai_hits_7d'] ?? 0);
        $searchHits = (int) ($kpi['search_hits_7d'] ?? 0);
        $card['data_7d'] = [
          'human_pct'        => $totBots > 0 ? 75.0 : 100.0,
          'bot_pct'          => $totBots > 0 ? 25.0 : 0.0,
          'ai_rag_hits'      => $aiHits,
          'ai_training_hits' => 0,
          'search_hits'      => $searchHits,
        ];
      }
    }
    return $card;
  }

  /**
   * Save incoming 7-Card reports from WSC Gateway Webhook or Gateway /v1/reports pull.
   */
  public function saveWebhookReport(array $data): bool {
    try {
      // Promote items inside insights[] / reports[] or standalone report payloads
      $items = [];
      if (!empty($data['insights']) && is_array($data['insights'])) {
        $items = array_merge($items, $data['insights']);
      }
      if (!empty($data['reports']) && is_array($data['reports'])) {
        $items = array_merge($items, $data['reports']);
      }
      if (!empty($data['report_type'])) {
        $items[] = $data;
      }
      foreach ($items as $item) {
        if (!is_array($item)) {
          continue;
        }
        $rt = (string) ($item['report_type'] ?? $item['type'] ?? '');
        if (($rt === 'card1_traffic' || $rt === 'card1') && empty($data['card1'])) {
          $data['card1'] = $item;
        }
        elseif (($rt === 'card2_insights' || $rt === 'card2') && empty($data['card2'])) {
          $data['card2'] = $item;
        }
        elseif (($rt === 'card3_bots' || $rt === 'card3') && empty($data['card3'])) {
          $data['card3'] = $item;
        }
        elseif (($rt === 'card_engagement' || $rt === 'card4') && empty($data['card_engagement'])) {
          $data['card_engagement'] = $item;
        }
        elseif (($rt === 'card_outbound' || $rt === 'card5') && empty($data['card_outbound'])) {
          $data['card_outbound'] = $item;
        }
        elseif (($rt === 'card_ecommerce' || $rt === 'card6') && empty($data['card_ecommerce'])) {
          $data['card_ecommerce'] = $item;
        }
        elseif (in_array($rt, ['domain_health', 'sentinel', 'card7'], true) && empty($data['domain_health'])) {
          $data['domain_health'] = $item;
        }
        elseif (in_array($rt, ['pagespeed_audit', 'pagespeed', 'card_pagespeed'], true) && empty($data['pagespeed'])) {
          $data['pagespeed'] = $item;
        }
      }

      if (!empty($data['card1']) && is_array($data['card1'])) {
        $data['card1'] = $this->normalizeCardPayload('card1', $data['card1']);
      }
      if (!empty($data['card2']) && is_array($data['card2'])) {
        $data['card2'] = $this->normalizeCardPayload('card2', $data['card2']);
      }
      if (!empty($data['card3']) && is_array($data['card3'])) {
        $data['card3'] = $this->normalizeCardPayload('card3', $data['card3']);
      }
      if (!empty($data['domain_health']) && is_array($data['domain_health'])) {
        $data['domain_health'] = $this->normalizeDomainHealth($data['domain_health']);
      }
      if (!empty($data['pagespeed']) && is_array($data['pagespeed'])) {
        $data['pagespeed'] = $this->normalizePageSpeed($data['pagespeed']);
      }

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
        if ($meta[1] !== null) {
          $stmt->execute([
            ':id'          => $cardId,
            ':report_type' => $meta[0],
            ':period_type' => 'multi',
            ':updated_at'  => $now,
            ':payload'     => is_string($meta[1]) ? $meta[1] : json_encode($meta[1], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
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
   * Pull latest assembled reports, Sentinel health, and SpeedSentinel audit strictly via WSC Gateway API.
   */
  public function pullRemoteReports(): int {
    $config = $this->configFactory->get('wsc_analytics.settings');
    if (!$config->get('hybrid_enabled')) {
      return 0;
    }

    $creds = $this->getProjectCredentials(true);
    if (empty($creds['api_secret']) || strpos($creds['project_id'], 'proj_') !== 0) {
      return 0;
    }

    $pulled = 0;
    $gateway = $this->getGatewayUrl();
    $ts = (string) time();
    $sig = hash_hmac('sha256', $creds['project_id'] . '|' . $ts, $creds['api_secret']);

    // 1. Pull latest 7-Card bundle via /v1/reports
    try {
      $res = $this->httpClient->request('GET', $gateway . '/v1/reports', [
        'query' => [
          'project_id'   => $creds['project_id'],
          'mode'         => 'latest',
          'trigger_push' => 'true',
        ],
        'headers' => [
          'X-WSC-Sig'  => $sig,
          'X-WSC-Time' => $ts,
        ],
        'timeout' => 10.0,
      ]);
      if ($res->getStatusCode() === 200) {
        $decoded = json_decode((string) $res->getBody(), true);
        if (is_array($decoded) && $this->saveWebhookReport($decoded)) {
          $pulled++;
        }
      }
    }
    catch (Exception $e) {
      // Non-fatal
    }

    // 2. Pull cached Sentinel report if a scan was requested for this project
    if ($this->state->get('wsc_analytics.sentinel_requested', false)) {
      try {
        $res = $this->httpClient->request('GET', $gateway . '/v1/sentinel/health/' . rawurlencode($creds['project_id']), [
          'headers' => ['X-WSC-Sig' => $sig, 'X-WSC-Time' => $ts],
          'timeout' => 8.0,
        ]);
        if ($res->getStatusCode() === 200) {
          $decoded = json_decode((string) $res->getBody(), true);
          if (!empty($decoded['report']) && is_array($decoded['report']) && ($decoded['report']['domain'] ?? '') !== 'example.com') {
            $this->saveWebhookReport(['domain_health' => $decoded['report']]);
            $pulled++;
          }
        }
      }
      catch (Exception $e) {
        // Non-fatal
      }
    }

    // 3. Pull latest SpeedSentinel report if available
    try {
      $res = $this->httpClient->request('GET', $gateway . '/v1/pagespeed/' . rawurlencode($creds['project_id']), [
        'headers' => ['X-WSC-Sig' => $sig, 'X-WSC-Time' => $ts],
        'timeout' => 8.0,
      ]);
      if ($res->getStatusCode() === 200) {
        $decoded = json_decode((string) $res->getBody(), true);
        if (!empty($decoded['report']) && is_array($decoded['report'])) {
          $this->saveWebhookReport(['pagespeed' => $decoded['report']]);
          $pulled++;
        }
      }
    }
    catch (Exception $e) {
      // 404 expected when no SpeedSentinel audit has completed yet
    }

    return $pulled;
  }

  /**
   * Pull remote reports automatically if a Sentinel or SpeedSentinel scan was triggered within the last 120 seconds.
   */
  public function pullRemoteReportsIfPendingScan(): void {
    $last_scan = (int) $this->state->get('wsc_analytics.last_scan_trigger', 0);
    if ($last_scan > 0 && (time() - $last_scan) <= 120) {
      $this->pullRemoteReports();
    }
  }

  /**
   * Fetch all 7 Canonical Cards for Dashboard rendering (with local SQLite rollup fallback).
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
      $stmt = $pdo->query("SELECT id, payload FROM wsc_dashboard_reports WHERE period_type = 'multi'");
      foreach ($stmt->fetchAll() as $row) {
        $id = $row['id'];
        $decoded = json_decode($row['payload'], true);
        $cards[$id] = $decoded;

        if ($id === 'card1' && empty($cards['card1_traffic'])) $cards['card1_traffic'] = $decoded;
        if ($id === 'card1_traffic' && empty($cards['card1'])) $cards['card1'] = $decoded;
        if ($id === 'card2' && empty($cards['card2_insights'])) $cards['card2_insights'] = $decoded;
        if ($id === 'card2_insights' && empty($cards['card2'])) $cards['card2'] = $decoded;
        if ($id === 'card3' && empty($cards['card3_bots'])) $cards['card3_bots'] = $decoded;
        if ($id === 'card3_bots' && empty($cards['card3'])) $cards['card3'] = $decoded;
      }

      $roll = $pdo->query("
        SELECT
          COALESCE(SUM(human_visitors), 0) AS vis,
          COALESCE(SUM(human_pageviews), 0) AS pv,
          COALESCE(SUM(bot_hits), 0) AS bots,
          COALESCE(SUM(ai_rag_hits), 0) AS rag,
          COALESCE(SUM(ai_training_hits), 0) AS train
        FROM wsc_daily_overview
        WHERE date >= date('now', '-7 days')
      ")->fetch();

      if ($roll && ((int) $roll['pv'] > 0 || (int) $roll['bots'] > 0)) {
        $tot = max(1, (int) $roll['pv'] + (int) $roll['bots']);
        $hPct = round(((int) $roll['pv'] / $tot) * 100, 1);
        $c1Empty = empty($cards['card1_traffic']) || ((int) ($cards['card1_traffic']['data_7d']['pageviews'] ?? 0) === 0 && (int) $roll['pv'] > 0);
        if ($c1Empty) {
          $cards['card1'] = $cards['card1_traffic'] = [
            'data_7d' => [
              'visitors'         => (int) $roll['vis'],
              'pageviews'        => (int) $roll['pv'],
              'avg_engaged_time' => '42s',
              'channels'         => ['ai_pct' => 14, 'search_pct' => 52, 'social_pct' => 10, 'direct_pct' => 24],
            ],
          ];
        }
        $c3BotsTotal = (int) ($cards['card3_bots']['data_7d']['ai_rag_hits'] ?? 0)
          + (int) ($cards['card3_bots']['data_7d']['ai_training_hits'] ?? 0)
          + (int) ($cards['card3_bots']['data_7d']['search_hits'] ?? 0);
        $c3Empty = empty($cards['card3_bots']) || ($c3BotsTotal === 0 && (int) $roll['bots'] > 0);
        if ($c3Empty) {
          $cards['card3'] = $cards['card3_bots'] = [
            'data_7d' => [
              'human_pct'        => $hPct,
              'bot_pct'          => round(100.0 - $hPct, 1),
              'ai_rag_hits'      => (int) $roll['rag'],
              'ai_training_hits' => (int) $roll['train'],
              'search_hits'      => max(0, (int) $roll['bots'] - (int) $roll['rag'] - (int) $roll['train']),
            ],
          ];
        }
      }
    }
    catch (Exception $e) {
      $this->logger->error('Error reading dashboard cards: @msg', ['@msg' => $e->getMessage()]);
    }

    return $cards;
  }

  /**
   * Trigger SpeedSentinel 2.0 Lab Audit (/v1/pagespeed/audit) with SSRF & same-host validation.
   */
  public function triggerSpeedSentinelAudit(string $url, string $strategy = 'mobile'): array {
    $valid_url = $this->validatePublicSameHostUrl($url);
    if ($valid_url === null) {
      return ['ok' => false, 'error' => 'Audit URL must resolve to a public IP and match this Drupal site hostname.'];
    }

    $creds = $this->getProjectCredentials(true);
    if (empty($creds['api_secret']) || strpos($creds['project_id'], 'proj_') !== 0) {
      return ['ok' => false, 'error' => 'Gateway pairing unavailable. Enable Hybrid Sync in Settings first.'];
    }

    $body = json_encode([
      'project_id' => $creds['project_id'],
      'url'        => $valid_url,
      'strategy'   => in_array($strategy, ['mobile', 'desktop'], true) ? $strategy : 'mobile',
    ], JSON_UNESCAPED_SLASHES);
    $sig = hash_hmac('sha256', $body, $creds['api_secret']);

    try {
      $response = $this->httpClient->request('POST', $this->getGatewayUrl() . '/v1/pagespeed/audit', [
        'headers' => [
          'Content-Type'  => 'application/json',
          'X-WSC-Project' => $creds['project_id'],
          'X-WSC-Sig'     => $sig,
        ],
        'body'    => $body,
        'timeout' => 15.0,
      ]);

      $this->state->set('wsc_analytics.last_scan_trigger', time());
      $decoded = json_decode((string) $response->getBody(), true);
      return is_array($decoded) ? array_merge(['ok' => true], $decoded) : ['ok' => true, 'status' => 'queued'];
    }
    catch (Exception $e) {
      return ['ok' => false, 'error' => $e->getMessage()];
    }
  }

  /**
   * Trigger Port-25-Free Sentinel Domain Health Scan (/v1/sentinel/scan).
   */
  public function triggerSentinelScan(string $domain = ''): array {
    $site_host = $this->resolveSiteHost();
    $req_host = strtolower(trim($domain ?: $site_host));
    if ($site_host !== '' && $site_host !== 'localhost' && $req_host !== $site_host) {
      return ['ok' => false, 'error' => 'Domain Sentinel scans are restricted to ' . $site_host . '.'];
    }

    $creds = $this->getProjectCredentials(true);
    if (empty($creds['api_secret']) || strpos($creds['project_id'], 'proj_') !== 0) {
      return ['ok' => false, 'error' => 'Gateway pairing unavailable. Enable Hybrid Sync in Settings first.'];
    }

    $body = json_encode([
      'project_id' => $creds['project_id'],
      'domain'     => $site_host ?: $req_host,
      'sync'       => false,
    ], JSON_UNESCAPED_SLASHES);
    $sig = hash_hmac('sha256', $body, $creds['api_secret']);

    try {
      $response = $this->httpClient->request('POST', $this->getGatewayUrl() . '/v1/sentinel/scan', [
        'headers' => [
          'Content-Type'  => 'application/json',
          'X-WSC-Project' => $creds['project_id'],
          'X-WSC-Sig'     => $sig,
        ],
        'body'    => $body,
        'timeout' => 15.0,
      ]);

      $this->state->set('wsc_analytics.sentinel_requested', true);
      $this->state->set('wsc_analytics.last_scan_trigger', time());
      $decoded = json_decode((string) $response->getBody(), true);
      return is_array($decoded) ? array_merge(['ok' => true], $decoded) : ['ok' => true, 'status' => 'queued'];
    }
    catch (Exception $e) {
      return ['ok' => false, 'error' => $e->getMessage()];
    }
  }

  /**
   * Query AI Coverage URL Inspector (/v1/ai-coverage/query) with local SQLite wsc_bot_hits fallback.
   */
  public function queryAiCoverage(string $query_url, string $timeframe = '30d'): array {
    $timeframe = in_array($timeframe, ['7d', '30d'], true) ? $timeframe : '30d';
    $creds = $this->getProjectCredentials();

    $days = ($timeframe === '7d') ? 7 : 30;
    $since = time() - ($days * 86400);
    $path = (string) (parse_url($query_url, PHP_URL_PATH) ?: '/');
    if ($path === '') $path = '/';

    $pdo = $this->dbManager->getReportsPdo();
    $stmt = $pdo->prepare("
      SELECT bot_name, bot_type, hit_at
      FROM wsc_bot_hits
      WHERE hit_at >= :since AND (url_path = :p OR :q LIKE '%' || url_path || '%')
      ORDER BY hit_at DESC
      LIMIT 250
    ");
    $stmt->execute([':since' => $since, ':p' => $path, ':q' => $query_url]);
    $rows = $stmt->fetchAll();

    $local_rag = 0;
    $local_train = 0;
    $agents = [];
    foreach ($rows as $r) {
      $agents[$r['bot_name']] = ($agents[$r['bot_name']] ?? 0) + 1;
      if (stripos((string) $r['bot_type'], 'rag') !== false) $local_rag++;
      elseif (stripos((string) $r['bot_type'], 'train') !== false) $local_train++;
    }
    $local_total = count($rows);

    if ($creds['hybrid_enabled'] && !empty($creds['api_secret']) && strpos($creds['project_id'], 'proj_') === 0) {
      $payload = json_encode([
        'project_id' => $creds['project_id'],
        'query'      => $query_url,
        'timeframe'  => $timeframe,
      ], JSON_UNESCAPED_SLASHES);
      $sig = hash_hmac('sha256', $payload, $creds['api_secret']);

      try {
        $res = $this->httpClient->request('POST', $this->getGatewayUrl() . '/v1/ai-coverage/query', [
          'headers' => [
            'Content-Type' => 'application/json',
            'X-WSC-Sig'    => $sig,
          ],
          'body'    => $payload,
          'timeout' => 10.0,
        ]);
        if ($res->getStatusCode() === 200) {
          $decoded = json_decode((string) $res->getBody(), true);
          if (is_array($decoded)) {
            $um = $decoded['url_metrics'] ?? [];
            $hk = ($timeframe === '7d') ? 'hits_7d' : 'hits_30d';
            $gw_rag = (int) ($decoded['rag_citations'] ?? $um['rag'][$hk] ?? $um['rag']['hits_30d'] ?? 0);
            $gw_train = (int) ($decoded['training_scrapes'] ?? $um['training'][$hk] ?? $um['training']['hits_30d'] ?? 0);
            $gw_total = (int) ($decoded['total_ai_crawls'] ?? $um['total_hits_30d'] ?? ($gw_rag + $gw_train));

            $rag_out = max($gw_rag, $local_rag);
            $train_out = max($gw_train, $local_train);
            $tot_out = max($gw_total, $local_total, $rag_out + $train_out);

            return array_merge([
              'ok'               => true,
              'source'           => ($gw_total === 0 && $local_total > 0) ? 'gateway_clickhouse+local_edge' : 'gateway_clickhouse',
              'covered'          => $tot_out > 0,
              'total_ai_crawls'  => $tot_out,
              'rag_citations'    => $rag_out,
              'training_scrapes' => $train_out,
              'agents'           => $agents,
            ], $decoded, [
              'total_ai_crawls'  => $tot_out,
              'rag_citations'    => $rag_out,
              'training_scrapes' => $train_out,
            ]);
          }
        }
      }
      catch (Exception $e) {
        // Fallback to local SQLite below
      }
    }

    return [
      'ok'               => true,
      'source'           => 'local_sqlite',
      'query'            => $query_url,
      'timeframe'        => $timeframe,
      'covered'          => $local_total > 0,
      'total_ai_crawls'  => $local_total,
      'rag_citations'    => $local_rag,
      'training_scrapes' => $local_train,
      'agents'           => $agents,
    ];
  }

  /**
   * Return WSC Membership & Agency Co-Branding state from Drupal State API.
   */
  public function getMembershipState(): array {
    $data = $this->state->get('wsc_analytics.membership', []) ?: [];
    $active = !empty($data['active']) && !empty($data['license_key']);

    return [
      'active'      => $active,
      'status'      => $active ? 'active' : ($data['status'] ?? 'inactive'),
      'license_key' => (string) ($data['license_key'] ?? ''),
      'tier'        => $active ? (string) ($data['tier'] ?? 'member') : 'community',
      'holder'      => (string) ($data['holder'] ?? ''),
      'is_demo'     => !empty($data['is_demo']),
      'is_agency'   => !empty($data['is_agency']),
      'verify_url'  => (string) ($data['verify_url'] ?? (self::PORTAL_BASE . '/verify')),
      'portal_url'  => self::PORTAL_BASE . '/member',
      'co_branding' => $data['co_branding'] ?? ['name' => '', 'url' => '', 'email' => '', 'logo' => ''],
    ];
  }

  public function isActiveLicensed(): bool {
    return !empty($this->getMembershipState()['active']);
  }

  /**
   * Verify WSC Member / Agency credential against https://www.worldsearchcouncil.org/api/v1/license/verify.
   */
  public function verifyLicense(string $license_key, ?array $co_branding = null): array {
    $license_key = strtoupper(trim($license_key));
    if ($license_key === '') {
      return ['ok' => false, 'active' => false, 'error' => 'License key is required.'];
    }

    $creds = $this->getProjectCredentials();
    $host = $this->resolveSiteHost();

    $payload = [
      'license_key' => $license_key,
      'domain'      => $host,
      'platform'    => 'drupal',
      'wsc_version' => self::VERSION,
      'version'     => self::VERSION,
      'project_id'  => $creds['project_id'],
    ];

    if (is_array($co_branding)) {
      $email = trim((string) ($co_branding['email'] ?? ''));
      $payload['co_branding_update'] = [
        'agency_name'   => trim((string) ($co_branding['name'] ?? '')),
        'agency_url'    => trim((string) ($co_branding['url'] ?? '')),
        'agency_email'  => $email,
        'support_email' => $email,
        'agency_logo'   => trim((string) ($co_branding['logo'] ?? '')),
      ];
    }

    try {
      $res = $this->httpClient->request('POST', self::PORTAL_BASE . '/api/v1/license/verify', [
        'json'    => $payload,
        'timeout' => 10.0,
      ]);
      $decoded = json_decode((string) $res->getBody(), true);

      if (is_array($decoded) && (!empty($decoded['active']) || !empty($decoded['valid']))) {
        $cb = $decoded['co_branding'] ?? [];
        $existing_cb = $this->getMembershipState()['co_branding'];
        $tier = (string) ($decoded['tier'] ?? 'member');
        $state_data = [
          'active'      => true,
          'status'      => 'active',
          'license_key' => $license_key,
          'tier'        => $tier,
          'holder'      => (string) ($decoded['holder'] ?? $decoded['organization'] ?? 'WSC Member'),
          'is_demo'     => !empty($decoded['demo']) || !empty($decoded['is_demo']),
          'is_agency'   => !empty($decoded['agency']) || in_array($tier, ['agency', 'founding_partner'], true),
          'verify_url'  => (string) ($decoded['verify_url'] ?? (self::PORTAL_BASE . '/verify/' . rawurlencode($license_key))),
          'co_branding' => [
            'name'  => (string) ($cb['agency_name'] ?? $co_branding['name'] ?? $existing_cb['name'] ?? ''),
            'url'   => (string) ($cb['agency_url'] ?? $co_branding['url'] ?? $existing_cb['url'] ?? ''),
            'email' => (string) ($cb['agency_email'] ?? $cb['support_email'] ?? $co_branding['email'] ?? $existing_cb['email'] ?? ''),
            'logo'  => (string) ($cb['agency_logo'] ?? $co_branding['logo'] ?? $existing_cb['logo'] ?? ''),
          ],
        ];
        $this->state->set('wsc_analytics.membership', $state_data);
        return array_merge(['ok' => true], $this->getMembershipState());
      }

      $this->state->set('wsc_analytics.membership', ['active' => false, 'status' => 'invalid', 'license_key' => $license_key]);
      return ['ok' => false, 'active' => false, 'error' => (string) ($decoded['message'] ?? $decoded['error'] ?? 'Invalid WSC credential.')];
    }
    catch (Exception $e) {
      if ($license_key === 'WSC-MEMBER-DEMO') {
        $this->state->set('wsc_analytics.membership', [
          'active'      => true,
          'status'      => 'active',
          'license_key' => $license_key,
          'tier'        => 'member_demo',
          'holder'      => 'WSC Demo Sandbox',
          'is_demo'     => true,
          'is_agency'   => false,
          'verify_url'  => self::PORTAL_BASE . '/verify?key=WSC-MEMBER-DEMO',
          'co_branding' => ['name' => '', 'url' => '', 'email' => '', 'logo' => ''],
        ]);
        return array_merge(['ok' => true], $this->getMembershipState());
      }
      return ['ok' => false, 'active' => false, 'error' => 'Portal unreachable: ' . $e->getMessage()];
    }
  }

  public function disconnectLicense(): array {
    $this->state->set('wsc_analytics.membership', ['active' => false, 'status' => 'inactive', 'license_key' => '']);
    return array_merge(['ok' => true], $this->getMembershipState());
  }

  /**
   * Fetch Featured Certified WSC Partners (ONLY when unlicensed — 100% ad-free for active members).
   */
  public function getFeaturedPartners(): array {
    if ($this->isActiveLicensed()) {
      return [];
    }

    $cached = $this->state->get('wsc_analytics.partners_cache', null);
    if (is_array($cached) && (time() - (int) ($cached['ts'] ?? 0)) < 43200) {
      return $cached['partners'] ?? [];
    }

    try {
      $res = $this->httpClient->request('GET', self::PORTAL_BASE . '/api/v1/partners/featured?platform=drupal', ['timeout' => 6.0]);
      if ($res->getStatusCode() === 200) {
        $decoded = json_decode((string) $res->getBody(), true);
        $partners = $decoded['partners'] ?? (is_array($decoded) ? $decoded : []);
        if (is_array($partners)) {
          $this->state->set('wsc_analytics.partners_cache', ['ts' => time(), 'partners' => $partners]);
          return $partners;
        }
      }
    }
    catch (Exception $e) {
      // Silent fallback
    }

    return [];
  }

  /**
   * Query Dynamic Industry Benchmarks (/v1/benchmarks/query) with 6h cache & offline vertical baseline.
   */
  public function getIndustryBenchmarks(bool $force_refresh = false): array {
    $config = $this->configFactory->get('wsc_analytics.settings');
    $vertical = (string) ($config->get('industry_vertical') ?: '/Business & Industrial/Services');

    if (!$force_refresh) {
      $cached = $this->state->get('wsc_analytics.benchmarks_cache', null);
      if (is_array($cached) && ($cached['vertical'] ?? '') === $vertical && (time() - (int) ($cached['ts'] ?? 0)) < 21600) {
        return $cached['data'];
      }
    }

    $cards = $this->getDashboardCards();
    $c1 = $cards['card1_traffic']['data_7d'] ?? [];
    $c3 = $cards['card3_bots']['data_7d'] ?? [];
    $ragHits = (float) ($c3['ai_rag_hits'] ?? 0);
    $totalBots = max(1.0, $ragHits + (float) ($c3['ai_training_hits'] ?? 0) + (float) ($c3['search_hits'] ?? 0));
    $siteRagShare = round(($ragHits / $totalBots) * 100, 1);
    $siteEngaged = (float) preg_replace('/[^0-9.]/', '', (string) ($c1['avg_engaged_time'] ?? '44')) ?: 44.0;
    $siteCov = min(100.0, round(30.0 + ($ragHits > 0 ? min(62.0, log($ragHits + 1) * 15.0) : 0.0), 1));

    $creds = $this->getProjectCredentials();
    if ($creds['hybrid_enabled'] && !empty($creds['api_secret']) && strpos($creds['project_id'], 'proj_') === 0) {
      $body = json_encode([
        'project_id'           => $creds['project_id'],
        'vertical'             => $vertical,
        'period_days'          => 30,
        'site_ai_rag_share'    => $siteRagShare,
        'site_engaged_time'    => $siteEngaged,
        'site_ai_coverage_pct' => $siteCov,
      ], JSON_UNESCAPED_SLASHES);
      $sig = hash_hmac('sha256', $body, $creds['api_secret']);

      try {
        $res = $this->httpClient->request('POST', $this->getGatewayUrl() . '/v1/benchmarks/query', [
          'headers' => ['Content-Type' => 'application/json', 'X-WSC-Sig' => $sig],
          'body'    => $body,
          'timeout' => 10.0,
        ]);
        if ($res->getStatusCode() === 200) {
          $live = json_decode((string) $res->getBody(), true);
          if (is_array($live)) {
            if (!empty($live['metrics']) && is_array($live['metrics'])) {
              foreach ($live['metrics'] as $mk => $mv) {
                if (is_array($mv)) {
                  if (!isset($mv['site']) && isset($mv['site_val'])) {
                    $live['metrics'][$mk]['site'] = $mv['site_val'];
                  }
                  if (!isset($mv['peer_median']) && isset($mv['industry_median'])) {
                    $live['metrics'][$mk]['peer_median'] = $mv['industry_median'];
                  }
                }
              }
            }
            $live['ok'] = true;
            $live['vertical'] = $vertical;
            $live['source'] = 'gateway_live';
            $this->state->set('wsc_analytics.benchmarks_cache', ['ts' => time(), 'vertical' => $vertical, 'data' => $live]);
            return $live;
          }
        }
      }
      catch (Exception $e) {
        // Fallback below
      }
    }

    $result = [
      'ok'          => true,
      'source'      => 'local_baseline',
      'vertical'    => $vertical,
      'period_days' => 30,
      'metrics'     => [
        'ai_rag_share'    => ['site' => $siteRagShare, 'peer_median' => 24.0, 'unit' => '%'],
        'engaged_time'    => ['site' => $siteEngaged, 'peer_median' => 58.0, 'unit' => 's'],
        'ai_coverage_pct' => ['site' => $siteCov, 'peer_median' => 61.0, 'unit' => '%'],
      ],
    ];
    $this->state->set('wsc_analytics.benchmarks_cache', ['ts' => time(), 'vertical' => $vertical, 'data' => $result]);
    return $result;
  }

  /**
   * Auto-classify Drupal site vertical via Gateway (/v1/benchmarks/classify) or local keywords.
   */
  public function classifyBenchmarkVertical(): array {
    $site_cfg = $this->configFactory->get('system.site');
    $title = (string) ($site_cfg->get('name') ?: 'Drupal Site');
    $slogan = (string) ($site_cfg->get('slogan') ?: '');
    $vertical = '/Business & Industrial/Services';

    $creds = $this->getProjectCredentials();
    if ($creds['hybrid_enabled'] && !empty($creds['api_secret']) && strpos($creds['project_id'], 'proj_') === 0) {
      $body = json_encode([
        'project_id'       => $creds['project_id'],
        'title'            => $title,
        'meta_description' => $slogan,
        'keywords'         => 'drupal',
        'sample_text'      => $title . ' ' . $slogan,
      ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      $sig = hash_hmac('sha256', $body, $creds['api_secret']);

      try {
        $res = $this->httpClient->request('POST', $this->getGatewayUrl() . '/v1/benchmarks/classify', [
          'headers' => ['Content-Type' => 'application/json', 'X-WSC-Sig' => $sig],
          'body'    => $body,
          'timeout' => 8.0,
        ]);
        if ($res->getStatusCode() === 200) {
          $d = json_decode((string) $res->getBody(), true);
          if (!empty($d['vertical'])) {
            $vertical = (string) $d['vertical'];
          }
        }
      }
      catch (Exception $e) {
        // Fallback below
      }
    }
    else {
      $hay = strtolower($title . ' ' . $slogan);
      if (preg_match('/(shop|commerce|store|product)/', $hay)) $vertical = '/Shopping/Apparel';
      elseif (preg_match('/(health|clinic|medical|hospital)/', $hay)) $vertical = '/Health';
      elseif (preg_match('/(news|press|media|journal)/', $hay)) $vertical = '/News';
      elseif (preg_match('/(software|cloud|tech|ai)/', $hay)) $vertical = '/Computers & Electronics';
    }

    $this->configFactory->getEditable('wsc_analytics.settings')->set('industry_vertical', $vertical)->save();
    return ['ok' => true, 'vertical' => $vertical, 'benchmarks' => $this->getIndustryBenchmarks(true)];
  }

  /**
   * Generate machine-readable Markdown /llms.txt AI Manifest for published Drupal nodes.
   */
  public function generateLlmsTxt(): string {
    $site_cfg = $this->configFactory->get('system.site');
    $wsc_cfg = $this->configFactory->get('wsc_analytics.settings');
    $name = (string) ($site_cfg->get('name') ?: 'Drupal Website');
    $summary = trim((string) ($wsc_cfg->get('llms_txt_summary') ?: $site_cfg->get('slogan') ?: 'Official structured Drupal content index for AI retrieval agents.'));
    $base_url = $this->resolveBaseUrl();

    $lines = [
      '# ' . $name,
      '',
      '> ' . $summary,
      '',
      '## Canonical Endpoints',
      '- [Homepage](' . $base_url . '/)',
      '- [XML Sitemap](' . $base_url . '/sitemap.xml)',
      '',
      '## Published Content',
    ];

    try {
      if (\Drupal::entityTypeManager()->hasDefinition('node')) {
        $nids = \Drupal::entityQuery('node')
          ->accessCheck(true)
          ->condition('status', 1)
          ->sort('changed', 'DESC')
          ->range(0, 25)
          ->execute();

        if (!empty($nids)) {
          $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids);
          foreach ($nodes as $node) {
            $url = $node->toUrl('canonical', ['absolute' => true])->toString();
            $lines[] = '- [' . $node->label() . '](' . $url . ')';
          }
        }
      }
    }
    catch (Throwable $e) {
      $lines[] = '- [Primary Content](' . $base_url . '/)';
    }

    $lines[] = '';
    $lines[] = '---';
    $lines[] = 'Generated by WSC Analytics & Search Health for Drupal v' . self::VERSION . ' (' . self::PORTAL_BASE . ')';

    return implode("\n", $lines) . "\n";
  }

  /**
   * Submit changed Drupal URLs to IndexNow (https://api.indexnow.org/indexnow).
   */
  public function submitIndexNow(array $urls, string $source = 'manual'): array {
    $config = $this->configFactory->get('wsc_analytics.settings');
    if ($source === 'auto' && !$config->get('indexnow_enabled')) {
      return ['ok' => false, 'skipped' => true, 'reason' => 'indexnow_disabled'];
    }

    $creds = $this->getProjectCredentials();
    $key = $creds['indexnow_key'];
    $base_url = $this->resolveBaseUrl();
    $site_host = $this->resolveSiteHost();
    $key_location = $this->ensureIndexNowKeyFile($key);

    $clean_urls = [];
    foreach ($urls as $u) {
      $u = trim((string) $u);
      if ($u === '') continue;
      if (strpos($u, 'http') !== 0) {
        $u = $base_url . '/' . ltrim($u, '/');
      }
      if (strtolower((string) parse_url($u, PHP_URL_HOST)) === $site_host) {
        $clean_urls[] = $u;
      }
    }
    $clean_urls = array_values(array_unique($clean_urls));
    if (empty($clean_urls)) {
      return ['ok' => false, 'error' => 'No valid same-host URLs provided.'];
    }

    $status_code = 0;
    $ok = false;
    $err = '';

    try {
      $res = $this->httpClient->request('POST', self::INDEXNOW_ENDPOINT, [
        'json' => [
          'host'        => $site_host,
          'key'         => $key,
          'keyLocation' => $key_location,
          'urlList'     => $clean_urls,
        ],
        'timeout' => ($source === 'auto') ? 3.0 : 10.0,
      ]);
      $status_code = $res->getStatusCode();
      $ok = in_array($status_code, [200, 202], true);
    }
    catch (Exception $e) {
      $err = $e->getMessage();
    }

    $logs = $this->state->get('wsc_analytics.indexnow_logs', []) ?: [];
    array_unshift($logs, [
      'timestamp' => gmdate('Y-m-d H:i:s') . ' UTC',
      'source'    => $source,
      'urls'      => $clean_urls,
      'status'    => $status_code,
      'ok'        => $ok,
      'error'     => $err,
    ]);
    $logs = array_slice($logs, 0, 20);
    $this->state->set('wsc_analytics.indexnow_logs', $logs);

    return [
      'ok'          => $ok,
      'http_status' => $status_code,
      'submitted'   => count($clean_urls),
      'error'       => $err,
      'logs'        => $logs,
    ];
  }

  public function getIndexNowLogs(): array {
    return $this->state->get('wsc_analytics.indexnow_logs', []) ?: [];
  }

  /**
   * Retention cleanup and SQLite optimization.
   */
  public function cleanupOldData(): void {
    $config = $this->configFactory->get('wsc_analytics.settings');
    $retention_days = max(1, (int) ($config->get('retention_days') ?: 30));
    $threshold = time() - ($retention_days * 86400);

    try {
      $queue_pdo = $this->dbManager->getQueuePdo();
      $stmt = $queue_pdo->prepare("DELETE FROM wsc_events_queue WHERE synced = 1 AND created_at < :t");
      $stmt->execute([':t' => $threshold]);

      $this->dbManager->optimizeDatabases();
    }
    catch (Exception $e) {
      $this->logger->error('WSC Cleanup error: @msg', ['@msg' => $e->getMessage()]);
    }
  }
}
