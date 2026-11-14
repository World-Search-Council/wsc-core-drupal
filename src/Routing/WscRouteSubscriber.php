<?php

namespace Drupal\wsc_analytics\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Dynamically registers /llms.txt only if no other module (e.g. wsc_member) already owns /llms.txt.
 */
class WscRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach ($collection->all() as $existing_route) {
      if ($existing_route->getPath() === '/llms.txt') {
        return;
      }
    }

    $route = new Route(
      '/llms.txt',
      [
        '_controller' => '\Drupal\wsc_analytics\Controller\WscApiController::serveLlmsTxt',
      ],
      [
        '_access' => 'TRUE',
      ],
      [],
      '',
      [],
      ['GET', 'HEAD']
    );

    $collection->add('wsc_analytics.llms_txt', $route);
  }

}
