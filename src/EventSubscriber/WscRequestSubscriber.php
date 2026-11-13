<?php

namespace Drupal\wsc_analytics\EventSubscriber;

use Drupal\wsc_analytics\Service\WscCoreService;
use Drupal\wsc_analytics\Service\WscBotTracker;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Kernel request subscriber for virtual /{indexnow_key}.txt verification
 * and real-time Zero-PII edge crawler & AI bot detection in Drupal 8–11 (v2.2.3).
 */
class WscRequestSubscriber implements EventSubscriberInterface {

  /** @var \Drupal\wsc_analytics\Service\WscCoreService */
  protected $core;

  /** @var \Drupal\wsc_analytics\Service\WscBotTracker */
  protected $botTracker;

  /** @var \Drupal\Core\Config\ConfigFactoryInterface */
  protected $configFactory;

  /** @var array|null */
  protected $detectedBot = null;

  public function __construct(
    WscCoreService $core,
    WscBotTracker $bot_tracker,
    ConfigFactoryInterface $config_factory
  ) {
    $this->core = $core;
    $this->botTracker = $bot_tracker;
    $this->configFactory = $config_factory;
  }

  /**
   * Serve virtual /{indexnow_key}.txt and identify AI/RAG/Search crawlers.
   */
  public function onKernelRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    $path = $request->getPathInfo();

    // 1. Virtual IndexNow verification file /{32-hex}.txt
    if (preg_match('#^/([a-f0-9]{32})\.txt$#i', $path, $m)) {
      $creds = $this->core->getProjectCredentials();
      if (!empty($creds['indexnow_key']) && strcasecmp($m[1], $creds['indexnow_key']) === 0) {
        $event->setResponse(new Response($creds['indexnow_key'], 200, [
          'Content-Type'  => 'text/plain; charset=utf-8',
          'Cache-Control' => 'public, max-age=86400',
        ]));
        return;
      }
    }

    // 2. Zero-PII Bot Shield sensor
    $config = $this->configFactory->get('wsc_analytics.settings');
    if (!$config->get('tracking_active')) {
      return;
    }

    $level = $config->get('bot_tracking_level') ?: 'search_ai';
    if ($level === 'off') {
      return;
    }

    $user_agent = $request->headers->get('User-Agent') ?: '';
    $bot = $this->botTracker->detectBot($user_agent);
    if (!$bot) {
      return;
    }

    if ($level === 'ai_only' && ($bot['category'] ?? '') !== 'ai') {
      return;
    }

    $this->detectedBot = [
      'bot_name'     => $bot['name'],
      'bot_category' => $bot['category'],
      'bot_type'     => $bot['type'],
      'path'         => $path,
      'referrer'     => $request->headers->get('Referer') ?: '',
      'ip_hash'      => $this->core->hashClientIp($request->getClientIp(), $user_agent),
    ];
  }

  /**
   * Record bot_hit with HTTP status code when response is ready (Zero-PII).
   */
  public function onKernelResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest() || !$this->detectedBot) {
      return;
    }

    $status_code = $event->getResponse()->getStatusCode();

    $this->core->trackEvent(
      'bot_hit',
      $this->detectedBot['path'],
      $this->detectedBot['referrer'],
      [
        'bot_name'     => $this->detectedBot['bot_name'],
        'bot_category' => $this->detectedBot['bot_category'],
        'bot_type'     => $this->detectedBot['bot_type'],
        'status_code'  => $status_code,
        'ip_hash'      => $this->detectedBot['ip_hash'],
      ]
    );

    $this->detectedBot = null;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST  => ['onKernelRequest', 250],
      KernelEvents::RESPONSE => ['onKernelResponse', -100],
    ];
  }
}
