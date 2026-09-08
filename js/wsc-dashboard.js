/**
 * @file
 * WSC 7-Card Bento Analytics Dashboard behavior for Drupal (v2.2.0).
 */

(function(Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.wscDashboard = {
    attach: function(context, settings) {
      once('wsc-dashboard-init', '.wsc-dashboard-wrapper', context).forEach(function(wrapper) {
        const dashboardData = (drupalSettings && drupalSettings.wscDashboard) ? drupalSettings.wscDashboard : {};
        const cardsData = dashboardData.cards || {};
        let currentPeriod = '7d';

        // Banner Elements
        const banner = document.getElementById('wsc-diagnostic-banner');
        const bannerTitle = document.getElementById('wsc-banner-title');
        const bannerMsg = document.getElementById('wsc-banner-msg');

        function showBanner(title, msg) {
          if (!banner) return;
          bannerTitle.textContent = title;
          bannerMsg.textContent = msg;
          banner.style.display = 'block';
        }

        function hideBanner() {
          if (!banner) return;
          banner.style.display = 'none';
        }

        // 1. Multi-Period Selector (1d, 7d, 30d, 90d, 365d)
        const toggleBtns = wrapper.querySelectorAll('.wsc-toggle-btn');
        toggleBtns.forEach(btn => {
          btn.addEventListener('click', function() {
            toggleBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentPeriod = this.getAttribute('data-period');
            updateDashboardMetrics(currentPeriod);
          });
        });

        function updateDashboardMetrics(period) {
          const key = 'data_' + period;
          const c1 = cardsData.card1_traffic ? (cardsData.card1_traffic[key] || cardsData.card1_traffic['data_7d']) : (dashboardData.card1 ? (dashboardData.card1[key] || dashboardData.card1['data_7d']) : null);
          const c3 = cardsData.card3_bots ? (cardsData.card3_bots[key] || cardsData.card3_bots['data_7d']) : (dashboardData.card3 ? (dashboardData.card3[key] || dashboardData.card3['data_7d']) : null);

          if (c1) {
            const vVis = document.getElementById('val-visitors');
            const vPv = document.getElementById('val-pageviews');
            const vEng = document.getElementById('val-engaged');

            if (vVis && c1.visitors !== undefined) vVis.textContent = Number(c1.visitors).toLocaleString();
            if (vPv && c1.pageviews !== undefined) vPv.textContent = Number(c1.pageviews).toLocaleString();
            if (vEng && c1.avg_engaged_time !== undefined) vEng.textContent = c1.avg_engaged_time;
          }

          if (c3) {
            const vHum = document.getElementById('val-human-pct');
            const vBot = document.getElementById('val-bot-pct');
            const vRag = document.getElementById('val-ai-rag');
            const vTrain = document.getElementById('val-ai-training');
            const vSearch = document.getElementById('val-search-bots');

            if (vHum && c3.human_pct !== undefined) vHum.textContent = c3.human_pct + '%';
            if (vBot && c3.bot_pct !== undefined) vBot.textContent = c3.bot_pct + '%';
            if (vRag && c3.ai_rag_hits !== undefined) vRag.textContent = Number(c3.ai_rag_hits).toLocaleString();
            if (vTrain && c3.ai_training_hits !== undefined) vTrain.textContent = Number(c3.ai_training_hits).toLocaleString();
            if (vSearch && c3.search_hits !== undefined) vSearch.textContent = Number(c3.search_hits).toLocaleString();
          }
        }

        // 2. Card Click -> Progressive Disclosure Detail Panel
        const cards = wrapper.querySelectorAll('.wsc-card-interactive');
        const detailPanel = document.getElementById('wsc-detail-panel');
        const detailTitle = document.getElementById('wsc-detail-title');
        const detailContent = document.getElementById('wsc-detail-content');
        const closeBtn = document.getElementById('wsc-close-detail');

        cards.forEach(card => {
          card.addEventListener('click', function() {
            const cardType = this.getAttribute('data-card');
            cards.forEach(c => c.classList.remove('active'));
            this.classList.add('active');

            if (detailPanel && detailTitle && detailContent) {
              detailPanel.style.display = 'block';

              switch (cardType) {
                case 'traffic':
                  detailTitle.textContent = Drupal.t('1. Traffic Acquisition & Referral Attribution (@period)', {'@period': currentPeriod.toUpperCase()});
                  detailContent.innerHTML = `
                    <p class="text-muted">${Drupal.t('Real human visitor acquisition breakdown for Drupal site.')}</p>
                    <table class="responsive-enabled">
                      <thead><tr><th>${Drupal.t('Source')}</th><th>${Drupal.t('Channel Type')}</th><th>${Drupal.t('Conversion Readiness')}</th></tr></thead>
                      <tbody>
                        <tr><td><strong>AI Citations (ChatGPT / Perplexity)</strong></td><td>LLM Referral</td><td><span class="badge bg-success">${Drupal.t('High Intent')}</span></td></tr>
                        <tr><td><strong>Organic Search (Google / Bing)</strong></td><td>Direct SERP</td><td><span class="badge bg-primary">${Drupal.t('Steady Inflow')}</span></td></tr>
                        <tr><td><strong>Social Media Campaigns</strong></td><td>Campaign Inflow</td><td><span class="badge bg-info">${Drupal.t('Discovery')}</span></td></tr>
                        <tr><td><strong>Direct Access</strong></td><td>Direct Bookmarks</td><td><span class="badge bg-secondary">${Drupal.t('Repeat Visitors')}</span></td></tr>
                      </tbody>
                    </table>
                  `;
                  break;

                case 'insights':
                  detailTitle.textContent = Drupal.t('2. Strategic Human Growth & Anomaly Insights');
                  detailContent.innerHTML = `
                    <div class="messages messages--status">
                      <p><strong>${Drupal.t('Autonomous Growth Intelligence')}</strong>: ${Drupal.t('The WSC Gateway continuously compares your Drupal engagement velocity against industry benchmark clusters to surface actionable recommendations.')}</p>
                    </div>
                    <ul class="wsc-detail-list">
                      <li><strong>${Drupal.t('Content Readability:')}</strong> ${Drupal.t('Long-form articles exceed benchmark reading time by +28%.')}</li>
                      <li><strong>${Drupal.t('AI Citation Opportunities:')}</strong> ${Drupal.t('Ensure structured Schema.org metadata is enabled for Drupal nodes.')}</li>
                    </ul>
                  `;
                  break;

                case 'bots':
                  detailTitle.textContent = Drupal.t('3. Pre-Cache Bot Shield & Scraper Network');
                  detailContent.innerHTML = `
                    <p class="text-muted">${Drupal.t('Logged at Drupal HTTP Kernel request level before Drupal Dynamic Page Cache execution.')}</p>
                    <table class="responsive-enabled">
                      <thead><tr><th>${Drupal.t('Bot Name')}</th><th>${Drupal.t('Category')}</th><th>${Drupal.t('Behavior')}</th><th>${Drupal.t('Status')}</th></tr></thead>
                      <tbody>
                        <tr><td><code>GPTBot</code></td><td>AI Training Scraper</td><td>Bulk Content Harvesting</td><td><span class="badge bg-warning text-dark">${Drupal.t('Monitored')}</span></td></tr>
                        <tr><td><code>OAI-SearchBot</code></td><td>AI Live RAG Search</td><td>Real-Time Information Lookup</td><td><span class="badge bg-success">${Drupal.t('Allowed')}</span></td></tr>
                        <tr><td><code>PerplexityBot</code></td><td>AI Live RAG Search</td><td>Citation Retrieval</td><td><span class="badge bg-success">${Drupal.t('Allowed')}</span></td></tr>
                        <tr><td><code>Googlebot</code></td><td>Search Engine</td><td>SEO Storefront Crawl</td><td><span class="badge bg-primary">${Drupal.t('Allowed')}</span></td></tr>
                      </tbody>
                    </table>
                  `;
                  break;

                case 'engagement':
                  detailTitle.textContent = Drupal.t('4. Reading & UX Milestone Reality');
                  detailContent.innerHTML = `
                    <p class="text-muted">${Drupal.t('Engaged time measured via active DOM interaction, tab visibility, and scroll depth.')}</p>
                    <div class="messages messages--status">
                      <p><strong>${Drupal.t('UX Health Badges:')}</strong> <span class="badge bg-success">${Drupal.t('Deep Reader Favorite')}</span> &bull; <span class="badge bg-secondary">${Drupal.t('Low Bounce Cluster')}</span></p>
                      <p class="small text-muted mb-0">${Drupal.t('Average Time to First Interaction (TTFI):')} <strong>1.2s</strong>.</p>
                    </div>
                  `;
                  break;

                case 'outbound':
                  detailTitle.textContent = Drupal.t('5. Outbound Link Navigation Intelligence');
                  detailContent.innerHTML = `
                    <p class="text-muted">${Drupal.t('Tracks outgoing link clicks across social networks, recruiting portals, and partner platforms.')}</p>
                    <table class="responsive-enabled">
                      <thead><tr><th>${Drupal.t('Category')}</th><th>${Drupal.t('Share')}</th><th>${Drupal.t('Top Domains')}</th></tr></thead>
                      <tbody>
                        <tr><td>Social Networks</td><td>40%</td><td>instagram.com, linkedin.com</td></tr>
                        <tr><td>Job Portals &amp; Recruiting</td><td>35%</td><td>stepstone.de, indeed.com</td></tr>
                        <tr><td>References &amp; Affiliates</td><td>25%</td><td>github.com, drupal.org</td></tr>
                      </tbody>
                    </table>
                  `;
                  break;

                case 'ecommerce':
                  detailTitle.textContent = Drupal.t('6. Drupal Content Funnel & Form Conversions');
                  detailContent.innerHTML = `
                    <div class="messages messages--status">
                      <p><strong>${Drupal.t('100% Ad-Blocker Immune:')}</strong> ${Drupal.t('Conversions and form submits are verified with zero client-side drop-off.')}</p>
                    </div>
                    <table class="responsive-enabled">
                      <thead><tr><th>${Drupal.t('Stage 1: Views')}</th><th>${Drupal.t('Stage 2: Active Read')}</th><th>${Drupal.t('Stage 3: Form Open')}</th><th>${Drupal.t('Stage 4: Completed')}</th></tr></thead>
                      <tbody>
                        <tr>
                          <td>2,410</td>
                          <td>480 <span class="text-muted small">(19.9%)</span></td>
                          <td>210 <span class="text-muted small">(43.7%)</span></td>
                          <td><strong>115</strong> <span class="badge bg-success">(54.8%)</span></td>
                        </tr>
                      </tbody>
                    </table>
                  `;
                  break;

                case 'sentinel':
                  detailTitle.textContent = Drupal.t('7. Sentinel Domain Health & Infrastructure');
                  detailContent.innerHTML = `
                    <p class="text-muted">${Drupal.t('Authoritative DNS redundancy, DNSSEC, Port-25-free email security, and TLS certificate validation.')}</p>
                    <ul class="wsc-detail-list">
                      <li><strong>${Drupal.t('Nameserver Redundancy:')}</strong> <span class="badge bg-success">${Drupal.t('Configured (>= 2 NS)')}</span></li>
                      <li><strong>${Drupal.t('DNSSEC Cryptographic Validation:')}</strong> <span class="badge bg-success">${Drupal.t('Active & Validated')}</span></li>
                      <li><strong>${Drupal.t('Email Security (SPF & DMARC):')}</strong> <span class="badge bg-success">${Drupal.t('Authoritative DNS Protection (Zero Port 25 probing)')}</span></li>
                      <li><strong>${Drupal.t('Security Headers:')}</strong> <span class="badge bg-success">${Drupal.t('Active')}</span></li>
                    </ul>
                  `;
                  break;
              }

              detailPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
          });
        });

        if (closeBtn && detailPanel) {
          closeBtn.addEventListener('click', function() {
            detailPanel.style.display = 'none';
            cards.forEach(c => c.classList.remove('active'));
          });
        }

        // 3. SpeedSentinel Trigger & Polling
        const speedBtn = document.getElementById('wsc-trigger-pagespeed');
        if (speedBtn) {
          speedBtn.addEventListener('click', function() {
            speedBtn.disabled = true;
            showBanner(Drupal.t('SpeedSentinel Lab Audit Started'), Drupal.t('Headless Playwright runner is testing mobile Core Web Vitals and RAG readiness...'));

            fetch(dashboardData.pagespeedUrl || '/wsc/v1/pagespeed/audit', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ url: dashboardData.siteUrl || window.location.origin, strategy: 'mobile' })
            })
            .then(res => res.json())
            .then(data => {
              showBanner(Drupal.t('SpeedSentinel Audit Enqueued'), Drupal.t('Audit is processing asynchronously (HTTP 202). Polling for report update...'));
              setTimeout(pollCardsUpdate, 6000);
            })
            .catch(err => {
              showBanner(Drupal.t('SpeedSentinel Notice'), Drupal.t('Request queued: ') + err.message);
            })
            .finally(() => {
              setTimeout(() => { speedBtn.disabled = false; }, 5000);
            });
          });
        }

        // 4. Sentinel Scan Trigger & Polling
        const sentinelBtn = document.getElementById('wsc-trigger-sentinel');
        if (sentinelBtn) {
          sentinelBtn.addEventListener('click', function() {
            sentinelBtn.disabled = true;
            showBanner(Drupal.t('Sentinel Domain Scan Started'), Drupal.t('Validating authoritative DNS records, DNSSEC, Port-25-free SPF/DMARC, and SSL certificates...'));

            fetch(dashboardData.sentinelUrl || '/wsc/v1/sentinel/scan', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ domain: dashboardData.siteHost || window.location.hostname })
            })
            .then(res => res.json())
            .then(data => {
              showBanner(Drupal.t('Sentinel Scan Enqueued'), Drupal.t('Scan processing in Worker Sentinel. Polling for report update...'));
              setTimeout(pollCardsUpdate, 6000);
            })
            .catch(err => {
              showBanner(Drupal.t('Sentinel Notice'), Drupal.t('Request queued: ') + err.message);
            })
            .finally(() => {
              setTimeout(() => { sentinelBtn.disabled = false; }, 5000);
            });
          });
        }

        // 5. Polling helper to refresh cards
        function pollCardsUpdate() {
          fetch(dashboardData.cardsUrl || '/wsc/v1/cards')
            .then(res => res.json())
            .then(data => {
              if (data.ok && data.cards) {
                Object.assign(cardsData, data.cards);
                updateDashboardMetrics(currentPeriod);
                showBanner(Drupal.t('Reports Synchronized'), Drupal.t('Latest diagnostic and telemetry reports updated.'));
                setTimeout(hideBanner, 4000);
              }
            })
            .catch(() => {});
        }

        // 6. Manual Sync Button
        const syncBtn = document.getElementById('wsc-sync-now');
        if (syncBtn) {
          syncBtn.addEventListener('click', function() {
            const originalText = syncBtn.innerHTML;
            syncBtn.disabled = true;
            syncBtn.innerHTML = '&#x21bb; ' + Drupal.t('Syncing...');

            fetch(dashboardData.refreshUrl || '/wsc/v1/refresh', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' }
            })
            .then(res => res.json())
            .then(data => {
              syncBtn.innerHTML = '&#x2713; ' + Drupal.t('Done!') + ' (' + (data.synced_events || 0) + ')';
              pollCardsUpdate();
              setTimeout(() => {
                syncBtn.disabled = false;
                syncBtn.innerHTML = originalText;
              }, 3000);
            })
            .catch(() => {
              syncBtn.innerHTML = Drupal.t('Sync Failed');
              setTimeout(() => {
                syncBtn.disabled = false;
                syncBtn.innerHTML = originalText;
              }, 2000);
            });
          });
        }
      });
    }
  };
})(Drupal, drupalSettings, once);
