/**
 * LinkNest-style fuzzy / phonetic search (ported from linknest/utils/fuzzySearch.ts).
 * Exposed as window.FuzzySearch for SharePoint catalog live search.
 *
 * Performance: token/pair caches, AND fail-fast, unique-token scoring, and
 * cheap substring prefilters so Fuzzy + Deep files stays usable on large catalogs.
 */
(() => {
  const EMPTY_SCORE = { matched: false, score: 0, kind: 'none' };
  const TOKEN_CACHE_MAX = 8000;
  const PAIR_CACHE_MAX = 14000;
  const tokenCache = new Map();
  const soundexCache = new Map();
  const phoneticCache = new Map();
  const pairCache = new Map();

  let levPrev = new Array(64);
  let levCurr = new Array(64);

  function cacheSet(map, key, value, max) {
    if (map.size >= max) map.clear();
    map.set(key, value);
    return value;
  }

  function splitSearchTokens(text) {
    const raw = String(text || '').toLowerCase();
    if (!raw) return [];
    return raw.split(/[^a-z0-9]+/).filter(Boolean);
  }

  function tokenizeSearchText(text) {
    const raw = String(text || '');
    if (!raw) return [];
    const cached = tokenCache.get(raw);
    if (cached) return cached;

    const tokens = new Set();
    const push = (value) => {
      const token = String(value || '')
        .toLowerCase()
        .replace(/[^a-z0-9]/g, '');
      if (token) tokens.add(token);
    };

    raw.split(/[^A-Za-z0-9]+/).forEach((part) => {
      if (!part) return;
      push(part);
      part
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replace(/([A-Z]+)([A-Z][a-z])/g, '$1 $2')
        .replace(/([a-zA-Z])([0-9])/g, '$1 $2')
        .replace(/([0-9])([a-zA-Z])/g, '$1 $2')
        .split(/\s+/)
        .forEach(push);
    });

    return cacheSet(tokenCache, raw, Array.from(tokens), TOKEN_CACHE_MAX);
  }

  function soundex(word) {
    const cleaned = String(word || '')
      .toLowerCase()
      .replace(/[^a-z]/g, '');
    if (!cleaned) return '';
    const cached = soundexCache.get(cleaned);
    if (cached !== undefined) return cached;

    const codes = {
      b: '1',
      f: '1',
      p: '1',
      v: '1',
      c: '2',
      g: '2',
      j: '2',
      k: '2',
      q: '2',
      s: '2',
      x: '2',
      z: '2',
      d: '3',
      t: '3',
      l: '4',
      m: '5',
      n: '5',
      r: '6',
    };

    let result = cleaned[0].toUpperCase();
    let previous = codes[cleaned[0]] || '';

    for (let i = 1; i < cleaned.length && result.length < 4; i++) {
      const code = codes[cleaned[i]] || '';
      if (code && code !== previous) result += code;
      previous = code;
    }

    return cacheSet(soundexCache, cleaned, result.padEnd(4, '0'), TOKEN_CACHE_MAX);
  }

  function phoneticKey(word) {
    const source = String(word || '')
      .toLowerCase()
      .replace(/[^a-z]/g, '');
    if (!source) return '';
    const cached = phoneticCache.get(source);
    if (cached !== undefined) return cached;

    let value = source
      .replace(/^kn/, 'n')
      .replace(/^gn/, 'n')
      .replace(/^pn/, 'n')
      .replace(/^wr/, 'r')
      .replace(/^ae/, 'e')
      .replace(/ph/g, 'f')
      .replace(/gh/g, 'g')
      .replace(/ck/g, 'k')
      .replace(/q/g, 'k')
      .replace(/x/g, 'ks')
      .replace(/[aeiouy]/g, '');

    return cacheSet(phoneticCache, source, value.replace(/(.)\1+/g, '$1'), TOKEN_CACHE_MAX);
  }

  function levenshtein(a, b, maxDistance = Infinity) {
    if (a === b) return 0;
    if (!a.length) return b.length > maxDistance ? maxDistance + 1 : b.length;
    if (!b.length) return a.length > maxDistance ? maxDistance + 1 : a.length;
    if (Math.abs(a.length - b.length) > maxDistance) return maxDistance + 1;

    const cols = b.length + 1;
    if (levPrev.length < cols) {
      levPrev = new Array(cols);
      levCurr = new Array(cols);
    }
    for (let j = 0; j < cols; j++) levPrev[j] = j;

    for (let i = 1; i <= a.length; i++) {
      levCurr[0] = i;
      let rowMin = levCurr[0];
      const aCh = a[i - 1];
      for (let j = 1; j <= b.length; j++) {
        const cost = aCh === b[j - 1] ? 0 : 1;
        const cell = Math.min(levPrev[j] + 1, levCurr[j - 1] + 1, levPrev[j - 1] + cost);
        levCurr[j] = cell;
        if (cell < rowMin) rowMin = cell;
      }
      if (rowMin > maxDistance) return maxDistance + 1;
      const swap = levPrev;
      levPrev = levCurr;
      levCurr = swap;
    }

    return levPrev[b.length];
  }

  function allowedEditDistance(wordLength) {
    if (wordLength <= 3) return 1;
    if (wordLength <= 6) return 1;
    if (wordLength <= 9) return 2;
    return 3;
  }

  function tokensSoundAlike(query, token) {
    if (query.length < 3 || token.length < 3) return false;
    if (soundex(query) === soundex(token)) return true;

    const queryKey = phoneticKey(query);
    const tokenKey = phoneticKey(token);
    if (queryKey.length < 2 || tokenKey.length < 2) return false;
    return queryKey === tokenKey || tokenKey.startsWith(queryKey) || queryKey.startsWith(tokenKey);
  }

  function clampScore(value) {
    return Math.max(0, Math.min(100, Math.round(value)));
  }

  function editSimilarityFromDistance(query, token, distance) {
    const maxLen = Math.max(query.length, token.length, 1);
    return clampScore(100 * (1 - distance / maxLen));
  }

  /**
   * Cheap gate before Levenshtein / phonetic work.
   * Keeps prefix-of-longer-token matches (typo at the start of a long filename).
   */
  function tokenMightMatch(query, token, fuzzy) {
    if (!query || !token) return false;
    if (token === query || token.includes(query)) return true;
    if (query.includes(token) && token.length >= 3) return true;
    if (!fuzzy || query.length < 3) return false;
    const maxDistance = allowedEditDistance(Math.min(query.length, token.length));
    if (Math.abs(query.length - token.length) <= maxDistance + 1) return true;
    return token.length > query.length && token[0] === query[0];
  }

  function scoreQueryAgainstTokenUncached(query, token) {
    if (!query || !token) return EMPTY_SCORE;
    if (token === query) return { matched: true, score: 100, kind: 'exact', token };

    if (token.includes(query)) {
      return {
        matched: true,
        score: clampScore(88 + 12 * (query.length / token.length)),
        kind: 'contains',
        token,
      };
    }

    if (query.includes(token) && token.length >= 3) {
      return {
        matched: true,
        score: clampScore(80 + 15 * (token.length / query.length)),
        kind: 'contains',
        token,
      };
    }

    if (query.length < 3) return EMPTY_SCORE;

    const maxDistance = allowedEditDistance(Math.min(query.length, token.length));
    let distance = -1;
    let close = false;
    if (Math.abs(query.length - token.length) <= maxDistance + 1) {
      distance = levenshtein(query, token, maxDistance);
      close = distance <= maxDistance;
    }
    const soundAlike = tokensSoundAlike(query, token);
    if (close || soundAlike) {
      if (!close) distance = levenshtein(query, token, 3);
      const similarity = editSimilarityFromDistance(query, token, distance);
      if (soundAlike) {
        return { matched: true, score: Math.max(similarity, close ? 82 : 74), kind: 'phonetic', token };
      }
      return { matched: true, score: Math.max(similarity, 62), kind: 'fuzzy', token };
    }

    if (token.length > query.length) {
      const prefix = token.slice(0, query.length);
      if (prefix[0] === query[0]) {
        const prefixScore = scoreQueryAgainstToken(query, prefix);
        if (prefixScore.matched) return { ...prefixScore, token };
      }
    }
    return EMPTY_SCORE;
  }

  function scoreQueryAgainstToken(query, token) {
    const key = `${query}\0${token}`;
    const cached = pairCache.get(key);
    if (cached) return cached;
    return cacheSet(pairCache, key, scoreQueryAgainstTokenUncached(query, token), PAIR_CACHE_MAX);
  }

  function bestScoreForWord(tokens, word, fuzzy) {
    const needle = String(word || '').toLowerCase();
    if (!needle) return { matched: true, score: 100, kind: 'exact' };
    let best = EMPTY_SCORE;
    for (let i = 0; i < tokens.length; i++) {
      const token = tokens[i];
      if (!tokenMightMatch(needle, token, fuzzy)) continue;
      const next = scoreQueryAgainstToken(needle, token);
      if (next.score > best.score) {
        best = next;
        if (best.score >= 100) break;
      }
    }
    return best;
  }

  function scoreTextAgainstQuery(text, query, fuzzy = true) {
    const hay = String(text || '').toLowerCase();
    const needle = String(query || '').toLowerCase();
    if (!needle) return { matched: true, score: 100, kind: 'exact' };
    if (!hay) return EMPTY_SCORE;

    if (hay.includes(needle)) {
      const tokens = tokenizeSearchText(text);
      if (tokens.includes(needle)) return { matched: true, score: 100, kind: 'exact', token: needle };
      return { matched: true, score: 92, kind: 'contains', token: needle };
    }

    if (!fuzzy) return EMPTY_SCORE;
    return bestScoreForWord(tokenizeSearchText(text), needle, true);
  }

  function excerptAroundMatch(text, query, radius = 28) {
    const cleaned = String(text || '')
      .replace(/<[^>]*>/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
    if (!cleaned) return undefined;
    const hay = cleaned.toLowerCase();
    const needle = String(query || '').toLowerCase();
    const index = hay.indexOf(needle);
    if (index < 0) {
      return cleaned.length > 56 ? `${cleaned.slice(0, 56)}…` : cleaned;
    }
    const start = Math.max(0, index - radius);
    const end = Math.min(cleaned.length, index + needle.length + radius);
    return `${start > 0 ? '…' : ''}${cleaned.slice(start, end)}${end < cleaned.length ? '…' : ''}`;
  }

  function attachFieldMeta(score, field, word) {
    if (!score.matched) return score;
    return {
      ...score,
      source: field.sourceLabel,
      sourceName: field.sourceName,
      snippet: excerptAroundMatch(field.text || '', word),
    };
  }

  function reduceAndScores(wordScores) {
    const strongest = wordScores.reduce(
      (current, item) => (item.score > current.score ? item : current),
      wordScores[0]
    );
    const average = wordScores.reduce((sum, item) => sum + item.score, 0) / wordScores.length;
    return {
      ...strongest,
      matched: true,
      score: clampScore(average),
    };
  }

  function scoreTokensAgainstWords(tokens, words, mode, fuzzy = false) {
    if (!words.length) return { matched: true, score: 100, kind: 'exact' };
    const list = Array.isArray(tokens) ? tokens : [];
    if (!list.length) return EMPTY_SCORE;

    if (mode === 'and') {
      const wordScores = new Array(words.length);
      const order = words
        .map((_, index) => index)
        .sort((a, b) => String(words[a] || '').length - String(words[b] || '').length);
      for (let n = 0; n < order.length; n++) {
        const index = order[n];
        const next = bestScoreForWord(list, words[index], fuzzy);
        if (!next.matched) return EMPTY_SCORE;
        wordScores[index] = next;
      }
      return reduceAndScores(wordScores);
    }

    let best = EMPTY_SCORE;
    for (let i = 0; i < words.length; i++) {
      const next = bestScoreForWord(list, words[i], fuzzy);
      if (next.score > best.score) {
        best = next;
        if (best.score >= 100) break;
      }
    }
    return best.matched ? best : EMPTY_SCORE;
  }

  function scoreHayAgainstWords(hay, words, mode, fuzzy = false) {
    const text = String(hay || '').toLowerCase();
    if (!words.length) return { matched: true, score: 100, kind: 'exact' };
    if (!text) return EMPTY_SCORE;

    if (mode === 'and') {
      for (let i = 0; i < words.length; i++) {
        const word = String(words[i] || '').toLowerCase();
        if (word.length < 3 && !text.includes(word)) return EMPTY_SCORE;
        if (!fuzzy && !text.includes(word)) return EMPTY_SCORE;
      }
      if (words.every((word) => text.includes(String(word || '').toLowerCase()))) {
        return { matched: true, score: 92, kind: 'contains' };
      }
      if (!fuzzy) return EMPTY_SCORE;
      return scoreTokensAgainstWords(splitSearchTokens(text), words, mode, true);
    }

    if (words.some((word) => text.includes(String(word || '').toLowerCase()))) {
      return { matched: true, score: 88, kind: 'contains' };
    }
    if (!fuzzy) return EMPTY_SCORE;
    return scoreTokensAgainstWords(splitSearchTokens(text), words, mode, true);
  }

  function scoreLabeledFieldsAgainstWords(fields, words, mode, fuzzy = false) {
    if (!words.length) return { matched: true, score: 100, kind: 'exact' };

    const usableFields = (fields || []).filter((field) => String(field.text || '').trim());
    if (!usableFields.length) return EMPTY_SCORE;

    const matchedFieldKeys = new Set();
    const bestForWord = (word) => {
      let best = EMPTY_SCORE;
      for (let i = 0; i < usableFields.length; i++) {
        const field = usableFields[i];
        const next = attachFieldMeta(scoreTextAgainstQuery(field.text || '', word, fuzzy), field, word);
        if (next.matched) {
          matchedFieldKeys.add(`${field.sourceLabel}|${field.sourceName || ''}`);
          if (next.score > best.score) best = next;
          if (best.score >= 100) break;
        } else if (next.score > best.score) {
          best = next;
        }
      }
      return best;
    };

    if (mode === 'and') {
      const wordScores = new Array(words.length);
      const order = words
        .map((_, index) => index)
        .sort((a, b) => String(words[a] || '').length - String(words[b] || '').length);
      for (let n = 0; n < order.length; n++) {
        const index = order[n];
        const next = bestForWord(words[index]);
        if (!next.matched) return EMPTY_SCORE;
        wordScores[index] = next;
      }
      return { ...reduceAndScores(wordScores), extraCount: Math.max(0, matchedFieldKeys.size - 1) };
    }

    let best = EMPTY_SCORE;
    for (let i = 0; i < words.length; i++) {
      const next = bestForWord(words[i]);
      if (next.score > best.score) best = next;
      if (best.score >= 100) break;
    }
    if (!best.matched) return EMPTY_SCORE;
    return { ...best, extraCount: Math.max(0, matchedFieldKeys.size - 1) };
  }

  function queryNeedsFuzzy(words, fuzzy) {
    if (!fuzzy) return false;
    return (words || []).some((word) => String(word || '').length >= 3);
  }

  function getSearchWords(searchTerm) {
    return String(searchTerm || '')
      .toLowerCase()
      .split(/\s+/)
      .filter(Boolean);
  }

  function parseCatalogQuery(raw) {
    const empty = {
      words: [],
      phrases: [],
      excludes: [],
      extensions: [],
      types: [],
      person: '',
      modifiedBy: '',
      createdBy: '',
      paths: [],
      has: [],
      lacks: [],
      tags: [],
      rawRemainder: '',
    };
    let text = String(raw || '').trim();
    if (!text) return empty;

    const out = { ...empty, phrases: [], excludes: [], extensions: [], types: [], paths: [], has: [], lacks: [], tags: [] };
    const words = [];
    const remainderParts = [];

    const pushCsv = (list, value) => {
      String(value || '')
        .split(',')
        .map((part) => part.trim().toLowerCase())
        .filter(Boolean)
        .forEach((part) => {
          if (!list.includes(part)) list.push(part);
        });
    };

    const normalizeType = (value) => {
      const v = String(value || '').toLowerCase().replace(/^\.+/, '');
      if (v === 'drawing' || v === 'drawings') return 'drawings';
      if (v === 'image' || v === 'images' || v === 'img' || v === 'photo') return 'images';
      if (v === 'folder' || v === 'folders') return 'folders';
      if (v === 'cad' || v === 'dwg' || v === 'dxf') return 'cad';
      if (v === 'visio' || v === 'vsdx' || v === 'vsd') return 'visio';
      if (v === 'pdf') return 'pdf';
      return v;
    };

    // Pull prefixed quoted values first: person:"Jane Doe"
    text = text.replace(
      /\b(ext|extension|type|person|modified|modifiedby|mod|created|createdby|author|path|in|has|contain|contains|lacks|missing|without|tag|tags):"([^"]*)"/gi,
      (_, prefix, value) => {
        const key = String(prefix || '').toLowerCase();
        const val = String(value || '').trim();
        const lower = val.toLowerCase();
        remainderParts.push(`${key}:"${val}"`);
        if (key === 'ext' || key === 'extension') pushCsv(out.extensions, lower.replace(/^\.+/, ''));
        else if (key === 'type') pushCsv(out.types, normalizeType(lower));
        else if (key === 'person') out.person = lower;
        else if (key === 'modified' || key === 'modifiedby' || key === 'mod') out.modifiedBy = lower;
        else if (key === 'created' || key === 'createdby' || key === 'author') out.createdBy = lower;
        else if (key === 'path' || key === 'in') {
          if (lower) out.paths.push(lower);
        } else if (key === 'has' || key === 'contain' || key === 'contains') pushCsv(out.has, normalizeType(lower));
        else if (key === 'lacks' || key === 'missing' || key === 'without') pushCsv(out.lacks, normalizeType(lower));
        else if (key === 'tag' || key === 'tags') pushCsv(out.tags, lower);
        return ' ';
      }
    );

    const tokens = [];
    const re = /(-?)"([^"]*)"|(\S+)/g;
    let match;
    while ((match = re.exec(text)) !== null) {
      if (match[2] !== undefined) {
        tokens.push({ kind: match[1] === '-' ? 'exclude-phrase' : 'phrase', value: match[2] });
      } else {
        tokens.push({ kind: 'token', value: match[3] });
      }
    }

    const takePrefixed = (token, prefixes) => {
      const lower = token.toLowerCase();
      for (const prefix of prefixes) {
        if (lower.startsWith(prefix)) {
          return token.slice(prefix.length).trim();
        }
      }
      return null;
    };

    tokens.forEach((token) => {
      if (token.kind === 'phrase') {
        const phrase = token.value.trim().toLowerCase();
        if (phrase) {
          out.phrases.push(phrase);
          remainderParts.push(`"${token.value.trim()}"`);
        }
        return;
      }
      if (token.kind === 'exclude-phrase') {
        const phrase = token.value.trim().toLowerCase();
        if (phrase) {
          out.excludes.push(phrase);
          remainderParts.push(`-"${token.value.trim()}"`);
        }
        return;
      }

      const value = token.value;
      if (value.startsWith('-') && value.length > 1 && !value.includes(':')) {
        const excluded = value.slice(1).toLowerCase();
        if (excluded) {
          out.excludes.push(excluded);
          remainderParts.push(value);
        }
        return;
      }

      let taken = takePrefixed(value, ['ext:', 'extension:']);
      if (taken !== null) {
        pushCsv(out.extensions, taken.replace(/^\.+/, ''));
        remainderParts.push(value);
        return;
      }
      taken = takePrefixed(value, ['type:']);
      if (taken !== null) {
        pushCsv(
          out.types,
          taken
            .split(',')
            .map(normalizeType)
            .join(',')
        );
        remainderParts.push(value);
        return;
      }
      taken = takePrefixed(value, ['person:']);
      if (taken !== null) {
        out.person = taken.toLowerCase();
        remainderParts.push(value);
        return;
      }
      taken = takePrefixed(value, ['modified:', 'modifiedby:', 'mod:']);
      if (taken !== null) {
        out.modifiedBy = taken.toLowerCase();
        remainderParts.push(value);
        return;
      }
      taken = takePrefixed(value, ['created:', 'createdby:', 'author:']);
      if (taken !== null) {
        out.createdBy = taken.toLowerCase();
        remainderParts.push(value);
        return;
      }
      taken = takePrefixed(value, ['path:', 'in:']);
      if (taken !== null) {
        const pathVal = taken.toLowerCase();
        if (pathVal) out.paths.push(pathVal);
        remainderParts.push(value);
        return;
      }
      taken = takePrefixed(value, ['has:', 'contain:', 'contains:']);
      if (taken !== null) {
        pushCsv(
          out.has,
          taken
            .split(',')
            .map(normalizeType)
            .join(',')
        );
        remainderParts.push(value);
        return;
      }
      taken = takePrefixed(value, ['lacks:', 'missing:', 'without:']);
      if (taken !== null) {
        pushCsv(
          out.lacks,
          taken
            .split(',')
            .map(normalizeType)
            .join(',')
        );
        remainderParts.push(value);
        return;
      }
      taken = takePrefixed(value, ['tag:', 'tags:']);
      if (taken !== null) {
        pushCsv(out.tags, taken);
        remainderParts.push(value);
        return;
      }

      words.push(value.toLowerCase());
      remainderParts.push(value);
    });

    out.words = words;
    out.rawRemainder = remainderParts.join(' ');
    return out;
  }

  function combineSearchScores(primary, refine) {
    if (!refine || !refine.matched) return primary;
    if (!primary || !primary.matched) return primary;
    const weaker = refine.score < primary.score ? refine : primary;
    return {
      matched: true,
      score: Math.round((primary.score + refine.score) / 2),
      kind: weaker.kind,
      token: weaker.token,
      source: primary.source,
      sourceName: primary.sourceName,
      snippet: primary.snippet,
      extraCount: (primary.extraCount || 0) + (refine.source && refine.source !== primary.source ? 1 : 0),
    };
  }

  function matchKindLabel(kind) {
    if (kind === 'exact') return 'Exact';
    if (kind === 'contains') return 'Contains';
    if (kind === 'phonetic') return 'Sounds like';
    if (kind === 'fuzzy') return 'Close spelling';
    return 'No match';
  }

  function formatMatchReason(match) {
    if (!match?.matched || !match.source) return '';
    const name = match.sourceName ? ` in “${match.sourceName}”` : '';
    const extra = match.extraCount ? ` +${match.extraCount} more` : '';
    return `${match.source}${name}${extra}`;
  }

  window.FuzzySearch = {
    tokenizeSearchText,
    soundex,
    phoneticKey,
    levenshtein,
    scoreTextAgainstQuery,
    scoreLabeledFieldsAgainstWords,
    scoreTokensAgainstWords,
    scoreHayAgainstWords,
    queryNeedsFuzzy,
    getSearchWords,
    parseCatalogQuery,
    combineSearchScores,
    matchKindLabel,
    formatMatchReason,
    excerptAroundMatch,
  };
})();
