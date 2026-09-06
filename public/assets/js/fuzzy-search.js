/**
 * LinkNest-style fuzzy / phonetic search (ported from linknest/utils/fuzzySearch.ts).
 * Exposed as window.FuzzySearch for SharePoint catalog live search.
 */
(() => {
  const EMPTY_SCORE = { matched: false, score: 0, kind: 'none' };

  function tokenizeSearchText(text) {
    const tokens = new Set();
    const push = (value) => {
      const token = String(value || '')
        .toLowerCase()
        .replace(/[^a-z0-9]/g, '');
      if (token) tokens.add(token);
    };

    String(text || '')
      .split(/[^A-Za-z0-9]+/)
      .filter(Boolean)
      .forEach((part) => {
        push(part);
        part
          .replace(/([a-z])([A-Z])/g, '$1 $2')
          .replace(/([A-Z]+)([A-Z][a-z])/g, '$1 $2')
          .replace(/([a-zA-Z])([0-9])/g, '$1 $2')
          .replace(/([0-9])([a-zA-Z])/g, '$1 $2')
          .split(/\s+/)
          .forEach(push);
      });

    return Array.from(tokens);
  }

  function soundex(word) {
    const cleaned = String(word || '')
      .toLowerCase()
      .replace(/[^a-z]/g, '');
    if (!cleaned) return '';

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

    return result.padEnd(4, '0');
  }

  function phoneticKey(word) {
    let value = String(word || '')
      .toLowerCase()
      .replace(/[^a-z]/g, '');
    if (!value) return '';

    value = value
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

    return value.replace(/(.)\1+/g, '$1');
  }

  function levenshtein(a, b, maxDistance = Infinity) {
    if (a === b) return 0;
    if (!a.length) return b.length > maxDistance ? maxDistance + 1 : b.length;
    if (!b.length) return a.length > maxDistance ? maxDistance + 1 : a.length;
    if (Math.abs(a.length - b.length) > maxDistance) return maxDistance + 1;

    const prev = new Array(b.length + 1);
    const curr = new Array(b.length + 1);
    for (let j = 0; j <= b.length; j++) prev[j] = j;

    for (let i = 1; i <= a.length; i++) {
      curr[0] = i;
      let rowMin = curr[0];
      for (let j = 1; j <= b.length; j++) {
        const cost = a[i - 1] === b[j - 1] ? 0 : 1;
        curr[j] = Math.min(prev[j] + 1, curr[j - 1] + 1, prev[j - 1] + cost);
        if (curr[j] < rowMin) rowMin = curr[j];
      }
      if (rowMin > maxDistance) return maxDistance + 1;
      for (let j = 0; j <= b.length; j++) prev[j] = curr[j];
    }

    return prev[b.length];
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

  function tokensAreClose(query, token) {
    const maxDistance = allowedEditDistance(Math.min(query.length, token.length));
    if (Math.abs(query.length - token.length) > maxDistance + 1) return false;
    return levenshtein(query, token, maxDistance) <= maxDistance;
  }

  function clampScore(value) {
    return Math.max(0, Math.min(100, Math.round(value)));
  }

  function editSimilarity(query, token) {
    const maxLen = Math.max(query.length, token.length, 1);
    const distance = levenshtein(query, token, 3);
    return clampScore(100 * (1 - distance / maxLen));
  }

  function scoreQueryAgainstToken(query, token) {
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

    const close = tokensAreClose(query, token);
    const soundAlike = tokensSoundAlike(query, token);
    if (close || soundAlike) {
      const similarity = editSimilarity(query, token);
      if (soundAlike) {
        return { matched: true, score: Math.max(similarity, close ? 82 : 74), kind: 'phonetic', token };
      }
      return { matched: true, score: Math.max(similarity, 62), kind: 'fuzzy', token };
    }

    const prefixLengths = [query.length - 1, query.length, query.length + 1];
    let best = EMPTY_SCORE;
    for (const length of prefixLengths) {
      if (length < 3 || length >= token.length) continue;
      const prefix = token.slice(0, length);
      const prefixScore = scoreQueryAgainstToken(query, prefix);
      if (prefixScore.score > best.score) best = { ...prefixScore, token };
    }
    return best;
  }

  function scoreTextAgainstQuery(text, query, fuzzy = true) {
    const hay = String(text || '').toLowerCase();
    const needle = String(query || '').toLowerCase();
    if (!needle) return { matched: true, score: 100, kind: 'exact' };
    if (!hay) return EMPTY_SCORE;

    const tokens = tokenizeSearchText(text);
    if (hay.includes(needle)) {
      if (tokens.includes(needle)) return { matched: true, score: 100, kind: 'exact', token: needle };
      return { matched: true, score: 92, kind: 'contains', token: needle };
    }

    if (!fuzzy) return EMPTY_SCORE;

    let best = EMPTY_SCORE;
    for (const token of tokens) {
      const next = scoreQueryAgainstToken(needle, token);
      if (next.score > best.score) best = next;
    }
    return best;
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

  function scoreLabeledFieldsAgainstWords(fields, words, mode, fuzzy = false) {
    if (!words.length) return { matched: true, score: 100, kind: 'exact' };

    const usableFields = (fields || []).filter((field) => String(field.text || '').trim());
    if (!usableFields.length) return EMPTY_SCORE;

    const matchedFieldKeys = new Set();
    const bestForWord = (word) => {
      let best = EMPTY_SCORE;
      usableFields.forEach((field) => {
        const next = attachFieldMeta(scoreTextAgainstQuery(field.text || '', word, fuzzy), field, word);
        if (next.matched) {
          matchedFieldKeys.add(`${field.sourceLabel}|${field.sourceName || ''}`);
        }
        if (next.score > best.score) best = next;
      });
      return best;
    };

    const wordScores = words.map(bestForWord);
    const extraCount = Math.max(0, matchedFieldKeys.size - 1);

    if (mode === 'and') {
      if (wordScores.some((item) => !item.matched)) return EMPTY_SCORE;
      const strongest = wordScores.reduce(
        (current, item) => (item.score > current.score ? item : current),
        wordScores[0]
      );
      const average = wordScores.reduce((sum, item) => sum + item.score, 0) / wordScores.length;
      return {
        ...strongest,
        matched: true,
        score: clampScore(average),
        extraCount,
      };
    }

    const best = wordScores.reduce(
      (current, item) => (item.score > current.score ? item : current),
      EMPTY_SCORE
    );
    if (!best.matched) return EMPTY_SCORE;
    return { ...best, extraCount };
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
      rawRemainder: '',
    };
    let text = String(raw || '').trim();
    if (!text) return empty;

    const out = { ...empty, phrases: [], excludes: [], extensions: [], types: [], paths: [], has: [], lacks: [] };
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
      /\b(ext|extension|type|person|modified|modifiedby|mod|created|createdby|author|path|in|has|contain|contains|lacks|missing|without):"([^"]*)"/gi,
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
    getSearchWords,
    parseCatalogQuery,
    combineSearchScores,
    matchKindLabel,
    formatMatchReason,
    excerptAroundMatch,
  };
})();
