<?php

namespace Drupal\wsc_analytics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\wsc_analytics\Service\WscCoreService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for Zero-PII telemetry ingestion, Gateway webhooks, AI Coverage,
 * Benchmarks, WSC Membership, /llms.txt, and IndexNow in Drupal 8–11 (v2.2.3).
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
   * Public machine-readable /llms.txt AI Manifest endpoint.
   */
  public function serveLlmsTxt(): Response {
    $config = $this->config('wsc_analytics.settings');
    if (!$config->get('llms_txt_enabled')) {
      return new Response('404 Not Found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    return new Response($this->core->generateLlmsTxt(), 200, [
      'Content-Type'  => 'text/markdown; charset=utf-8',
      'Cache-Control' => 'public, max-age=3600',
    ]);
  }

  /**
   * Ingest client-side micro-batch telemetry from wsc-tracker.js (Zero-PII).
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

    $raw_ip = $request->getClientIp();
    $ua = $request->headers->get('User-Agent') ?: '';
    $ip_hash = $this->core->hashClientIp($raw_ip, $ua);

    $count = 0;
    foreach ($data['events'] as $item) {
      if (empty($item['e'])) {
        continue;
      }

      $event_type = (string) $item['e'];
      $url_path   = (string) ($item['p'] ?? '/');
      $referrer   = (string) ($item['r'] ?? '');
      $meta       = is_array($item['m'] ?? null) ? $item['m'] : [];

      unset($meta['ip']);
      $meta['ip_hash'] = $ip_hash;
      $meta['user_agent'] = substr($ua, 0, 255);

      if ($this->core->trackEvent($event_type, $url_path, $referrer, $meta)) {
        $count++;
      }
    }

    return new JsonResponse(['ok' => true, 'queued' => $count]);
  }

  /**
   * Handle incoming 7-Card reports webhook from WSC Gateway.
   */
  public function handleWebhook(Request $request): JsonResponse {
    $raw_content = (string) $request->getContent();
    $signature   = $request->headers->get('X-WSC-Sig') ?: '';

    $creds = $this->core->getProjectCredentials();
    $secret = !empty($creds['api_secret']) ? $creds['api_secret'] : $creds['local_key'];
    $expected_sig = hash_hmac('sha256', $raw_content, $secret);

    if ($signature === '' || !hash_equals($expected_sig, $signature)) {
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
   * Query AI Coverage URL Inspector (Gateway + local SQLite fallback).
   */
  public function aiCoverage(Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), true) ?: [];
    $query_url = trim((string) ($data['query'] ?? ''));
    $timeframe = trim((string) ($data['timeframe'] ?? '30d'));

    if ($query_url === '') {
      return new JsonResponse(['ok' => false, 'error' => 'query_required'], 400);
    }

    return new JsonResponse($this->core->queryAiCoverage($query_url, $timeframe));
  }

  public function getCards(): JsonResponse {
    $this->core->pullRemoteReportsIfPendingScan();
    return new JsonResponse(['ok' => true, 'cards' => $this->core->getDashboardCards()]);
  }

  public function triggerPagespeedAudit(Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), true) ?: [];
    $url = trim((string) ($data['url'] ?? $request->getSchemeAndHttpHost()));
    $strategy = in_array($data['strategy'] ?? '', ['mobile', 'desktop'], true) ? $data['strategy'] : 'mobile';

    $result = $this->core->triggerSpeedSentinelAudit($url, $strategy);
    return new JsonResponse($result, !empty($result['ok']) ? 202 : 400);
  }

  public function triggerSentinelScan(Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), true) ?: [];
    $domain = trim((string) ($data['domain'] ?? $request->getHost()));

    $result = $this->core->triggerSentinelScan($domain);
    return new JsonResponse($result, !empty($result['ok']) ? 202 : 400);
  }

  public function getBenchmarks(): JsonResponse {
    return new JsonResponse($this->core->getIndustryBenchmarks(true));
  }

  public function classifyBenchmark(): JsonResponse {
    return new JsonResponse($this->core->classifyBenchmarkVertical());
  }

  public function verifyLicense(Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), true) ?: [];
    $key = trim((string) ($data['license_key'] ?? ''));
    $cb = is_array($data['co_branding'] ?? null) ? $data['co_branding'] : null;
    $res = $this->core->verifyLicense($key, $cb);
    return new JsonResponse($res, !empty($res['ok']) ? 200 : 400);
  }

  public function disconnectLicense(): JsonResponse {
    return new JsonResponse($this->core->disconnectLicense());
  }

  public function saveCoBranding(Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), true) ?: [];
    $state = $this->core->getMembershipState();
    $res = $this->core->verifyLicense($state['license_key'], [
      'name'  => $data['agency_name'] ?? '',
      'url'   => $data['agency_url'] ?? '',
      'email' => $data['agency_email'] ?? '',
      'logo'  => $data['agency_logo'] ?? '',
    ]);
    return new JsonResponse($res, !empty($res['ok']) ? 200 : 400);
  }

  public function submitIndexNow(Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), true) ?: [];
    $url = trim((string) ($data['url'] ?? $request->getSchemeAndHttpHost()));
    $res = $this->core->submitIndexNow([$url], 'manual');
    return new JsonResponse($res, !empty($res['ok']) ? 200 : 400);
  }
}
