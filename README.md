# WSC Analytics & Search Health for Drupal (`v2.2.3` — Drupal 8 / 9 / 10 / 11)

<p align="center">
  <strong>Official Drupal Connector of the World Search Council (WSC) Standard</strong><br>
  100% Cookieless Zero-PII Analytics, HTTP Kernel AI Crawler Sensor, Canonical 7-Card Bento Suite, SpeedSentinel 2.0, AI Coverage Inspector, Industry Benchmarks, Live WSC Member Bridge, <code>/llms.txt</code> &amp; Opt-In IndexNow
</p>

<p align="center">
  <a href="https://www.worldsearchcouncil.org"><img src="https://img.shields.io/badge/Portal-worldsearchcouncil.org-0F172A?style=flat-square" alt="World Search Council"></a>
  <img src="https://img.shields.io/badge/Version-2.2.3-0678BE?style=flat-square&logo=drupal" alt="Drupal v2.2.3">
  <img src="https://img.shields.io/badge/Drupal-8_|_9_|_10_|_11-0678BE?style=flat-square" alt="Drupal 8-11">
  <img src="https://img.shields.io/badge/Privacy-100%25_Cookieless_Zero--PII-10B981?style=flat-square" alt="Cookieless">
  <img src="https://img.shields.io/badge/License-GPL--2.0--or--later-orange?style=flat-square" alt="License">
</p>

---

## 🌟 Key Features (`v2.2.3`)

* **100% Cookieless & Zero-PII Privacy by Design**:
  * Rotating daily salted session hashes (`SHA256(IP + UA + DailySalt)`) — zero tracking cookies and **zero stored raw IP addresses**.
  * **Zero Unconsented Phone-Home**: Hybrid Cloud Sync (`hybrid_enabled: false`) and `IndexNow` (`indexnow_enabled: false`) default strictly to opt-in.
* **Dual-Layer Sensor Architecture**:
  * **Edge HTTP Kernel Sensor (`WscRequestSubscriber`)**: Intercepts AI Retrieval/RAG agents (`ChatGPT-User`, `OAI-SearchBot`, `PerplexityBot`), AI Training scrapers (`GPTBot`, `ClaudeBot`, `CCBot`, `Bytespider`), and Search crawlers (`Googlebot`, `Bingbot`).
  * **Client Runtime (`wsc-tracker.js`)**: Zero-dependency Vanilla ES6 (< 5 KB) bundled locally with zero external CDNs.
* **Canonical 7-Card Bento Suite & Multi-Period Switcher (`1d`, `7d`, `30d`, `90d`, `365d`)**:
  1. **`card1_traffic`** — Real Human Visitors, Pageviews, Engaged Time & AI/Search/Social/Direct Attribution
  2. **`card2_insights`** — Algorithmic Growth Insights, Anomaly Detection & Prioritized Actions
  3. **`card3_bots`** — AI Bot Shield Sensor Network (Live RAG vs. Training Scrapers vs. Search Indexers)
  4. **`card_engagement`** — Engaged Reading Time, TTFI, Scroll Milestones (25/50/75/100%) & UX Verdict Badges
  5. **`card_outbound`** — External Link Destinations Categorized (Jobs, Social, Affiliate, Reference)
  6. **`card_ecommerce`** — 4-Stage Conversion Funnel (`Views` → `Cart` → `Checkout` → `Purchase`) & Drupal Commerce Hook
  7. **`domain_health` + `pagespeed`** — Port-25-Free Domain Sentinel & **SpeedSentinel 2.0 Dual-Thermometer** (🤖 RAG Readiness vs. 👤 Human CWV)
* **AI Coverage Inspector, Dynamic Benchmarks, `/llms.txt` & Opt-In IndexNow (`v2.2.3`)**:
  * **AI Coverage URL Inspector**: Per-URL AI crawler & RAG citation query (`/v1/ai-coverage/query`) with local SQLite `wsc_bot_hits` fallback.
  * **Dynamic Industry Benchmarks**: Peer-group comparison (`/v1/benchmarks/query` & `/v1/benchmarks/classify`) with 6-hour State cache and offline baselines.
  * **Public `/llms.txt` AI Manifest**: Dynamically renders Markdown index of published Drupal nodes at `/llms.txt`.
  * **Opt-In `IndexNow` Instant Push**: Automatic `hook_entity_insert` / `hook_entity_update` ping + manual URL push (`https://api.indexnow.org/indexnow`) with virtual `/{key}.txt` ownership verification.
* **Live WSC Member & Agency Governance Bridge (`v2.2.3`)**:
  * Live credential verification against `https://www.worldsearchcouncil.org/api/v1/license/verify`, Domain Heartbeat, Agency Co-Branding sync, and 100% ad-free UI for active WSC Members.

---

## 🔗 Resources & License

- **Official Portal & Member Console:** [https://www.worldsearchcouncil.org](https://www.worldsearchcouncil.org)
- **GitHub Organization & Maintainer:** [World-Search-Council](https://github.com/World-Search-Council) · Founding Lead Maintainer: **[@thebennos](https://github.com/thebennos)**
- **Drupal Integration Docs:** [https://world-search-council.github.io/wsc-docs/platforms/drupal/](https://world-search-council.github.io/wsc-docs/platforms/drupal/)
- **License:** [GNU General Public License v2.0 or later (GPL-2.0-or-later)](LICENSE)
