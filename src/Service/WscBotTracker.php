<?php

namespace Drupal\wsc_analytics\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * High-performance bot identification service with AI/LLM crawler taxonomy.
 */
class WscBotTracker {

  /** @var \Drupal\Core\Config\ConfigFactoryInterface */
  protected $configFactory;

  /** @var \Drupal\Core\Logger\LoggerChannelInterface */
  protected $logger;

  /**
   * Known bot definitions with taxonomy.
   */
  private const BOT_TAXONOMY = [
    // 1. AI Live RAG & User-driven Prompt Search Bots
    'ChatGPT-User'          => ['name' => 'ChatGPT-User', 'category' => 'ai', 'type' => 'rag'],
    'OAI-SearchBot'         => ['name' => 'OAI-SearchBot', 'category' => 'ai', 'type' => 'rag'],
    'Claude-User'           => ['name' => 'Claude-User', 'category' => 'ai', 'type' => 'rag'],
    'Claude-SearchBot'      => ['name' => 'Claude-SearchBot', 'category' => 'ai', 'type' => 'rag'],
    'Claude-Web'            => ['name' => 'Claude-Web', 'category' => 'ai', 'type' => 'rag'],
    'PerplexityBot'         => ['name' => 'PerplexityBot', 'category' => 'ai', 'type' => 'rag'],
    'Perplexity-User'       => ['name' => 'Perplexity-User', 'category' => 'ai', 'type' => 'rag'],
    'Gemini-Deep-Research'  => ['name' => 'Gemini-Deep-Research', 'category' => 'ai', 'type' => 'rag'],
    'YouBot'                => ['name' => 'YouBot', 'category' => 'ai', 'type' => 'rag'],

    // 2. AI Model Pre-Training & Offline Crawlers
    'GPTBot'                => ['name' => 'GPTBot', 'category' => 'ai', 'type' => 'training'],
    'ClaudeBot'             => ['name' => 'ClaudeBot', 'category' => 'ai', 'type' => 'training'],
    'CCBot'                 => ['name' => 'CCBot', 'category' => 'ai', 'type' => 'training'],
    'Bytespider'            => ['name' => 'Bytespider', 'category' => 'ai', 'type' => 'training'],
    'Google-Extended'       => ['name' => 'Google-Extended', 'category' => 'ai', 'type' => 'training'],
    'Meta-ExternalAgent'    => ['name' => 'Meta-ExternalAgent', 'category' => 'ai', 'type' => 'training'],
    'Meta-ExternalFetcher'  => ['name' => 'Meta-ExternalFetcher', 'category' => 'ai', 'type' => 'training'],
    'FacebookBot'           => ['name' => 'FacebookBot', 'category' => 'ai', 'type' => 'training'],
    'xAI-Bot'               => ['name' => 'xAI-Bot', 'category' => 'ai', 'type' => 'training'],
    'GrokBot'               => ['name' => 'GrokBot', 'category' => 'ai', 'type' => 'training'],
    'DeepSeekBot'           => ['name' => 'DeepSeekBot', 'category' => 'ai', 'type' => 'training'],
    'Amazonbot'             => ['name' => 'Amazonbot', 'category' => 'ai', 'type' => 'training'],
    'Cohere-AI'             => ['name' => 'Cohere-AI', 'category' => 'ai', 'type' => 'training'],
    'Diffbot'               => ['name' => 'Diffbot', 'category' => 'ai', 'type' => 'training'],
    'Applebot-Extended'     => ['name' => 'Applebot-Extended', 'category' => 'ai', 'type' => 'training'],
    'Scrapy'                => ['name' => 'Scrapy', 'category' => 'ai', 'type' => 'training'],

    // 3. Search Engine Crawlers (Traditional Organic Search)
    'Googlebot-Mobile'      => ['name' => 'Googlebot (Mobile)', 'category' => 'search', 'type' => 'search'],
    'Googlebot-Image'       => ['name' => 'Googlebot (Image)', 'category' => 'search', 'type' => 'search'],
    'Google-InspectionTool' => ['name' => 'Google Inspection Tool', 'category' => 'search', 'type' => 'search'],
    'GoogleOther'           => ['name' => 'GoogleOther', 'category' => 'search', 'type' => 'search'],
    'Googlebot'             => ['name' => 'Googlebot', 'category' => 'search', 'type' => 'search'],
    'Bingbot'               => ['name' => 'Bingbot', 'category' => 'search', 'type' => 'search'],
    'BingPreview'           => ['name' => 'BingPreview', 'category' => 'search', 'type' => 'search'],
    'DuckDuckBot'           => ['name' => 'DuckDuckBot', 'category' => 'search', 'type' => 'search'],
    'DuckDuckGo-Favicons-Bot' => ['name' => 'DuckDuckGo Favicons', 'category' => 'search', 'type' => 'search'],
    'YandexBot'             => ['name' => 'YandexBot', 'category' => 'search', 'type' => 'search'],
    'Baiduspider'           => ['name' => 'Baiduspider', 'category' => 'search', 'type' => 'search'],
    'Sogou'                 => ['name' => 'Sogou Spider', 'category' => 'search', 'type' => 'search'],
    'Exabot'                => ['name' => 'Exabot', 'category' => 'search', 'type' => 'search'],
    'Applebot'              => ['name' => 'Applebot', 'category' => 'search', 'type' => 'search'],
    'Qwantify'              => ['name' => 'Qwantify', 'category' => 'search', 'type' => 'search'],
    'archive.org_bot'       => ['name' => 'Internet Archive', 'category' => 'search', 'type' => 'search'],

    // 4. SEO & Auditing Tools
    'AhrefsBot'             => ['name' => 'AhrefsBot', 'category' => 'seo_tool', 'type' => 'seo_tool'],
    'AhrefsSiteAudit'       => ['name' => 'AhrefsSiteAudit', 'category' => 'seo_tool', 'type' => 'seo_tool'],
    'SemrushBot'            => ['name' => 'SemrushBot', 'category' => 'seo_tool', 'type' => 'seo_tool'],
    'DotBot'                => ['name' => 'DotBot (Moz)', 'category' => 'seo_tool', 'type' => 'seo_tool'],
    'MJ12bot'               => ['name' => 'MJ12bot (Majestic)', 'category' => 'seo_tool', 'type' => 'seo_tool'],
    'Screaming Frog SEO Spider' => ['name' => 'Screaming Frog', 'category' => 'seo_tool', 'type' => 'seo_tool'],
    'Sitebulb'              => ['name' => 'Sitebulb', 'category' => 'seo_tool', 'type' => 'seo_tool'],
  ];

  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('wsc_analytics');
  }

  /**
   * Detect bot from User-Agent string.
   *
   * @param string $user_agent
   * @return array|null Metadata array or null if human visitor.
   */
  public function detectBot(string $user_agent): ?array {
    if (empty($user_agent)) {
      return null;
    }

    foreach (self::BOT_TAXONOMY as $token => $info) {
      if (stripos($user_agent, $token) !== false) {
        return $info;
      }
    }

    // Generic fallback for bot keywords
    if (preg_match('/(bot|crawler|spider|slurp|archiver|transcoder|fetcher)/i', $user_agent)) {
      return [
        'name' => 'Generic Crawler (' . substr($user_agent, 0, 30) . ')',
        'category' => 'other',
        'type' => 'other',
      ];
    }

    return null;
  }
}
