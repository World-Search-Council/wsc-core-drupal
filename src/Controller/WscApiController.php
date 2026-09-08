<?php

namespace Drupal\wsc_analytics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\wsc_analytics\Service\WscCoreService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for client-side telemetry ingestion and gateway webhook callbacks.
 */
class WscApiController extends ControllerBase {

  /** @var \Drupal\wsc_analytics\Service\WscCoreService */
  protected $core;

  public function __construct(WscCoreService $core) {
    $this->core = $core;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('wsc_analytics.core')
    );
  }

  /**
   * Ingest client-side micro-batch telemetry from wsc-tracker.js.
   */
  public function track(Request $request): JsonResponse {
    $raw_content = $request->getContent();
    if (empty($raw_content)) {
      return new JsonResponse(['ok' => false, 'error' => 'empty_payload'], 400);
    }

    $data = json_decode($raw_content, true);
    if (!is_array($data) || empty($data['events']) || !is_array($data['events'])) {
      return new JsonResponse(['ok' => false, 'error' => 'invalid_format'], 400);
    }

    $ip = $request->getClientIp();
    $ua = $request->headers->get('User-Agent') ?: '';
    $ch_brand = $request->headers->get('Sec-CH-UA') ?: '';
    $ch_mobile = $request->headers->get('Sec-CH-UA-Mobile') ?: '';
    $ch_platform = $request->headers->get('Sec-CH-UA-Platform') ?: '';

    $count = 0;
    foreach ($data['events'] as $item) {
      if (empty($item['e'])) {
        continue;
      }

      $event_type = (string) $item['e'];
      $url_path   = (string) ($item['p'] ?? '/');
      $referrer   = (string) ($item['r'] ?? '');
      $meta       = is_array($item['m'] ?? null) ? $item['m'] : [];

      // Enrich meta with client environment headers
      $meta['ip']          = $ip;
      $meta['user_agent']  = $ua;
      if ($ch_brand)    $meta['sec_ch_ua'] = $ch_brand;
      if ($ch_mobile)   $meta['sec_ch_ua_mobile'] = $ch_mobile;
      if ($ch_platform) $meta['sec_ch_ua_platform'] = $ch_platform;

      if ($this->core->trackEvent($event_type, $url_path, $referrer, $meta)) {
        $count++;
      }
    }

    return new JsonResponse(['ok' => true, 'queued' => $count]);
  }

  /**
   * Handle incoming 3-Card reports webhook from WSC Gateway.
   */
  public function handleWebhook(Request $request): JsonResponse {
    $raw_content = $request->getContent();
    $signature   = $request->headers->get('X-WSC-Sig') ?: '';

    $creds = $this->core->getProjectCredentials();

    // Verify HMAC-SHA256 signature
    $expected_sig = hash_hmac('sha256', $raw_content, $creds['local_key']);
    if (!hash_equals($expected_sig, $signature)) {
      return new JsonResponse(['ok' => false, 'error' => 'invalid_signature'], 403);
    }

    $data = json_decode($raw_content, true);
    if (!is_array($data)) {
      return new JsonResponse(['ok' => false, 'error' => 'invalid_json'], 400);
    }

    $saved = $this->core->saveWebhookReport($data);

    return new JsonResponse(['ok' => $saved]);
  }

  /**
   * Proxies AI Coverage / URL Inspection queries to WSC Gateway.
   */
  public function aiCoverage(Request $request): JsonResponse {
    $raw_content = $request->getContent();
    $data = json_decode($raw_content, true);

    $query_url = trim((string) ($data['query'] ?? ''));
    $timeframe = trim((string) ($data['timeframe'] ?? '30d'));

    if (empty($query_url)) {
      return new JsonResponse(['ok' => false, 'error' => 'query_required'], 400);
    }

    $creds = $this->core->getProjectCredentials();
    if (empty($creds['project_id']) || empty($creds['api_secret'])) {
      return new JsonResponse(['ok' => false, 'error' => 'not_configured'], 400);
    }

    $payload = json_encode([
      'project_id' => $creds['project_id'],
      'query'      => $query_url,
      'timeframe'  => in_array($timeframe, ['7d', '30d'], true) ? $timeframe : '30d',
    ]);

    $signature = hash_hmac('sha256', $payload, $creds['api_secret']);

    try {
      $client = \Drupal::httpClient();
      $response = $client->post('https://gateway.worldseocouncil.com/v1/ai-coverage/query', [
        'headers' => [
          'Content-Type' => 'application/json',
          'X-WSC-Sig'    => $signature,
        ],
        'body'    => $payload,
        'timeout' => 10,
      ]);

      $result = json_decode((string) $response->getBody(), true);
      return new JsonResponse($result);
    }
    catch (\Exception $e) {
      return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 502);
    }
  }

  /**
   * Get current cached 7-Card Suite as JSON for frontend updates.
   */
  public function getCards(): JsonResponse {
    $cards = $this->core->getDashboardCards();
    return new JsonResponse(['ok' => true, 'cards' => $cards]);
  }

  /**
   * Trigger SpeedSentinel 2.0 Lab Audit.
   */
  public function triggerPagespeedAudit(Request $request): JsonResponse {
    $raw_content = $request->getContent();
    $data = json_decode($raw_content, true) ?: [];

    $url = trim((string) ($data['url'] ?? $request->getSchemeAndHttpHost()));
    $strategy = in_array($data['strategy'] ?? '', ['mobile', 'desktop'], true) ? $data['strategy'] : 'mobile';

    $result = $this->core->triggerSpeedSentinelAudit($url, $strategy);
    return new JsonResponse($result, ($result['ok'] ?? false) ? 202 : 400);
  }

  /**
   * Trigger Sentinel Domain Health Scan.
   */
  public function triggerSentinelScan(Request $request): JsonResponse {
    $raw_content = $request->getContent();
    $data = json_decode($raw_content, true) ?: [];

    $domain = trim((string) ($data['domain'] ?? $request->getHost()));

    $result = $this->core->triggerSentinelScan($domain);
    return new JsonResponse($result, ($result['ok'] ?? false) ? 202 : 400);
  }
}

