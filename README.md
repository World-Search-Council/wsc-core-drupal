# WSC Analytics & AI Bot Shield for Drupal (`v2.2.0` — Drupal 8 / 9 / 10 / 11)

<p align="center">
  <strong>Official Drupal Connector of the World Search Council (WSC) Standard</strong><br>
  100% Cookieless Analytics, HTTP Kernel AI Crawler Sensor, Canonical 7-Card Bento Suite, SpeedSentinel 2.0 & Drupal Views/Charts Integration
</p>

<p align="center">
  <a href="https://www.worldsearchcouncil.org"><img src="https://img.shields.io/badge/Portal-worldsearchcouncil.org-0F172A?style=flat-square" alt="World Search Council"></a>
  <img src="https://img.shields.io/badge/Version-2.2.0-0678BE?style=flat-square&logo=drupal" alt="Drupal v2.2.0">
  <img src="https://img.shields.io/badge/Drupal-8_|_9_|_10_|_11-0678BE?style=flat-square" alt="Drupal 8-11">
  <img src="https://img.shields.io/badge/Privacy-100%25_Cookieless-10B981?style=flat-square" alt="Cookieless">
  <img src="https://img.shields.io/badge/License-GPL--2.0--or--later-orange?style=flat-square" alt="License">
</p>

---

## 🌟 Key Features (`v2.2.0`)

* **100% Cookieless & Privacy-First**: GDPR, TDDDG, and ePrivacy compliant by design — rotating daily salted session hashes (`SHA256(IP + UA + DailySalt)`) with zero tracking cookies and zero stored raw IPs.
* **Dual-Layer Sensor Architecture**:
  * **Edge HTTP Kernel Sensor (`WscRequestSubscriber`)**: Intercepts AI Retrieval/RAG agents (`ChatGPT-User`, `OAI-SearchBot`, `PerplexityBot`), AI Training scrapers (`GPTBot`, `ClaudeBot`, `CCBot`, `Bytespider`), and Search crawlers (`Googlebot`, `Bingbot`) before response rendering.
  * **Client Runtime (`wsc-tracker.js`)**: Zero-dependency Vanilla ES6 (< 5 KB) with 5s/10-event micro-batching and `navigator.sendBeacon` unload flush.
* **Non-Blocking SQLite WAL Storage**:
  * Buffers outgoing events in `queue_temp.sqlite` and caches pre-computed reports in `reports_temp.sqlite` (`PRAGMA journal_mode = WAL`) without locking Drupal's primary MySQL/PostgreSQL database.
* **Canonical 7-Card Bento Suite & Multi-Period Switcher (`1d`, `7d`, `30d`, `90d`, `365d`)**:
  1. **`card1_traffic`** — Real Human Visitors, Pageviews, Engaged Time & AI/Search/Social/Direct Attribution
  2. **`card2_insights`** — Algorithmic Growth Insights, Anomaly Detection & Prioritized Actions
  3. **`card3_bots`** — AI Bot Shield Sensor Network (Live RAG vs. Training Scrapers vs. Search Indexers)
  4. **`card_engagement`** — Engaged Reading Time, TTFI, Scroll Milestones (25/50/75/100%) & UX Verdict Badges
  5. **`card_outbound`** — External Link Destinations Categorized (Jobs, Social, Affiliate, Reference)
  6. **`card_ecommerce`** — 4-Stage Conversion Funnel (`Views` → `Cart` → `Checkout` → `Purchase`) & Revenue
  7. **`domain_health` + `pagespeed`** — Port-25-Free Domain Sentinel (DNSSEC, SSL, SPF/DMARC, Security Headers) & **SpeedSentinel 2.0 Dual-Thermometer** (🤖 RAG Readiness vs. 👤 Human CWV)
* **On-Demand Cloud Diagnostics & Live Progress Banner**:
  * 1-click triggers for `/wsc/v1/pagespeed/audit` and `/wsc/v1/sentinel/scan` with non-blocking `/wsc/v1/cards` polling.
* **Native Drupal Views & Charts Integration (`hook_views_data`)**:
  * Exposes `wsc_daily_overview`, `wsc_bot_stats`, and `wsc_top_pages` directly to **Drupal Views** and **`drupal/charts`** (Apache ECharts, Chart.js, Highcharts).
* **Cryptographic Sovereignty**:
  * Optional cloud sync (`https://api.worldsearchcouncil.org`) uses local **AES-256-CBC** payload encryption and **HMAC-SHA256 (`X-WSC-Sig`)** signature verification.

---

## 📦 Directory Structure

```text
wsc-core-drupal/
├── wsc_analytics.info.yml            # Drupal module metadata (v2.2.0)
├── wsc_analytics.module              # Page attachments, cron & theme hooks
├── wsc_analytics.routing.yml         # /wsc/v1/track, /webhook, /cards, /pagespeed/audit, /sentinel/scan
├── wsc_analytics.permissions.yml     # Granular Drupal ACL permissions
├── wsc_analytics.libraries.yml       # Tracker & Bento dashboard asset libraries
├── wsc_analytics.services.yml        # Symfony DI container services
├── wsc_analytics.links.menu.yml      # Reports & Configuration menu links
├── wsc_analytics.links.task.yml      # Admin local task tabs
├── wsc_analytics.views.inc           # hook_views_data() for Drupal Views & Charts
├── src/
│   ├── Service/
│   │   ├── WscCoreService.php        # 7-Card Bento storage, AES-256, HMAC & Gateway triggers
│   │   ├── WscDatabaseManager.php    # Hardened SQLite WAL manager
│   │   └── WscBotTracker.php         # Canonical AI/RAG/Search crawler taxonomy
│   ├── EventSubscriber/
│   │   └── WscRequestSubscriber.php  # Early KernelEvents::REQUEST bot sensor
│   ├── Controller/
│   │   ├── WscApiController.php      # Intake, signed webhook receiver & async diagnostic routes
│   │   └── WscDashboardController.php# 7-Card Bento Dashboard controller
│   └── Form/
│       └── WscSettingsForm.php       # Configuration & Gateway pairing form
├── templates/
│   └── wsc-dashboard.html.twig       # 7-Card Bento Dashboard Twig template (Claro / Gin compatible)
├── js/
│   ├── wsc-tracker.js                # Canonical Vanilla ES6 tracker
│   └── wsc-dashboard.js              # Period switcher, diagnostic triggers & live polling banner
└── css/
    └── wsc-dashboard.css             # Responsive Bento Grid styles
```

---

## 🚀 Installation & Setup

1. Copy this directory into `web/modules/custom/wsc_analytics` (or `modules/custom/wsc_analytics`).
2. Enable via Drush or the Extend UI:
   ```bash
   drush pm:enable wsc_analytics
   ```
3. Configure optional WSC Gateway pairing and WSC Membership credentials at **Administration → Configuration → Web services → WSC Analytics** (`/admin/config/services/wsc-analytics`).
4. Open the 7-Card Bento Dashboard at **Administration → Reports → WSC Analytics & Bot Shield** (`/admin/reports/wsc-analytics`).

---

## 📊 Using with Drupal Views & `drupal/charts`

```bash
composer require drupal/charts
drush pm:enable charts charts_echarts
```

Create a View under **Structure → Views → Add view**, select **Show: WSC Daily Traffic Overview**, choose **Format: Chart (ECharts)**, and plot `Unique Human Visitors`, `Human Pageviews`, `AI Live RAG Hits`, and `AI Training Bot Scrapes` by `Date`.

---

## 🔗 Resources & License

- **Official Portal & Member Console:** [https://www.worldsearchcouncil.org](https://www.worldsearchcouncil.org)
- **Drupal Integration Docs:** [https://world-search-council.github.io/wsc-docs/platforms/drupal/](https://world-search-council.github.io/wsc-docs/platforms/drupal/)
- **License:** [GNU General Public License v2.0 or later (GPL-2.0-or-later)](LICENSE)
