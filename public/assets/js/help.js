(function () {
    const layout = document.getElementById('help-layout');
    if (!layout) {
        return;
    }

    const defaultTopic = layout.getAttribute('data-default-topic') || '';
    const links = Array.from(layout.querySelectorAll('[data-help-topic]'));
    const articles = Array.from(layout.querySelectorAll('[data-help-article]'));
    const toc = layout.querySelector('.help-toc');

    const topicIds = links.map((link) => link.getAttribute('data-help-topic') || '').filter(Boolean);

    const topicFromHash = () => {
        const raw = window.location.hash.replace(/^#/, '');
        if (raw && topicIds.includes(raw)) {
            return raw;
        }
        return defaultTopic || topicIds[0] || '';
    };

    const showTopic = (topicId, options) => {
        const nextId = topicIds.includes(topicId) ? topicId : (defaultTopic || topicIds[0] || '');
        if (!nextId) {
            return;
        }

        links.forEach((link) => {
            const active = link.getAttribute('data-help-topic') === nextId;
            link.classList.toggle('is-active', active);
            if (active) {
                link.setAttribute('aria-current', 'true');
            } else {
                link.removeAttribute('aria-current');
            }
        });

        articles.forEach((article) => {
            const active = article.getAttribute('data-help-article') === nextId;
            article.classList.toggle('is-active', active);
            article.hidden = !active;
        });

        if (options && options.updateHash !== false) {
            const nextHash = '#' + nextId;
            if (window.location.hash !== nextHash) {
                const url = window.location.pathname + window.location.search + nextHash;
                if (options && options.replace) {
                    window.history.replaceState(null, '', url);
                } else {
                    window.history.pushState(null, '', url);
                }
            }
        }

        const article = layout.querySelector('[data-help-article="' + nextId + '"]');
        if (article && options && options.scrollArticle) {
            article.scrollIntoView({ block: 'start', behavior: 'smooth' });
        }
    };

    links.forEach((link) => {
        link.addEventListener('click', (event) => {
            const topicId = link.getAttribute('data-help-topic') || '';
            if (!topicId) {
                return;
            }
            event.preventDefault();
            showTopic(topicId, { updateHash: true, scrollArticle: window.matchMedia('(max-width: 900px)').matches });
            if (typeof link.focus === 'function') {
                link.focus();
            }
        });
    });

    window.addEventListener('hashchange', () => {
        showTopic(topicFromHash(), { updateHash: false });
    });

    window.addEventListener('popstate', () => {
        showTopic(topicFromHash(), { updateHash: false });
    });

    if (toc) {
        toc.addEventListener('keydown', (event) => {
            if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
                return;
            }
            const focused = document.activeElement;
            const index = links.indexOf(focused);
            if (index < 0) {
                return;
            }
            event.preventDefault();
            const delta = event.key === 'ArrowDown' ? 1 : -1;
            const next = links[index + delta];
            if (!next) {
                return;
            }
            next.focus();
            const topicId = next.getAttribute('data-help-topic') || '';
            showTopic(topicId, { updateHash: true, replace: true, scrollArticle: false });
        });
    }

    showTopic(topicFromHash(), { updateHash: true, replace: true, scrollArticle: false });
})();
