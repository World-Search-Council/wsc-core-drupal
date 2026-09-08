<?php

/**
 * @file
 * Provide views data for WSC Analytics SQLite tables.
 *
 * Integrates directly with Drupal Views and the Drupal Charts module
 * (drupal/charts - supporting ECharts, Chart.js, Highcharts, Google Charts).
 */

/**
 * Implements hook_views_data().
 */
function wsc_analytics_views_data() {
  $data = [];

  // =========================================================================
  // 1. Table: wsc_daily_overview (Daily Aggregated Human & Bot Traffic)
  // =========================================================================
  $data['wsc_daily_overview']['table']['group'] = t('WSC Analytics');
  $data['wsc_daily_overview']['table']['base'] = [
    'field' => 'date',
    'title' => t('WSC Daily Traffic Overview'),
    'help' => t('Aggregated daily human visitors, pageviews, and AI crawler activity from WSC Reports SQLite database.'),
    'database' => 'wsc_reports',
  ];

  // Field: Date
  $data['wsc_daily_overview']['date'] = [
    'title' => t('Date'),
    'help' => t('The recorded date (YYYY-MM-DD).'),
    'field' => [
      'id' => 'standard',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'string',
    ],
    'argument' => [
      'id' => 'string',
    ],
  ];

  // Field: Human Unique Visitors
  $data['wsc_daily_overview']['human_visitors'] = [
    'title' => t('Unique Human Visitors'),
    'help' => t('Count of unique human visitors (cookieless daily hash).'),
    'field' => [
      'id' => 'numeric',
    ],
    'sort' => [
      'id' => 'numeric',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
  ];

  // Field: Human Pageviews
  $data['wsc_daily_overview']['human_pageviews'] = [
    'title' => t('Human Pageviews'),
    'help' => t('Total pageviews excluding bots.'),
    'field' => [
      'id' => 'numeric',
    ],
    'sort' => [
      'id' => 'numeric',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
  ];

  // Field: Bot Hits
  $data['wsc_daily_overview']['bot_hits'] = [
    'title' => t('Total Bot Hits'),
    'help' => t('Total search and AI crawler hits.'),
    'field' => [
      'id' => 'numeric',
    ],
    'sort' => [
      'id' => 'numeric',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
  ];

  // Field: AI Training Hits
  $data['wsc_daily_overview']['ai_training_hits'] = [
    'title' => t('AI Training Bot Scrapes'),
    'help' => t('Hits by foundation model scrapers (GPTBot, ClaudeBot, Bytespider, etc.).'),
    'field' => [
      'id' => 'numeric',
    ],
    'sort' => [
      'id' => 'numeric',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
  ];

  // Field: AI RAG Hits
  $data['wsc_daily_overview']['ai_rag_hits'] = [
    'title' => t('AI Live RAG / Search Bot Hits'),
    'help' => t('Hits by user-triggered prompt assistants (ChatGPT-User, Perplexity, etc.).'),
    'field' => [
      'id' => 'numeric',
    ],
    'sort' => [
      'id' => 'numeric',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
  ];

  // Field: Human Percentage
  $data['wsc_daily_overview']['human_pct'] = [
    'title' => t('Human Traffic %'),
    'help' => t('Ratio of real humans vs automated bots.'),
    'field' => [
      'id' => 'numeric',
    ],
    'sort' => [
      'id' => 'numeric',
    ],
  ];

  // =========================================================================
  // 2. Table: wsc_bot_stats (Bot-by-Bot Breakdown)
  // =========================================================================
  $data['wsc_bot_stats']['table']['group'] = t('WSC Bot Shield');
  $data['wsc_bot_stats']['table']['base'] = [
    'field' => 'bot_name',
    'title' => t('WSC Bot Activity Statistics'),
    'help' => t('Bot hits broken down by crawler name, category, and type.'),
    'database' => 'wsc_reports',
  ];

  $data['wsc_bot_stats']['date'] = [
    'title' => t('Date'),
    'help' => t('The date of bot activity.'),
    'field' => ['id' => 'standard'],
    'filter' => ['id' => 'string'],
    'sort' => ['id' => 'standard'],
  ];

  $data['wsc_bot_stats']['bot_name'] = [
    'title' => t('Bot Name'),
    'help' => t('Crawler name (e.g. Googlebot, GPTBot, PerplexityBot).'),
    'field' => ['id' => 'standard'],
    'filter' => ['id' => 'string'],
    'sort' => ['id' => 'standard'],
  ];

  $data['wsc_bot_stats']['bot_category'] = [
    'title' => t('Bot Category'),
    'help' => t('Category (ai, search, seo_tool).'),
    'field' => ['id' => 'standard'],
    'filter' => ['id' => 'string'],
    'sort' => ['id' => 'standard'],
  ];

  $data['wsc_bot_stats']['bot_type'] = [
    'title' => t('Bot Type'),
    'help' => t('Type (rag, training, search, other).'),
    'field' => ['id' => 'standard'],
    'filter' => ['id' => 'string'],
    'sort' => ['id' => 'standard'],
  ];

  $data['wsc_bot_stats']['hits'] = [
    'title' => t('Hit Count'),
    'help' => t('Number of requests recorded by this bot.'),
    'field' => ['id' => 'numeric'],
    'sort' => ['id' => 'numeric'],
    'filter' => ['id' => 'numeric'],
  ];

  $data['wsc_bot_stats']['blocked'] = [
    'title' => t('Is Blocked'),
    'help' => t('Whether this bot was blocked by the shield.'),
    'field' => ['id' => 'boolean'],
    'filter' => ['id' => 'boolean'],
  ];

  // =========================================================================
  // 3. Table: wsc_top_pages (Top Visited URLs)
  // =========================================================================
  $data['wsc_top_pages']['table']['group'] = t('WSC Content');
  $data['wsc_top_pages']['table']['base'] = [
    'field' => 'url_path',
    'title' => t('WSC Top Pages'),
    'help' => t('Top visited pages and content performance.'),
    'database' => 'wsc_reports',
  ];

  $data['wsc_top_pages']['date'] = [
    'title' => t('Date'),
    'field' => ['id' => 'standard'],
    'filter' => ['id' => 'string'],
  ];

  $data['wsc_top_pages']['url_path'] = [
    'title' => t('URL Path'),
    'field' => ['id' => 'standard'],
    'filter' => ['id' => 'string'],
    'sort' => ['id' => 'standard'],
  ];

  $data['wsc_top_pages']['pageviews'] = [
    'title' => t('Pageviews'),
    'field' => ['id' => 'numeric'],
    'sort' => ['id' => 'numeric'],
  ];

  $data['wsc_top_pages']['unique_visitors'] = [
    'title' => t('Unique Visitors'),
    'field' => ['id' => 'numeric'],
    'sort' => ['id' => 'numeric'],
  ];

  return $data;
}
