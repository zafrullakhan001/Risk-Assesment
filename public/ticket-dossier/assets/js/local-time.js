(function () {
    'use strict';

    var SELECTOR = 'time.js-local-time[datetime]';

    /**
     * Parse an explicit UTC datetime attribute into a Date.
     * Accepts ISO-8601 with Z/offset, or naive "YYYY-MM-DD HH:MM:SS" treated as UTC.
     */
    function parseUtc(value) {
        if (!value || typeof value !== 'string') {
            return null;
        }
        var raw = value.trim();
        if (raw === '') {
            return null;
        }

        // Prefer ISO forms the browser understands reliably.
        if (/^\d{4}-\d{2}-\d{2}T/.test(raw)) {
            var iso = new Date(raw);
            return isNaN(iso.getTime()) ? null : iso;
        }

        // Naive SQLite-style UTC: "YYYY-MM-DD HH:MM:SS"
        var match = raw.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/);
        if (!match) {
            return null;
        }
        var parsed = new Date(Date.UTC(
            parseInt(match[1], 10),
            parseInt(match[2], 10) - 1,
            parseInt(match[3], 10),
            parseInt(match[4], 10),
            parseInt(match[5], 10),
            parseInt(match[6], 10)
        ));
        return isNaN(parsed.getTime()) ? null : parsed;
    }

    function formatLocal(date) {
        try {
            return new Intl.DateTimeFormat(undefined, {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                timeZoneName: 'short'
            }).format(date);
        } catch (err) {
            // Fallback for older environments without Intl options support.
            return date.toLocaleString();
        }
    }

    function localizeElement(el) {
        var utcAttr = el.getAttribute('datetime') || '';
        var date = parseUtc(utcAttr);
        if (!date) {
            return;
        }
        var local = formatLocal(date);
        if (!local) {
            return;
        }
        el.textContent = local;
        el.setAttribute('title', 'Stored as UTC: ' + utcAttr.replace('T', ' ').replace(/Z$/, '') + ' UTC');
        el.classList.add('is-local');
    }

    function localizeAll(root) {
        var scope = root && root.querySelectorAll ? root : document;
        var nodes = scope.querySelectorAll(SELECTOR);
        for (var i = 0; i < nodes.length; i++) {
            localizeElement(nodes[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            localizeAll(document);
        });
    } else {
        localizeAll(document);
    }

    window.TicketDossierLocalTime = {
        localizeAll: localizeAll,
        parseUtc: parseUtc,
        formatLocal: formatLocal
    };
})();
