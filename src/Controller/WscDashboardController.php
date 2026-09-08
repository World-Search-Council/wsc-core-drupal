<?php

namespace Drupal\wsc_analytics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\State\StateInterface;
use Drupal\wsc_analytics\Service\WscCoreService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for WSC 3-Card Analytics Dashboard in Drupal admin.
 */
class WscDashboardController extends ControllerBase {

  /** @var \Drupal\wsc_analytics\Service\WscCoreService */
  protected $core;

  /** @var \Drupal\Core\State\StateInterface */
  protected $state;

  public function __construct(WscCoreService $core, StateInterface $state) {
    $this->core = $core;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('wsc_analytics.core'),
      $container->get('state')
    );
  }

  /**
   * Render the 7-Card Bento Analytics Dashboard.
   */
  public function renderDashboard(): array {
    $cards = $this->core->getDashboardCards();
    $creds = $this->core->getProjectCredentials();
    $last_sync = $this->state->get('wsc_analytics.last_sync', 0);

    $has_data = !empty($cards['card1'])
      || !empty($cards['card2'])
      || !empty($cards['card3'])
      || !empty($cards['card_engagement'])
      || !empty($cards['card_outbound'])
      || !empty($cards['card_ecommerce'])
      || !empty($cards['domain_health']);

    $request = \Drupal::request();
    $site_host = $request->getHost();
    $site_url = $request->getSchemeAndHttpHost();

    return [
      '#theme' => 'wsc_dashboard',
      '#card1' => $cards['card1_traffic'] ?? $cards['card1'] ?? [],
      '#card2' => $cards['card2_insights'] ?? $cards['card2'] ?? [],
      '#card3' => $cards['card3_bots'] ?? $cards['card3'] ?? [],
      '#card_engagement' => $cards['card_engagement'] ?? [],
      '#card_outbound' => $cards['card_outbound'] ?? [],
      '#card_ecommerce' => $cards['card_ecommerce'] ?? [],
      '#domain_health' => $cards['domain_health'] ?? [],
      '#pagespeed' => $cards['pagespeed'] ?? [],
      '#project_id' => $creds['project_id'],
      '#last_sync' => $last_sync ? date('Y-m-d H:i:s', $last_sync) : t('Never'),
      '#has_data' => $has_data,
      '#site_host' => $site_host,
      '#site_url' => $site_url,
      '#attached' => [
        'library' => [
          'wsc_analytics/dashboard',
        ],
        'drupalSettings' => [
          'wscDashboard' => [
            'cards' => $cards,
            'card1' => $cards['card1_traffic'] ?? $cards['card1'] ?? null,
            'card2' => $cards['card2_insights'] ?? $cards['card2'] ?? null,
            'card3' => $cards['card3_bots'] ?? $cards['card3'] ?? null,
            'cardEngagement' => $cards['card_engagement'] ?? null,
            'cardOutbound' => $cards['card_outbound'] ?? null,
            'cardEcommerce' => $cards['card_ecommerce'] ?? null,
            'domainHealth' => $cards['domain_health'] ?? null,
            'pagespeed' => $cards['pagespeed'] ?? null,
            'projectId' => $creds['project_id'],
            'refreshUrl' => '/wsc/v1/refresh',
            'cardsUrl' => '/wsc/v1/cards',
            'pagespeedUrl' => '/wsc/v1/pagespeed/audit',
            'sentinelUrl' => '/wsc/v1/sentinel/scan',
            'siteHost' => $site_host,
            'siteUrl' => $site_url,
          ],
        ],
      ],
    ];
  }

  /**
   * AJAX handler for manual sync trigger.
   */
  public function ajaxRefresh(Request $request): JsonResponse {
    $synced = $this->core->processBatchExport();
    return new JsonResponse([
      'ok' => true,
      'synced_events' => $synced,
      'last_sync' => date('Y-m-d H:i:s'),
    ]);
  }
}
