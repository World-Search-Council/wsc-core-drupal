/**
 * @file
 * WSC 7-Card Bento Analytics, AI Coverage, Benchmarks, Membership & IndexNow behavior for Drupal 8–11 (v2.2.3).
 */

(function(Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.wscDashboard = {
    attach: function(context) {
      once('wsc-dashboard-init', '.wsc-dashboard-wrapper', context).forEach(function(wrapper) {
        const dashboardData = (drupalSettings && drupalSettings.wscDashboard) ? drupalSettings.wscDashboard : {};
        const cardsData = dashboardData.cards || {};
        let currentPeriod = '7d';

        const banner = document.getElementById('wsc-diagnostic-banner');
        const bannerTitle = document.getElementById('wsc-banner-title');
        const bannerMsg = document.getElementById('wsc-banner-msg');

        function showBanner(title, msg) {
          if (!banner) return;
          if (bannerTitle) bannerTitle.textContent = title;
          if (bannerMsg) bannerMsg.textContent = msg;
          banner.style.display = 'block';
        }

        function hideBanner() {
          if (banner) banner.style.display = 'none';
        }

        function esc(s) {
          return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
        }

        // 1. Multi-Period Selector
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

        // 2. Detail Drawer
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
              detailTitle.textContent = Drupal.t('Detailed Telemetry: @type', {'@type': cardType.toUpperCase()});
              detailContent.innerHTML = '<p>' + Drupal.t('Zero-PII SQLite WAL & ClickHouse telemetry synchronized under WSC v2.2.3 contract.') + '</p>';
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

        // 3. SpeedSentinel & Sentinel Triggers
        const speedBtn = document.getElementById('wsc-trigger-pagespeed');
        if (speedBtn) {
          speedBtn.addEventListener('click', function() {
            speedBtn.disabled = true;
            showBanner(Drupal.t('SpeedSentinel Lab Audit Started'), Drupal.t('Validating same-host URL and enqueueing headless Playwright CWV + RAG audit...'));
            fetch(dashboardData.pagespeedUrl || '/wsc/v1/pagespeed/audit', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ url: dashboardData.siteUrl || window.location.origin, strategy: 'mobile' })
            })
            .then(r => r.json())
            .then(d => {
              showBanner(d.ok === false ? Drupal.t('SpeedSentinel Notice') : Drupal.t('SpeedSentinel Enqueued'), d.error || Drupal.t('Polling for report update...'));
              if (d.ok !== false) setTimeout(pollCardsUpdate, 6000);
            })
            .finally(() => setTimeout(() => { speedBtn.disabled = false; }, 4000));
          });
        }

        const sentinelBtn = document.getElementById('wsc-trigger-sentinel');
        if (sentinelBtn) {
          sentinelBtn.addEventListener('click', function() {
            sentinelBtn.disabled = true;
            showBanner(Drupal.t('Sentinel Domain Scan Started'), Drupal.t('Checking authoritative DNS, DNSSEC, Port-25-free SPF/DMARC, and TLS...'));
            fetch(dashboardData.sentinelUrl || '/wsc/v1/sentinel/scan', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ domain: dashboardData.siteHost || window.location.hostname })
            })
            .then(r => r.json())
            .then(d => {
              showBanner(d.ok === false ? Drupal.t('Sentinel Notice') : Drupal.t('Sentinel Scan Enqueued'), d.error || Drupal.t('Polling for report update...'));
              if (d.ok !== false) setTimeout(pollCardsUpdate, 6000);
            })
            .finally(() => setTimeout(() => { sentinelBtn.disabled = false; }, 4000));
          });
        }

        // 4. AI Coverage URL Inspector
        const aiBtn = document.getElementById('wsc-ai-coverage-btn');
        const aiIn = document.getElementById('wsc-ai-coverage-input');
        const aiTf = document.getElementById('wsc-ai-coverage-timeframe');
        const aiOut = document.getElementById('wsc-ai-coverage-result');
        if (aiBtn && aiIn && aiOut) {
          aiBtn.addEventListener('click', function() {
            aiBtn.disabled = true;
            aiOut.style.display = 'block';
            aiOut.textContent = Drupal.t('Inspecting AI crawler activity...');
            fetch(dashboardData.aiCoverageUrl || '/wsc/v1/ai-coverage/query', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ query: aiIn.value.trim(), timeframe: aiTf ? aiTf.value : '30d' })
            })
            .then(r => r.json())
            .then(d => {
              const total = d.total_ai_crawls !== undefined ? d.total_ai_crawls : (d.total_hits || 0);
              const rag = d.rag_citations !== undefined ? d.rag_citations : (d.rag_hits || 0);
              const train = d.training_scrapes !== undefined ? d.training_scrapes : (d.training_hits || 0);
              aiOut.innerHTML = '<strong>Total AI Hits:</strong> ' + esc(total) + ' &bull; <strong>Live RAG:</strong> ' + esc(rag) + ' &bull; <strong>Training:</strong> ' + esc(train) + ' &bull; <code>' + esc(d.source || 'gateway') + '</code>';
            })
            .finally(() => { aiBtn.disabled = false; });
          });
        }

        // 5. Benchmarks Refresh & Auto-Classify
        const bmRefresh = document.getElementById('wsc-bm-refresh');
        if (bmRefresh) {
          bmRefresh.addEventListener('click', function() {
            bmRefresh.disabled = true;
            fetch(dashboardData.benchmarksUrl || '/wsc/v1/benchmarks/query').then(() => window.location.reload());
          });
        }
        const bmClassify = document.getElementById('wsc-bm-classify');
        if (bmClassify) {
          bmClassify.addEventListener('click', function() {
            bmClassify.disabled = true;
            fetch(dashboardData.classifyVerticalUrl || '/wsc/v1/benchmarks/classify', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })
              .then(() => window.location.reload());
          });
        }

        // 6. IndexNow Manual Push
        const idxBtn = document.getElementById('wsc-indexnow-btn');
        const idxIn = document.getElementById('wsc-indexnow-url');
        const idxSt = document.getElementById('wsc-indexnow-status');
        if (idxBtn && idxIn && idxSt) {
          idxBtn.addEventListener('click', function() {
            idxBtn.disabled = true;
            idxSt.textContent = Drupal.t('Submitting URL to api.indexnow.org...');
            fetch(dashboardData.indexNowUrl || '/wsc/v1/indexnow/submit', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ url: idxIn.value.trim() })
            })
            .then(r => r.json())
            .then(d => {
              idxSt.textContent = d.ok ? ('✓ IndexNow accepted (HTTP ' + (d.http_status || 200) + ')') : ('IndexNow: ' + (d.error || ('HTTP ' + (d.http_status || 0))));
            })
            .finally(() => { idxBtn.disabled = false; });
          });
        }

        // 7. WSC License Verification & Co-Branding
        const licBtn = document.getElementById('wsc-license-verify-btn');
        const licIn = document.getElementById('wsc-license-input');
        const licFb = document.getElementById('wsc-license-feedback');
        if (licBtn && licIn && licFb) {
          licBtn.addEventListener('click', function() {
            licBtn.disabled = true;
            licFb.style.display = 'block';
            licFb.textContent = Drupal.t('Verifying credential with www.worldsearchcouncil.org...');
            fetch(dashboardData.licenseVerifyUrl || '/wsc/v1/license/verify', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ license_key: licIn.value.trim() })
            })
            .then(r => r.json())
            .then(d => {
              if (d.active) {
                licFb.textContent = '✓ Verified (' + (d.tier || 'member') + '). Reloading...';
                setTimeout(() => window.location.reload(), 700);
              } else {
                licFb.textContent = d.error || 'Verification failed.';
              }
            })
            .finally(() => { licBtn.disabled = false; });
          });
        }

        const licDisc = document.getElementById('wsc-license-disconnect-btn');
        if (licDisc) {
          licDisc.addEventListener('click', function() {
            fetch(dashboardData.licenseDisconnectUrl || '/wsc/v1/license/disconnect', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })
              .then(() => window.location.reload());
          });
        }

        const cbBtn = document.getElementById('wsc-cb-save-btn');
        if (cbBtn) {
          cbBtn.addEventListener('click', function() {
            cbBtn.disabled = true;
            fetch(dashboardData.coBrandingSaveUrl || '/wsc/v1/cobranding/save', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                agency_name: (document.getElementById('wsc-cb-name') || {}).value || '',
                agency_email: (document.getElementById('wsc-cb-email') || {}).value || '',
                agency_url: (document.getElementById('wsc-cb-url') || {}).value || '',
                agency_logo: (document.getElementById('wsc-cb-logo') || {}).value || ''
              })
            }).then(() => window.location.reload());
          });
        }

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

        const syncBtn = document.getElementById('wsc-sync-now');
        if (syncBtn) {
          syncBtn.addEventListener('click', function() {
            const originalText = syncBtn.innerHTML;
            syncBtn.disabled = true;
            syncBtn.innerHTML = '&#x21bb; ' + Drupal.t('Syncing...');
            fetch(dashboardData.refreshUrl || '/wsc/v1/refresh', { method: 'POST', headers: { 'Content-Type': 'application/json' } })
              .then(res => res.json())
              .then(data => {
                syncBtn.innerHTML = '&#x2713; (' + (data.synced_events || 0) + ')';
                pollCardsUpdate();
                setTimeout(() => { syncBtn.disabled = false; syncBtn.innerHTML = originalText; }, 3000);
              })
              .catch(() => {
                syncBtn.innerHTML = originalText;
                syncBtn.disabled = false;
              });
          });
        }
      });
    }
  };
})(Drupal, drupalSettings, once);
