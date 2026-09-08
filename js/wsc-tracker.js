/**
 * WSC Tracker - Canonical Universal Analytics Client
 * Part of the World Search Council (WSC) Standard
 *
 * @version 1.5.0
 * @license GPL-2.0-or-later
 *
 * Compatible with Drupal 10/11, WordPress, and standalone CMS environments.
 */

(function(window, document) {
    'use strict';

    // Global WSC namespace
    window.WSC = window.WSC || {};

    // Read Drupal drupalSettings or window.WSC_CONFIG
    const drupalCfg = (window.drupalSettings && window.drupalSettings.wscConfig) ? window.drupalSettings.wscConfig : {};
    const cfg = Object.assign({}, window.WSC_CONFIG || {}, drupalCfg);

    const ENDPOINT = cfg.endpoint || '/wsc/v1/track';
    const MODE = (cfg.mode === 'consent') ? 'consent' : 'cookieless';
    const CONSENT_KEY = cfg.consentKey || 'wsc_consent';
    const AUTO = Object.assign({
        downloads: false,
        media: false,
        outbound: false,
        forms: false,
        scroll: false,
        timeOnPage: false,
        timeToInteraction: false,
        videoProgress: false,
        formFieldTiming: false,
    }, cfg.auto || {});

    let queue = [];
    let isSending = false;
    let pageviewSent = false;

    // --- Performance & Timing Helpers ---
    function now() {
        return (window.performance && performance.now) ? performance.now() : Date.now();
    }

    function throttle(fn, delay) {
        let lastCall = 0;
        return function(...args) {
            const t = Date.now();
            if (t - lastCall >= delay) {
                lastCall = t;
                fn.apply(this, args);
            }
        };
    }

    // --- Consent Management ---
    function readConsentStorage() {
        try {
            if (window.localStorage && localStorage.getItem(CONSENT_KEY) === '1') return true;
        } catch (e) {}
        try {
            return document.cookie.split(';').some(function(c) {
                return c.trim().indexOf(CONSENT_KEY + '=1') === 0;
            });
        } catch (e2) {}
        return false;
    }

    function writeConsentStorage(granted) {
        try {
            if (window.localStorage) {
                if (granted) localStorage.setItem(CONSENT_KEY, '1');
                else localStorage.removeItem(CONSENT_KEY);
            }
        } catch (e) {}
        try {
            if (granted) {
                document.cookie = CONSENT_KEY + '=1;path=/;max-age=31536000;SameSite=Lax';
            } else {
                document.cookie = CONSENT_KEY + '=;path=/;max-age=0;SameSite=Lax';
            }
        } catch (e2) {}
    }

    let trackingEnabled = (MODE !== 'consent') || readConsentStorage();

    // --- Queue & Batch Dispatcher ---
    function sendBatch(useBeacon = false) {
        if (queue.length === 0 || isSending) return;
        const dataToSend = [...queue];
        const payload = JSON.stringify({ events: dataToSend });

        if (useBeacon && navigator.sendBeacon) {
            const blob = new Blob([payload], { type: 'application/json' });
            if (navigator.sendBeacon(ENDPOINT, blob)) {
                queue = queue.filter(item => !dataToSend.includes(item));
            }
        } else {
            isSending = true;
            fetch(ENDPOINT, {
                method: 'POST',
                body: payload,
                keepalive: true,
                headers: { 'Content-Type': 'application/json' }
            })
            .then(response => {
                if (response.ok) {
                    queue = queue.filter(item => !dataToSend.includes(item));
                }
            })
            .catch(() => {})
            .finally(() => { isSending = false; });
        }
    }

    // --- Canonical Event Track Method ---
    function track(eventName, metaData = {}, customPath = null) {
        if (!trackingEnabled) return;

        const payload = {
            t: Math.floor(Date.now() / 1000),
            e: String(eventName || 'custom'),
            p: customPath || window.location.pathname,
            r: document.referrer || '',
            m: (typeof metaData === 'object' && metaData !== null) ? metaData : { value: metaData }
        };

        queue.push(payload);
        if (queue.length >= 10) sendBatch();
    }

    function firePageviewOnce() {
        if (pageviewSent || !trackingEnabled) return;
        pageviewSent = true;
        track('pageview', {
            title: document.title || '',
            lang: document.documentElement.lang || navigator.language || '',
            screen: (window.screen.width || 0) + 'x' + (window.screen.height || 0)
        });
    }

    // --- Public API ---
    window.WSC.track = track;
    window.WSC.flush = function() { sendBatch(false); };
    window.WSC.hasConsent = function() {
        return MODE !== 'consent' || trackingEnabled;
    };
    window.WSC.grantConsent = function() {
        writeConsentStorage(true);
        trackingEnabled = true;
        firePageviewOnce();
        sendBatch();
        try {
            document.dispatchEvent(new CustomEvent('wsc_consent_changed', { detail: { granted: true } }));
        } catch (e) {}
    };
    window.WSC.revokeConsent = function() {
        writeConsentStorage(false);
        trackingEnabled = false;
        queue = [];
        try {
            document.dispatchEvent(new CustomEvent('wsc_consent_changed', { detail: { granted: false } }));
        } catch (e) {}
    };

    // --- Consent Bridges (Klaro, Cookiebot, TCF 2.2, etc.) ---
    document.addEventListener('wsc_consent_granted', () => window.WSC.grantConsent());
    document.addEventListener('wsc_consent_revoked', () => window.WSC.revokeConsent());

    window.addEventListener('CookiebotOnAccept', function() {
        if (window.Cookiebot && window.Cookiebot.consent && window.Cookiebot.consent.statistics) {
            window.WSC.grantConsent();
        }
    });

    // === Declarative Tracking (data-wsc-track) ===
    document.addEventListener('click', function(e) {
        const el = e.target.closest('[data-wsc-track]');
        if (!el) return;

        const eventName = el.getAttribute('data-wsc-track') || 'click';
        let meta = {};
        try {
            const metaAttr = el.getAttribute('data-wsc-meta');
            if (metaAttr) meta = JSON.parse(metaAttr);
        } catch (err) {}

        if (el.tagName === 'A' && el.href) {
            meta.href = el.href;
            if (!meta.text) meta.text = el.textContent.trim().substring(0, 100);
        }

        track(eventName, meta);
    }, true);

    // 1. File Downloads
    if (AUTO.downloads) {
        document.addEventListener('click', function(e) {
            const link = e.target.closest('a[href]');
            if (!link) return;

            const href = link.href || '';
            const downloadAttr = link.hasAttribute('download');
            const fileExt = href.split('.').pop().toLowerCase();

            const commonExts = ['pdf','zip','rar','7z','doc','docx','xls','xlsx','ppt','pptx','csv','mp3','mp4','mov','avi','jpg','png','svg','webp'];
            if (downloadAttr || commonExts.includes(fileExt)) {
                track('download', {
                    href: href,
                    filename: href.split('/').pop(),
                    extension: fileExt,
                    text: link.textContent.trim().substring(0, 80)
                });
            }
        }, true);
    }

    // 2. HTML5 Media (Video / Audio)
    if (AUTO.media) {
        const mediaPlaySent = (typeof WeakSet !== 'undefined') ? new WeakSet() : null;
        const mediaPlaySentFallback = mediaPlaySent ? null : [];

        function markPlaySent(media) {
            if (mediaPlaySent) mediaPlaySent.add(media);
            else if (mediaPlaySentFallback.indexOf(media) === -1) mediaPlaySentFallback.push(media);
        }
        function hasPlaySent(media) {
            if (mediaPlaySent) return mediaPlaySent.has(media);
            return mediaPlaySentFallback.indexOf(media) !== -1;
        }

        function attachMediaListeners(media) {
            if (!media || media.__wscMediaBound) return;
            media.__wscMediaBound = true;

            media.addEventListener('play', function() {
                if (hasPlaySent(media)) return;
                markPlaySent(media);
                track('video_play', {
                    src: media.currentSrc || media.src,
                    title: media.title || ''
                });
            });

            media.addEventListener('ended', function() {
                track('video_complete', {
                    src: media.currentSrc || media.src,
                    duration: Math.round(media.duration || 0)
                });
            });
        }

        document.querySelectorAll('video, audio').forEach(attachMediaListeners);
    }

    // 3. Outbound Links
    if (AUTO.outbound) {
        document.addEventListener('click', function(e) {
            const link = e.target.closest('a[href]');
            if (!link) return;

            try {
                const url = new URL(link.href, location.origin);
                if (url.hostname !== location.hostname) {
                    track('outbound_click', {
                        href: link.href,
                        hostname: url.hostname,
                        text: (link.textContent || '').trim().substring(0, 80)
                    });
                }
            } catch (err) {}
        }, true);
    }

    // 4. Forms
    document.addEventListener('submit', function(e) {
        const form = e.target;
        if (!form || form.tagName !== 'FORM') return;

        const trackAttr = form.getAttribute('data-wsc-track');
        const hasTrackedChild = !!form.querySelector('[data-wsc-track]');
        const shouldAuto = AUTO.forms;

        if (trackAttr || hasTrackedChild || shouldAuto) {
            const meta = {
                action: form.action || window.location.href,
                method: (form.method || 'get').toLowerCase(),
                source: 'js'
            };

            const formId = form.getAttribute('data-form-id') || form.id || '';
            if (formId) meta.form_id = formId;
            const formName = form.getAttribute('name') || form.getAttribute('data-form-title') || '';
            if (formName) meta.form_title = formName;

            track(trackAttr || 'form_submit', meta);
        }
    }, true);

    // 5. Time on Page & Engaged Active Time
    if (AUTO.timeOnPage) {
        const pageStart = now();
        let totalActive = 0;
        let lastActiveStart = now();
        let lastActivity = now();
        let isActive = true;
        let finalSent = false;
        let lastHeartbeatAt = 0;
        const INACTIVITY_TIMEOUT = 30000;
        const HEARTBEAT_MS = 60000;

        const accumulateActive = (t) => {
            if (isActive && document.visibilityState === 'visible') {
                if (t - lastActivity > INACTIVITY_TIMEOUT) {
                    totalActive += Math.max(0, lastActivity - lastActiveStart);
                    isActive = false;
                } else {
                    totalActive += Math.max(0, t - lastActiveStart);
                    lastActiveStart = t;
                }
            }
        };

        const updateActivity = () => {
            const t = now();
            lastActivity = t;
            if (!isActive && document.visibilityState === 'visible') {
                isActive = true;
                lastActiveStart = t;
            }
        };

        const sendTimeOnPage = (isFinal, exitVia) => {
            if (finalSent && isFinal) return;
            if (finalSent && !isFinal) return;

            const t = now();
            accumulateActive(t);

            if (isFinal) {
                finalSent = true;
            } else {
                lastHeartbeatAt = t;
            }

            track('time_on_page', {
                duration_ms: Math.round(t - pageStart),
                active_ms: Math.max(0, Math.round(totalActive)),
                final: !!isFinal,
                exit_via: exitVia || (isFinal ? 'unload' : 'heartbeat')
            });

            if (isFinal) {
                sendBatch(true);
            }
        };

        ['mousemove', 'keydown', 'scroll', 'touchstart', 'click'].forEach(evt => {
            document.addEventListener(evt, updateActivity, { passive: true });
        });

        window.addEventListener('pagehide', () => sendTimeOnPage(true, 'pagehide'));
        window.addEventListener('beforeunload', () => sendTimeOnPage(true, 'beforeunload'));
    }

    // --- Bootstrap & Initial Pageview ---
    firePageviewOnce();
    setInterval(() => sendBatch(false), 5000);
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'hidden') sendBatch(true);
    });

})(window, document);
