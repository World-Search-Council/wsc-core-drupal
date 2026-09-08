<?php

namespace Drupal\wsc_analytics\EventSubscriber;

use Drupal\wsc_analytics\Service\WscCoreService;
use Drupal\wsc_analytics\Service\WscBotTracker;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Kernel request subscriber for real-time edge crawler & AI bot detection in Drupal.
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
   * Listen to incoming request and identify crawlers.
   */
  public function onKernelRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $config = $this->configFactory->get('wsc_analytics.settings');
    $level = $config->get('bot_tracking_level') ?: 'search_ai';
    if ($level === 'off') {
      return;
    }

    $request = $event->getRequest();
    $user_agent = $request->headers->get('User-Agent') ?: '';

    $bot = $this->botTracker->detectBot($user_agent);
    if (!$bot) {
      return;
    }

    // Filter by configured bot level
    if ($level === 'ai_only' && $bot['category'] !== 'ai') {
      return;
    }

    $this->detectedBot = [
      'bot_name'     => $bot['name'],
      'bot_category' => $bot['category'],
      'bot_type'     => $bot['type'],
      'path'         => $request->getPathInfo(),
      'referrer'     => $request->headers->get('Referer') ?: '',
      'ip'           => $request->getClientIp(),
    ];
  }

  /**
   * Record bot_hit with HTTP status code when response is ready.
   */
  public function onKernelResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest() || !$this->detectedBot) {
      return;
    }

    $response = $event->getResponse();
    $status_code = $response->getStatusCode();

    $meta = [
      'bot_name'     => $this->detectedBot['bot_name'],
      'bot_category' => $this->detectedBot['bot_category'],
      'bot_type'     => $this->detectedBot['bot_type'],
      'status_code'  => $status_code,
      'ip'           => $this->detectedBot['ip'],
    ];

    $this->core->trackEvent(
      'bot_hit',
      $this->detectedBot['path'],
      $this->detectedBot['referrer'],
      $meta
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
