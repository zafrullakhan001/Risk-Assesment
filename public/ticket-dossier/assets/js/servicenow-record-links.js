(function () {
    'use strict';

    var INSTANCE_STORAGE_KEY = 'ticketDossier.servicenow.instanceUrl';

    function storedInstance() {
        try {
            return window.localStorage.getItem(INSTANCE_STORAGE_KEY) || '';
        } catch (error) {
            return '';
        }
    }

    function normalizeOrigin(raw) {
        var value = String(raw || '').trim();
        if (!value) {
            return '';
        }
        if (!/^https?:\/\//i.test(value)) {
            value = 'https://' + value;
        }
        try {
            var parsed = new URL(value);
            if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
                return '';
            }
            return parsed.origin;
        } catch (error) {
            return '';
        }
    }

    function tableFor(kind, table, number) {
        var name = String(table || '').trim().toLowerCase();
        if (/^[a-z][a-z0-9_]*$/.test(name)) {
            return name;
        }
        var resolved = String(kind || '').trim().toLowerCase();
        if (!resolved) {
            var upper = String(number || '').toUpperCase();
            if (upper.indexOf('DMND') === 0) resolved = 'demand';
            else if (upper.indexOf('STRY') === 0) resolved = 'story';
            else if (upper.indexOf('DDR') === 0) resolved = 'ddr';
            else resolved = 'task';
        }
        if (resolved === 'demand') return 'dmn_demand';
        if (resolved === 'story') return 'rm_story';
        if (resolved === 'ddr') return 'sn_tprm_dd_request';
        return 'task';
    }

    function recordUrl(instance, number, sysId, table, kind) {
        var origin = normalizeOrigin(instance);
        var ticket = String(number || '').trim().toUpperCase();
        if (!origin || !/^(DMND|STRY|TASK|DDR)\d+$/.test(ticket)) {
            return '';
        }
        var uri = tableFor(kind, table, ticket) + '.do';
        var id = String(sysId || '').trim().toLowerCase();
        if (/^[0-9a-f]{32}$/.test(id)) {
            uri += '?sys_id=' + encodeURIComponent(id);
        } else {
            uri += '?sysparm_query=number=' + encodeURIComponent(ticket);
        }
        return origin + '/nav_to.do?uri=' + uri;
    }

    function instanceFor(el) {
        return normalizeOrigin(
            el.getAttribute('data-sn-instance')
            || document.documentElement.getAttribute('data-sn-instance')
            || storedInstance()
        );
    }

    function copyData(from, to) {
        ['data-sn-number', 'data-sn-kind', 'data-sn-sys-id', 'data-sn-table', 'data-sn-instance'].forEach(function (name) {
            var value = from.getAttribute(name);
            if (value) {
                to.setAttribute(name, value);
            }
        });
    }

    function upgrade(el) {
        if (!(el instanceof HTMLElement)) {
            return;
        }
        var number = el.getAttribute('data-sn-number') || '';
        var href = recordUrl(
            instanceFor(el),
            number,
            el.getAttribute('data-sn-sys-id') || '',
            el.getAttribute('data-sn-table') || '',
            el.getAttribute('data-sn-kind') || ''
        );
        if (!href) {
            return;
        }
        if (el.tagName === 'A') {
            if (!el.getAttribute('href')) {
                el.setAttribute('href', href);
            }
            el.setAttribute('target', '_blank');
            el.setAttribute('rel', 'noopener noreferrer');
            if (!el.getAttribute('title')) {
                el.setAttribute('title', 'Open ' + number + ' in ServiceNow');
            }
            return;
        }
        var link = document.createElement('a');
        link.className = el.className;
        link.href = href;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.title = el.getAttribute('title') || ('Open ' + number + ' in ServiceNow');
        copyData(el, link);
        while (el.firstChild) {
            link.appendChild(el.firstChild);
        }
        el.replaceWith(link);
    }

    function enhance() {
        document.querySelectorAll('[data-sn-number]').forEach(upgrade);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', enhance);
    } else {
        enhance();
    }
})();
