<?php

namespace Drupal\wsc_analytics\StackMiddleware;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Pre-PageCache StackMiddleware (priority 250) capturing AI RAG, AI Training,
 * and Search Engine crawler hits even when Drupal's Internal Page Cache serves
 * cached HTML prior to KernelPreHandle.
 */
class WscBotShieldMiddleware implements HttpKernelInterface {

  /**
   * The wrapped HTTP kernel.
   *
   * @var \Symfony\Component\HttpKernel\HttpKernelInterface
   */
  protected $httpKernel;

  /**
   * The Symfony service container.
   *
   * @var \Symfony\Component\DependencyInjection\ContainerInterface
   */
  protected $container;

  /**
   * Constructs WscBotShieldMiddleware.
   */
  public function __construct(HttpKernelInterface $http_kernel, ContainerInterface $container) {
    $this->httpKernel = $http_kernel;
    $this->container = $container;
  }

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, int $type = 1, bool $catch = true): Response {
    $response = $this->httpKernel->handle($request, $type, $catch);

    if ($type !== 1 || $request->attributes->get('_wsc_bot_tracked')) {
      return $response;
    }

    $ua = (string) $request->headers->get('User-Agent', '');
    if ($ua === '') {
      return $response;
    }

    $path = $request->getPathInfo();
    if (strpos($path, '/wsc/v1/') === 0 || strpos($path, '/api/wsc/') === 0) {
      return $response;
    }

    $pushedRequest = false;
    $requestStack = null;

    try {
      if (!$this->container->has('wsc_analytics.bot_tracker') || !$this->container->has('wsc_analytics.core')) {
        return $response;
      }

      /** @var \Drupal\wsc_analytics\Service\WscBotTracker $botTracker */
      $botTracker = $this->container->get('wsc_analytics.bot_tracker');
      $bot = $botTracker->detectBot($ua);
      if ($bot === null) {
        return $response;
      }

      if (!\Drupal::hasContainer()) {
        \Drupal::setContainer($this->container);
      }

      if ($this->container->has('request_stack')) {
        $requestStack = $this->container->get('request_stack');
        if ($requestStack->getCurrentRequest() === null) {
          $requestStack->push($request);
          $pushedRequest = true;
        }
      }

      $config = $this->container->get('config.factory')->get('wsc_analytics.settings');
      if (!$config->get('tracking_active')) {
        return $response;
      }

      $level = (string) ($config->get('bot_tracking_level') ?: 'search_ai');
      if ($level === 'off' || ($level === 'ai_only' && ($bot['category'] ?? '') !== 'ai')) {
        return $response;
      }

      /** @var \Drupal\wsc_analytics\Service\WscCoreService $core */
      $core = $this->container->get('wsc_analytics.core');
      $request->attributes->set('_wsc_bot_tracked', true);

      $core->trackEvent(
        'bot_hit',
        $path ?: '/',
        (string) ($request->headers->get('Referer') ?: ''),
        [
          'bot_name'     => $bot['name'],
          'bot_category' => $bot['category'],
          'bot_type'     => $bot['type'],
          'status_code'  => $response->getStatusCode(),
          'ip_hash'      => $core->hashClientIp($request->getClientIp(), $ua),
        ]
      );
    }
    catch (\Throwable $e) {
      // Never interrupt response delivery on telemetry error.
    }
    finally {
      if ($pushedRequest && $requestStack !== null) {
        $requestStack->pop();
      }
    }

    return $response;
  }

}
