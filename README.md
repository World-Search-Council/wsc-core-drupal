# WSC Analytics & Bot Shield for Drupal (Drupal 10 / 11)

Official Drupal integration of the **World Search Council (WSC) Hybrid Analytics & AI Bot Shield Standard**.

Provides cookieless, privacy-first web analytics, deep AI crawler tracking at the HTTP kernel layer, automated AES-256 encrypted micro-batch synchronization to the WSC Gateway, and native integration with **Drupal Views** and the **Drupal Charts module** (`drupal/charts`).

---

## 🌟 Key Features

* **Cookieless Privacy-First Tracking**: 100% GDPR, TTDSG/TDDDG, and ePrivacy compliant without requiring annoying cookie banners.
* **Dual-Layer Architecture**:
  * **Edge Sensor (`WscRequestSubscriber`)**: Intercepts AI crawlers (`GPTBot`, `ClaudeBot`, `ChatGPT-User`, `Perplexity`, etc.) and search engines (`Googlebot`, `Bingbot`) in real time before response rendering.
  * **Client Runtime (`wsc-tracker.js`)**: Pure Vanilla JS (< 4 KB) with automated micro-batching (5s intervals / 10 events) and `sendBeacon` lifecycle flush.
* **Local SQLite Queuing & WAL Acceleration**:
  * Writes into `queue_temp.sqlite` without creating database lock overhead on main Drupal MySQL/Postgres instances.
  * Isolated `reports_temp.sqlite` stores aggregated 3-Card dashboard metrics.
* **Drupal Views & Charts Integration (`hook_views_data`)**:
  * Exposes reporting tables (`wsc_daily_overview`, `wsc_bot_stats`, `wsc_top_pages`) directly to **Drupal Views**.
  * Fully compatible with the contributed **Drupal Charts module** (`drupal/charts`) supporting **Apache ECharts**, **Chart.js**, **Highcharts**, and **Google Charts**.
* **3-Card Anti-GA4 Smart Dashboard**:
  * **Card 1**: Traffic & Acquisition Channels (AI Search Citations, Organic Search, Social, Direct, Referral).
  * **Card 2**: Strategic Human Insights & Anomaly Detection.
  * **Card 3**: Bot Shield Sensor Network & Data Quality.
  * Progressive disclosure detail row with 7-day and 30-day toggles.
* **Cryptographic Security**:
  * Payloads encrypted with local 256-bit AES-CBC keys.
  * Every transmission signed with `HMAC-SHA256` (`X-WSC-Sig`).

---

## 📦 Directory Structure

```text
wsc-core-drupal/
├── wsc_analytics.info.yml           # Drupal module metadata (^10 || ^11)
├── wsc_analytics.module             # Page attachments, cron, theme definitions
├── wsc_analytics.routing.yml        # Routes: /wsc/v1/track, /wsc/v1/webhook, /admin/reports/wsc-analytics
├── wsc_analytics.permissions.yml    # Permissions ('administer wsc analytics', 'access wsc analytics reports')
├── wsc_analytics.libraries.yml      # Asset definitions (tracker, dashboard)
├── wsc_analytics.services.yml       # Symfony Dependency Injection definitions
├── wsc_analytics.links.menu.yml     # Admin menu links
├── wsc_analytics.links.task.yml     # Admin tabs
├── wsc_analytics.views.inc          # hook_views_data() for Drupal Views & Charts
├── src/
│   ├── Service/
│   │   ├── WscCoreService.php       # AES-256 encryption, HMAC signing, Gateway sync
│   │   ├── WscDatabaseManager.php   # SQLite management with WAL & security protection
│   │   └── WscBotTracker.php        # AI/LLM crawler taxonomy & pattern engine
│   ├── EventSubscriber/
│   │   └── WscRequestSubscriber.php # Kernel request subscriber for edge bot tracking
│   ├── Controller/
│   │   ├── WscApiController.php     # Ingestion & webhook REST controller
│   │   └── WscDashboardController.php # 3-Card Dashboard controller
│   └── Form/
│       └── WscSettingsForm.php      # Admin configuration form
├── templates/
│   └── wsc-dashboard.html.twig      # Twig template for 3-Card Dashboard
├── js/
│   ├── wsc-tracker.js               # Canonical Vanilla WSC client tracker
│   └── wsc-dashboard.js             # Interactive dashboard JS (toggles & sync)
└── css/
    └── wsc-dashboard.css            # Dashboard styling (Claro / Gin compatible)
```

---

## 🚀 Installation & Setup

1. Copy the `wsc-core-drupal` directory into your Drupal project under `web/modules/custom/wsc_analytics` (or `modules/wsc_analytics`).
2. Enable the module via Drush or the Drupal Admin UI:
   ```bash
   drush pm:enable wsc_analytics
   ```
3. Navigate to **Administration → Configuration → Web services → WSC Analytics** (`/admin/config/services/wsc-analytics`) to verify settings.
4. View real-time analytics under **Administration → Reports → WSC Analytics & Bot Shield** (`/admin/reports/wsc-analytics`).

---

## 📊 Using with the Drupal Charts Module (`drupal/charts`)

1. Install `drupal/charts` and your preferred rendering library (e.g., `charts_echarts` or `charts_chartjs`):
   ```bash
   composer require drupal/charts
   drush pm:enable charts charts_echarts
   ```
2. Navigate to **Structure → Views → Add view**:
   * **Show**: *WSC Daily Traffic Overview*
   * **Format**: *Chart* (Select ECharts or Chart.js)
   * **X-Axis**: `Date`
   * **Fields**: `Unique Human Visitors`, `Human Pageviews`, `AI Training Bot Scrapes`, `AI Live RAG Hits`
3. Save the View to display custom interactive charts in any Drupal Block or Page!

---

## 🔒 Security & Privacy

* **SQLite Hardening**: The SQLite databases are stored in `private://wsc_analytics` or `public://wsc_analytics` protected with `.htaccess`, `web.config`, and `index.php` blocking all direct web access.
* **Ad-Blocker Proof**: Endpoints run natively on the first-party domain `/wsc/v1/track`.
* **Zero Fingerprinting**: Respects user privacy, eliminates third-party tracking scripts.
