(function () {
    const layout = document.getElementById('help-layout');
    const toolbar = document.getElementById('help-reader-toolbar');
    if (!layout || !toolbar) {
        return;
    }

    const FONT_KEY = 'ra-help-font';
    const VOICE_KEY = 'ra-help-voice';
    const FONT_OPTIONS = [
        'app', 'clear', 'lexend', 'rounded', 'figtree', 'arial', 'verdana', 'tahoma', 'trebuchet', 'impact',
        'serif', 'literata', 'merriweather', 'times', 'georgia',
        'courier',
        'dancing', 'caveat', 'great-vibes', 'patrick', 'comic',
    ];

    const FONT_STACKS = {
        app: '"Source Sans 3", sans-serif',
        clear: '"Atkinson Hyperlegible", Tahoma, sans-serif',
        lexend: 'Lexend, sans-serif',
        rounded: 'Nunito, sans-serif',
        figtree: 'Figtree, sans-serif',
        arial: 'Arial, Helvetica, sans-serif',
        verdana: 'Verdana, Geneva, sans-serif',
        tahoma: 'Tahoma, "Segoe UI", sans-serif',
        trebuchet: '"Trebuchet MS", "Segoe UI", sans-serif',
        impact: 'Impact, Haettenschweiler, sans-serif',
        serif: '"Source Serif 4", Georgia, serif',
        literata: 'Literata, Georgia, serif',
        merriweather: 'Merriweather, Georgia, serif',
        times: '"Times New Roman", Times, serif',
        georgia: 'Georgia, "Times New Roman", serif',
        courier: '"Courier New", Courier, monospace',
        dancing: '"Dancing Script", cursive',
        caveat: 'Caveat, cursive',
        'great-vibes': '"Great Vibes", cursive',
        patrick: '"Patrick Hand", cursive',
        comic: '"Comic Sans MS", cursive',
    };

    const voiceSelect = document.getElementById('help-voice-select');
    const fontSelect = document.getElementById('help-font-select');
    const fontPicker = document.getElementById('help-font-picker');
    const fontTrigger = document.getElementById('help-font-picker-trigger');
    const fontMenu = document.getElementById('help-font-picker-menu');
    const fontOptions = fontMenu
        ? Array.from(fontMenu.querySelectorAll('[data-help-font]'))
        : [];
    const playBtn = document.getElementById('help-reader-play');
    const pauseBtn = document.getElementById('help-reader-pause');
    const stopBtn = document.getElementById('help-reader-stop');
    const statusEl = document.getElementById('help-reader-status');

    /** @type {{ voice: SpeechSynthesisVoice, gender: 'female'|'male', label: string }[]} */
    let curatedVoices = [];
    /** @type {HTMLElement[]} */
    let speakQueue = [];
    let queueIndex = 0;
    let isSpeaking = false;
    let isPaused = false;
    let utteranceToken = 0;

    const setStatus = (message) => {
        if (statusEl) {
            statusEl.textContent = message || '';
        }
    };

    const closeFontMenu = () => {
        if (!fontMenu || !fontTrigger) {
            return;
        }
        fontMenu.hidden = true;
        fontTrigger.setAttribute('aria-expanded', 'false');
    };

    const openFontMenu = () => {
        if (!fontMenu || !fontTrigger) {
            return;
        }
        fontMenu.hidden = false;
        fontTrigger.setAttribute('aria-expanded', 'true');
        const selected = fontMenu.querySelector('[aria-selected="true"]');
        if (selected && typeof selected.focus === 'function') {
            selected.focus();
        }
    };

    const applyFont = (font) => {
        const next = FONT_OPTIONS.includes(font) ? font : 'app';
        document.documentElement.setAttribute('data-help-font', next);
        layout.setAttribute('data-help-font', next);
        if (fontSelect && fontSelect.value !== next) {
            fontSelect.value = next;
        }
        const stack = FONT_STACKS[next] || FONT_STACKS.app;
        let label = next;
        fontOptions.forEach((option) => {
            const active = option.getAttribute('data-help-font') === next;
            option.setAttribute('aria-selected', active ? 'true' : 'false');
            option.classList.toggle('is-active', active);
            if (active) {
                label = (option.textContent || next).trim();
            }
        });
        if (fontTrigger) {
            fontTrigger.textContent = label;
            fontTrigger.style.fontFamily = stack;
        }
        try {
            localStorage.setItem(FONT_KEY, next);
        } catch (e) { /* ignore */ }
    };

    const savedFont = (() => {
        try {
            return localStorage.getItem(FONT_KEY) || 'app';
        } catch (e) {
            return 'app';
        }
    })();
    applyFont(savedFont);

    if (fontTrigger && fontMenu) {
        fontTrigger.addEventListener('click', () => {
            if (fontMenu.hidden) {
                openFontMenu();
            } else {
                closeFontMenu();
            }
        });

        fontOptions.forEach((option) => {
            option.addEventListener('click', () => {
                applyFont(option.getAttribute('data-help-font') || 'app');
                closeFontMenu();
                fontTrigger.focus();
            });
        });

        fontMenu.addEventListener('keydown', (event) => {
            const current = document.activeElement;
            const index = fontOptions.indexOf(current);
            if (event.key === 'Escape') {
                event.preventDefault();
                closeFontMenu();
                fontTrigger.focus();
                return;
            }
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (index < 0) {
                    fontOptions[0]?.focus();
                    return;
                }
                const delta = event.key === 'ArrowDown' ? 1 : -1;
                const next = fontOptions[index + delta];
                if (next) {
                    next.focus();
                }
                return;
            }
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                if (current && current.hasAttribute('data-help-font')) {
                    applyFont(current.getAttribute('data-help-font') || 'app');
                    closeFontMenu();
                    fontTrigger.focus();
                }
            }
        });

        document.addEventListener('click', (event) => {
            if (!fontPicker || fontMenu.hidden) {
                return;
            }
            if (!fontPicker.contains(event.target)) {
                closeFontMenu();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && fontMenu && !fontMenu.hidden) {
                closeFontMenu();
            }
        });
    } else if (fontSelect) {
        fontSelect.addEventListener('change', () => {
            applyFont(fontSelect.value || 'app');
        });
    }

    const FEMALE_PRIORITY = [
        'Microsoft Aria',
        'Microsoft Jenny',
        'Microsoft Zira',
        'Microsoft Michelle',
        'Microsoft Ana',
        'Microsoft Sara',
        'Samantha',
        'Victoria',
        'Karen',
        'Google US English',
    ];
    const MALE_PRIORITY = [
        'Microsoft Guy',
        'Microsoft Andrew',
        'Microsoft David',
        'Microsoft Mark',
        'Microsoft Eric',
        'Microsoft Christopher',
        'Alex',
        'Fred',
        'Daniel',
        'Google US English Male',
    ];

    const FEMALE_HINTS = [
        'aria', 'jenny', 'zira', 'samantha', 'michelle', 'victoria', 'ana', 'sara',
        'susan', 'linda', 'karen', 'female', 'woman', 'girl',
    ];
    const MALE_HINTS = [
        'guy', 'andrew', 'david', 'mark', 'alex', 'eric', 'christopher', 'fred',
        'daniel', 'james', 'male', 'man', 'boy',
    ];

    const normalizeLang = (lang) => String(lang || '').toLowerCase().replace('_', '-');

    const isUsEnglish = (voice) => {
        const lang = normalizeLang(voice.lang);
        return lang === 'en-us' || lang.startsWith('en-us-');
    };

    const detectGender = (voice) => {
        const name = String(voice.name || '').toLowerCase();
        const uri = String(voice.voiceURI || '').toLowerCase();
        const hay = name + ' ' + uri;

        for (const hint of FEMALE_HINTS) {
            if (hay.includes(hint)) {
                return 'female';
            }
        }
        for (const hint of MALE_HINTS) {
            if (hay.includes(hint)) {
                return 'male';
            }
        }

        // Chrome's default "Google US English" is typically female-sounding.
        if (name === 'google us english' || name.includes('google us english')) {
            return 'female';
        }

        return null;
    };

    const priorityIndex = (name, list) => {
        const lower = String(name || '').toLowerCase();
        for (let i = 0; i < list.length; i += 1) {
            if (lower.includes(list[i].toLowerCase())) {
                return i;
            }
        }
        return 1000 + lower.length;
    };

    const shortLabel = (voice) => {
        let name = String(voice.name || 'Voice').replace(/\s+/g, ' ').trim();
        name = name
            .replace(/^Microsoft\s+/i, '')
            .replace(/\s+Online\s*\(Natural\)\s*-?\s*English\s*\(United States\)/i, '')
            .replace(/\s*-\s*English\s*\(United States\)/i, '')
            .replace(/\s*\(United States\)/i, '')
            .replace(/\s+Desktop$/i, '')
            .trim();
        return name || voice.name || 'Voice';
    };

    const pickTop = (voices, gender, priorityList, limit) => {
        const scored = voices
            .filter((v) => detectGender(v) === gender)
            .map((v) => ({ voice: v, score: priorityIndex(v.name, priorityList) }))
            .sort((a, b) => a.score - b.score || a.voice.name.localeCompare(b.voice.name));

        const picked = [];
        const seen = new Set();
        for (const item of scored) {
            const key = shortLabel(item.voice).toLowerCase();
            if (seen.has(key)) {
                continue;
            }
            seen.add(key);
            picked.push({
                voice: item.voice,
                gender,
                label: shortLabel(item.voice),
            });
            if (picked.length >= limit) {
                break;
            }
        }
        return picked;
    };

    const refreshVoices = () => {
        if (!('speechSynthesis' in window) || typeof SpeechSynthesisUtterance === 'undefined') {
            curatedVoices = [];
            if (voiceSelect) {
                voiceSelect.innerHTML = '';
                const opt = document.createElement('option');
                opt.value = '';
                opt.textContent = 'Not supported in this browser';
                voiceSelect.appendChild(opt);
                voiceSelect.disabled = true;
            }
            if (playBtn) {
                playBtn.disabled = true;
            }
            setStatus('Read aloud needs a browser with speech synthesis.');
            return;
        }

        const all = window.speechSynthesis.getVoices().filter(isUsEnglish);
        const female = pickTop(all, 'female', FEMALE_PRIORITY, 4);
        const male = pickTop(all, 'male', MALE_PRIORITY, 4);
        curatedVoices = female.concat(male);

        if (!voiceSelect) {
            return;
        }

        const previous = (() => {
            try {
                return localStorage.getItem(VOICE_KEY) || '';
            } catch (e) {
                return '';
            }
        })();

        voiceSelect.innerHTML = '';

        if (curatedVoices.length === 0) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = 'No US English voices found';
            voiceSelect.appendChild(opt);
            voiceSelect.disabled = true;
            if (playBtn) {
                playBtn.disabled = true;
            }
            setStatus('Install a US English voice in your OS or browser to use Read aloud.');
            return;
        }

        voiceSelect.disabled = false;
        if (playBtn && !isSpeaking) {
            playBtn.disabled = false;
        }

        const addGroup = (label, items) => {
            if (items.length === 0) {
                return;
            }
            const group = document.createElement('optgroup');
            group.label = label;
            items.forEach((item) => {
                const opt = document.createElement('option');
                opt.value = item.voice.voiceURI || item.voice.name;
                opt.textContent = item.label;
                group.appendChild(opt);
            });
            voiceSelect.appendChild(group);
        };

        addGroup('Female (US)', female);
        addGroup('Male (US)', male);

        const match = curatedVoices.find((item) => {
            const key = item.voice.voiceURI || item.voice.name;
            return key === previous || item.voice.name === previous;
        });
        if (match) {
            voiceSelect.value = match.voice.voiceURI || match.voice.name;
        } else if (curatedVoices[0]) {
            voiceSelect.value = curatedVoices[0].voice.voiceURI || curatedVoices[0].voice.name;
        }

        if (!statusEl.textContent || statusEl.textContent.indexOf('Loading') !== -1 || statusEl.textContent.indexOf('Install') !== -1) {
            setStatus('US English voices ready — pick a voice and press Play.');
        }
    };

    const selectedVoice = () => {
        if (!voiceSelect || !voiceSelect.value) {
            return curatedVoices[0] ? curatedVoices[0].voice : null;
        }
        const found = curatedVoices.find((item) => {
            const key = item.voice.voiceURI || item.voice.name;
            return key === voiceSelect.value;
        });
        return found ? found.voice : (curatedVoices[0] ? curatedVoices[0].voice : null);
    };

    if (voiceSelect) {
        voiceSelect.addEventListener('change', () => {
            try {
                localStorage.setItem(VOICE_KEY, voiceSelect.value || '');
            } catch (e) { /* ignore */ }

            // Mid-read voice switch: keep the current line, continue with the new voice.
            if ((isSpeaking || isPaused) && speakQueue.length > 0) {
                const resumeIndex = queueIndex;
                const keepPaused = isPaused;
                utteranceToken += 1;
                if ('speechSynthesis' in window) {
                    try {
                        if (window.speechSynthesis.paused) {
                            window.speechSynthesis.resume();
                        }
                    } catch (e) { /* ignore */ }
                    window.speechSynthesis.cancel();
                }
                queueIndex = resumeIndex;
                isSpeaking = true;
                isPaused = false;
                updateTransport();
                setStatus(keepPaused ? 'Voice changed — press Resume to continue.' : 'Voice changed — continuing…');
                if (keepPaused) {
                    // Re-highlight current line; wait for Resume.
                    clearHighlight();
                    const el = speakQueue[queueIndex];
                    if (el) {
                        el.classList.add('is-speaking');
                    }
                    isPaused = true;
                    updateTransport();
                } else {
                    window.setTimeout(() => {
                        if (isSpeaking && !isPaused) {
                            speakNext();
                        }
                    }, 40);
                }
            }
        });
    }

    const clearHighlight = () => {
        layout.querySelectorAll('.is-speaking').forEach((el) => {
            el.classList.remove('is-speaking');
        });
    };

    const updateTransport = () => {
        if (!playBtn || !pauseBtn || !stopBtn) {
            return;
        }
        const hasVoices = curatedVoices.length > 0;
        playBtn.disabled = !hasVoices || isSpeaking;
        pauseBtn.disabled = !isSpeaking;
        stopBtn.disabled = !isSpeaking && !isPaused;
        const pauseIcon = pauseBtn.querySelector('.help-reader-icon-pause');
        const resumeIcon = pauseBtn.querySelector('.help-reader-icon-resume');
        if (pauseIcon && resumeIcon) {
            pauseIcon.hidden = isPaused;
            resumeIcon.hidden = !isPaused;
        }
        pauseBtn.setAttribute('aria-label', isPaused ? 'Resume' : 'Pause');
        pauseBtn.title = isPaused ? 'Resume reading' : 'Pause reading';
        pauseBtn.classList.toggle('is-resume', isPaused);
    };

    const activeArticle = () => layout.querySelector('.help-article.is-active:not([hidden]), [data-help-article].is-active:not([hidden])')
        || layout.querySelector('[data-help-article]:not([hidden])');

    const collectChunks = (article) => {
        if (!article) {
            return [];
        }
        /** @type {HTMLElement[]} */
        const chunks = [];
        const kicker = article.querySelector('.help-article-kicker');
        const title = article.querySelector('h2');
        const body = article.querySelector('.help-article-body');
        if (kicker && (kicker.textContent || '').trim()) {
            chunks.push(kicker);
        }
        if (title && (title.textContent || '').trim()) {
            chunks.push(title);
        }
        if (body) {
            Array.from(body.children).forEach((child) => {
                if (!(child instanceof HTMLElement)) {
                    return;
                }
                if (child.tagName === 'UL' || child.tagName === 'OL') {
                    Array.from(child.children).forEach((li) => {
                        if (li instanceof HTMLElement && (li.textContent || '').trim()) {
                            chunks.push(li);
                        }
                    });
                    return;
                }
                if ((child.textContent || '').trim()) {
                    chunks.push(child);
                }
            });
        }
        return chunks;
    };

    const stopSpeaking = (options) => {
        const silent = options && options.silent;
        utteranceToken += 1;
        if ('speechSynthesis' in window) {
            // Some browsers leave synthesis stuck in paused unless resumed before cancel.
            try {
                if (window.speechSynthesis.paused) {
                    window.speechSynthesis.resume();
                }
            } catch (e) { /* ignore */ }
            window.speechSynthesis.cancel();
        }
        isSpeaking = false;
        isPaused = false;
        queueIndex = 0;
        speakQueue = [];
        clearHighlight();
        updateTransport();
        if (!silent) {
            setStatus(curatedVoices.length ? 'Stopped.' : (statusEl ? statusEl.textContent : ''));
        }
    };

    const speakNext = () => {
        if (!('speechSynthesis' in window)) {
            return;
        }
        if (queueIndex >= speakQueue.length) {
            isSpeaking = false;
            isPaused = false;
            clearHighlight();
            updateTransport();
            setStatus('Finished reading this topic.');
            return;
        }

        const el = speakQueue[queueIndex];
        clearHighlight();
        el.classList.add('is-speaking');
        try {
            el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        } catch (e) { /* ignore */ }

        const text = (el.innerText || el.textContent || '').replace(/\s+/g, ' ').trim();
        if (!text) {
            queueIndex += 1;
            speakNext();
            return;
        }

        const token = utteranceToken;
        const utter = new SpeechSynthesisUtterance(text);
        utter.lang = 'en-US';
        const voice = selectedVoice();
        if (voice) {
            utter.voice = voice;
            utter.lang = voice.lang || 'en-US';
        }

        utter.onend = () => {
            if (token !== utteranceToken || !isSpeaking) {
                return;
            }
            queueIndex += 1;
            speakNext();
        };
        utter.onerror = () => {
            if (token !== utteranceToken || !isSpeaking) {
                return;
            }
            // Interrupted by cancel (voice switch / stop) — do not advance.
            if (window.speechSynthesis.speaking || window.speechSynthesis.pending) {
                return;
            }
        };

        window.speechSynthesis.speak(utter);
    };

    const startSpeaking = () => {
        if (!('speechSynthesis' in window) || curatedVoices.length === 0) {
            setStatus('No US English voices available.');
            return;
        }

        const article = activeArticle();
        const chunks = collectChunks(article);
        if (chunks.length === 0) {
            setStatus('Nothing to read on this topic.');
            return;
        }

        utteranceToken += 1;
        window.speechSynthesis.cancel();
        speakQueue = chunks;
        queueIndex = 0;
        isSpeaking = true;
        isPaused = false;
        updateTransport();
        setStatus('Reading…');
        speakNext();
    };

    const togglePause = () => {
        if (!('speechSynthesis' in window) || !isSpeaking) {
            return;
        }
        if (isPaused) {
            isPaused = false;
            updateTransport();
            // After a mid-read voice change, cancel left nothing to resume — restart current line.
            if (!window.speechSynthesis.speaking && !window.speechSynthesis.pending && !window.speechSynthesis.paused) {
                setStatus('Reading…');
                speakNext();
            } else {
                window.speechSynthesis.resume();
                setStatus('Reading…');
            }
        } else {
            window.speechSynthesis.pause();
            isPaused = true;
            setStatus('Paused.');
            updateTransport();
        }
    };

    if (playBtn) {
        playBtn.addEventListener('click', () => {
            startSpeaking();
        });
    }
    if (pauseBtn) {
        pauseBtn.addEventListener('click', () => {
            togglePause();
        });
    }
    if (stopBtn) {
        stopBtn.addEventListener('click', () => {
            stopSpeaking();
        });
    }

    // Stop when the visible topic changes (TOC click, hash, or article swap).
    let lastTopic = '';
    const watchTopic = () => {
        const article = activeArticle();
        const topicId = article ? (article.getAttribute('data-help-article') || article.id || '') : '';
        if (topicId && topicId !== lastTopic) {
            if (isSpeaking || isPaused) {
                stopSpeaking({ silent: true });
                setStatus('Stopped — topic changed.');
            }
            lastTopic = topicId;
        }
    };

    layout.querySelectorAll('[data-help-topic]').forEach((link) => {
        link.addEventListener('click', () => {
            if (isSpeaking || isPaused) {
                stopSpeaking({ silent: true });
                setStatus('Stopped — topic changed.');
            }
        });
    });

    const topicObserver = new MutationObserver(() => {
        watchTopic();
    });
    topicObserver.observe(layout, {
        attributes: true,
        subtree: true,
        attributeFilter: ['hidden', 'class'],
    });
    window.addEventListener('hashchange', watchTopic);
    window.addEventListener('popstate', watchTopic);
    lastTopic = (activeArticle() && (activeArticle().getAttribute('data-help-article') || activeArticle().id)) || '';

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden' && (isSpeaking || isPaused)) {
            stopSpeaking({ silent: true });
        }
    });
    window.addEventListener('pagehide', () => {
        stopSpeaking({ silent: true });
    });

    updateTransport();
    setStatus('Loading voices…');
    refreshVoices();

    if ('speechSynthesis' in window) {
        window.speechSynthesis.addEventListener('voiceschanged', refreshVoices);
        // Some browsers populate voices asynchronously without firing reliably.
        window.setTimeout(refreshVoices, 250);
        window.setTimeout(refreshVoices, 1000);
    }
})();
