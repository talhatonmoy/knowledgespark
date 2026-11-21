/* KnowledgeSpark admin UI. Link placement is manual by default; Apply All Links wraps anchor phrases, saves the post, and reloads. */
(function () {
    'use strict';

    var lastSuggestions = [];
    var ksBusy = false;
    var TEXT_BLOCK_TYPES = ['core/paragraph', 'core/heading', 'core/quote', 'core/list-item', 'core/verse', 'core/html', 'core/freeform'];
    /* Raw HTML blocks: current WordPress (7.1+) stores their text in block inner content, not in any attribute (KNOWN-ISSUES #7). */
    var RAW_BLOCK_TYPES = ['core/html', 'core/freeform'];
    var VOID_TAGS = { area: 1, base: 1, br: 1, col: 1, embed: 1, hr: 1, img: 1, input: 1, link: 1, meta: 1, param: 1, source: 1, track: 1, wbr: 1 };
    var TAG_RE = /<!--[\s\S]*?-->|<(\/?)([a-zA-Z][a-zA-Z0-9]*)((?:[^>"']|"[^"]*"|'[^']*')*)>/g;
    var BLOCK_CLOSE_RE = /<\/\s*(?:p|h[1-6]|div|li|ul|ol|blockquote|pre|table|section|article|figure|thead|tbody|tfoot|tr|caption|figcaption|dl|dd|dt)[\s>]/i;
    var PROTECTED_RE = /<script\b[^>]*>[\s\S]*?(?:<\/script>|$)|<style\b[^>]*>[\s\S]*?(?:<\/style>|$)|<!--[\s\S]*?-->/gi;
    var HINT_RANK = { in_protected: 3, in_link: 2, untidy: 1 };
    /* Standard HTML entity forms per character; each alternative matches before the literal. Mirrors the class-file token table (KNOWN-ISSUES #7). */
    var ENTITY_FORMS = { '&': '&amp;|&#0*38;|&#x0*26;', '<': '&lt;|&#0*60;|&#x0*3c;', '>': '&gt;|&#0*62;|&#x0*3e;', '"': '&quot;|&#0*34;|&#x0*22;', "'": '&apos;|&#0*39;|&#x0*27;', ' ': '&nbsp;|&#0*160;|&#x0*a0;' };
    var JOIN_RE = '(?:\\s|&nbsp;|&#0*160;|&#x0*a0;|<[^>]+>)*';

    function element(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function text(value) {
        return typeof value === 'string' ? value : '';
    }

    function clear(node) {
        while (node.firstChild) node.removeChild(node.firstChild);
    }

    function status(node, message, kind) {
        clear(node);
        node.appendChild(element('p', 'ks-notice ks-notice--' + (kind || 'info'), message));
    }

    async function request(route, payload) {
        var config = window.KnowledgeSpark || {};
        if (!config.root || !config.nonce) {
            throw new Error('KnowledgeSpark could not load its connection settings. Reload this page and try again.');
        }
        var controller = new AbortController();
        var timedOut = false;
        // Allow for collection validation, inference, and optional AI review.
        var timeout = route === 'suggest' ? 180000 : 100000;
        var timer = window.setTimeout(function () {
            timedOut = true;
            controller.abort();
        }, timeout);
        try {
            var response = await window.fetch(config.root.replace(/\/$/, '') + '/' + route, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
                body: JSON.stringify(payload || {}),
                signal: controller.signal
            });
            var data;
            try {
                data = await response.json();
            } catch (error) {
                if (timedOut) throw error;
                throw new Error('The server returned an unreadable response. Check the connection and plugin settings, then try again.');
            }
            if (!response.ok) {
                throw new Error(text(data && data.message) || (response.status === 401 || response.status === 403
                    ? 'Your session may have expired. Reload this page and try again.'
                    : 'The request failed (HTTP ' + response.status + '). Check the plugin settings and try again.'));
            }
            if (!data || typeof data !== 'object' || Array.isArray(data)) {
                throw new Error('The server returned an unexpected response. Reload the page and try again.');
            }
            return data;
        } catch (error) {
            if (timedOut) throw new Error('The request timed out while the server was still working. Wait a moment, then try again.');
            if (error instanceof TypeError) throw new Error('Could not reach the server. Check your connection and try again.');
            throw error;
        } finally {
            window.clearTimeout(timer);
        }
    }

    function currentPost() {
        var title = document.getElementById('title');
        var content = document.getElementById('content');
        var result = { title: title ? title.value : '', content: content ? content.value : '' };
        var tiny = window.tinymce && window.tinymce.get('content');
        if (tiny && !tiny.isHidden()) result.content = tiny.getContent();
        try {
            var editor = window.wp && window.wp.data && window.wp.data.select('core/editor');
            if (editor && typeof editor.getEditedPostContent === 'function') {
                var editedContent = editor.getEditedPostContent();
                if (typeof editedContent === 'string') result.content = editedContent;
            }
            if (editor && typeof editor.getEditedPostAttribute === 'function') {
                var editedTitle = editor.getEditedPostAttribute('title');
                if (typeof editedTitle === 'string') result.title = editedTitle;
            }
        } catch (error) {
            // Classic Editor fields remain available when the Gutenberg store is absent.
        }
        return result;
    }

    function safeUrl(value) {
        try {
            var url = new URL(text(value));
            return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : '';
        } catch (error) {
            return '';
        }
    }

    function detail(card, label, value, tag) {
        var group = element('div', 'ks-detail');
        group.appendChild(element('span', 'ks-detail-label', label));
        group.appendChild(element(tag || 'p', '', value));
        card.appendChild(group);
    }

    function escapeRegExp(s) {
        return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function htmlEscape(s) {
        return text(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /* Tag-aware anchor location: mirrors the server-side span matcher so links only land on valid, unlinked text. */
    function tagStackScope(html) {
        var stack = [];
        TAG_RE.lastIndex = 0;
        var m;
        while ((m = TAG_RE.exec(html)) !== null) {
            if (m[0].charAt(1) === '!') continue; // comment
            var isClose = m[1] === '/';
            var name = (m[2] || '').toLowerCase();
            if (!name || VOID_TAGS[name]) continue;
            if (isClose) {
                if (stack.length && stack[stack.length - 1] === name) stack.pop();
            } else {
                stack.push(name);
            }
        }
        return stack;
    }

    function spanNesting(before, slice) {
        var seed = tagStackScope(before);
        var stack = seed.slice();
        var mark = seed.length;
        TAG_RE.lastIndex = 0;
        var m;
        while ((m = TAG_RE.exec(slice)) !== null) {
            if (m[0].charAt(1) === '!') continue; // comment
            var name = (m[2] || '').toLowerCase();
            if (!name || VOID_TAGS[name]) continue;
            if (m[1] !== '/') {
                stack.push(name);
            } else if (stack.length && stack[stack.length - 1] === name) {
                stack.pop();
                if (stack.length < mark) return false; // closed an element that opened before the span
            }
        }
        return stack.length === mark;
    }

    /* Protected markup (script/style/comments) is opaque: spans may never start, end, or bridge across it. Mirrors the PHP protected_ranges guard. */
    function protectedRanges(html) {
        PROTECTED_RE.lastIndex = 0;
        var ranges = [];
        var pm;
        while ((pm = PROTECTED_RE.exec(html)) !== null) {
            if (pm[0].length === 0) { PROTECTED_RE.lastIndex++; continue; }
            ranges.push({ start: pm.index, end: pm.index + pm[0].length });
        }
        return ranges;
    }

    function findAnchorSpan(html, anchor, out) {
        var chars = Array.from(text(anchor));
        if (!chars.length) return null;
        var pattern = chars.map(function (ch) {
            var forms = ENTITY_FORMS[ch];
            return forms ? '(?:' + forms + '|' + escapeRegExp(ch) + ')' : escapeRegExp(ch);
        }).join(JOIN_RE);
        var re = new RegExp(pattern, 'g');
        var ranges = protectedRanges(html);
        var rank = { in_protected: 3, in_link: 2, untidy: 1, in_tag: 0 };
        var best = null;
        var openA = /<\s*a\b/gi;
        var closedA = /<\s*\/\s*a\s*\/?\s*>/gi;
        var m;
        while ((m = re.exec(html)) !== null) {
            var slice = m[0];
            var before = html.slice(0, m.index);
            var after = html.slice(m.index + slice.length);
            var why = null;
            var p;
            for (p = 0; p < ranges.length && why === null; p++) {
                if (m.index < ranges[p].end && m.index + slice.length > ranges[p].start) why = 'in_protected';
            }
            var lt = before.lastIndexOf('<');
            var gt = before.lastIndexOf('>');
            if (why === null && lt !== -1 && (gt === -1 || lt > gt)) why = 'in_tag';
            var lt2 = after.indexOf('<');
            var gt2 = after.indexOf('>');
            if (why === null && gt2 !== -1 && (lt2 === -1 || gt2 < lt2)) why = 'in_tag';
            if (why === null && BLOCK_CLOSE_RE.test(slice)) why = 'untidy';
            if (why === null && (/<\s*a\b/i.test(slice) || /<\s*\/\s*a\s*\/?\s*>/i.test(slice))) why = 'in_link';
            var opens = (before.match(openA) || []).length;
            var closes = (before.match(closedA) || []).length;
            if (why === null && opens > closes) why = 'in_link';
            if (why === null && !spanNesting(before, slice)) why = 'untidy';
            if (why === null) {
                if (out) out.hint = null;
                return { index: m.index, span: slice };
            }
            if (why !== 'in_tag' && (best === null || rank[why] > rank[best])) best = why;
        }
        if (out) out.hint = best;
        return null;
    }

    /* One sentence per actual cause; "not in the current draft" is only used when the text was genuinely not found. Mirrors the PHP taxonomy in api_apply. */
    function skipSentence(name, hint) {
        if (hint === 'in_link') return name + ': its anchor is already inside a link, so it was left untouched.';
        if (hint === 'in_protected') return name + ': its anchor is only inside script, style, or comment markup, so it was left untouched.';
        if (hint === 'untidy') return name + ': its anchor crosses formatting that cannot be wrapped safely, so it was left out.';
        return name + ': its anchor is not in the current draft.';
    }

    /* Resolves a block's searchable content: raw blocks read inner content (attribute chain kept as legacy fallback), classic blocks read their text attribute. Shared by the anchor search and the post-write verification (KNOWN-ISSUES #8). */
    function blockContentChannel(block, wpBlocks) {
        var attrs = (block.attributes && typeof block.attributes === 'object') ? block.attributes : null;
        var chain = ['innerHTML', 'value', 'content'];
        var contentKey = null;
        var src = null;
        var isRaw = RAW_BLOCK_TYPES.indexOf(block.name) !== -1;
        var ci;
        if (isRaw) {
            for (ci = 0; ci < chain.length; ci++) {
                if (attrs && typeof attrs[chain[ci]] === 'string' && attrs[chain[ci]]) { src = attrs[chain[ci]]; contentKey = chain[ci]; break; }
            }
            if (src === null && wpBlocks && typeof wpBlocks.getBlockContent === 'function') {
                var gc = wpBlocks.getBlockContent(block);
                if (typeof gc === 'string' && gc) src = gc;
            }
            if (src === null && typeof block.innerHTML === 'string' && block.innerHTML) src = block.innerHTML;
            if (src === null && Array.isArray(block.innerContent)) src = block.innerContent.join('');
        } else {
            for (ci = 0; ci < chain.length && contentKey === null; ci++) {
                if (attrs && typeof attrs[chain[ci]] === 'string') contentKey = chain[ci];
            }
            if (contentKey !== null) src = attrs[contentKey];
        }
        return { attrs: attrs, contentKey: contentKey, src: src, isRaw: isRaw };
    }

    /* Finds a block by its editor-assigned client identifier; the store addresses blocks by clientId (KNOWN-ISSUES #8). */
    function findBlockByClientId(blocks, clientId) {
        var stack = Array.isArray(blocks) ? blocks.slice() : [];
        while (stack.length) {
            var b = stack.pop();
            if (typeof b === 'string') continue;
            if (b && typeof b === 'object' && b.clientId === clientId) return b;
            if (b && Array.isArray(b.innerBlocks)) {
                for (var i = 0; i < b.innerBlocks.length; i++) stack.push(b.innerBlocks[i]);
            }
        }
        return null;
    }

    function blockEditorApi() {
        try {
            if (!window.wp || !window.wp.data) return null;
            var beSelect = window.wp.data.select('core/block-editor');
            var beDispatch = window.wp.data.dispatch('core/block-editor');
            var edDispatch = window.wp.data.dispatch('core/editor');
            if (!beSelect || typeof beSelect.getBlocks !== 'function' || !beDispatch || typeof beDispatch.replaceBlock !== 'function'
                || !edDispatch || typeof edDispatch.savePost !== 'function') return null;
            return {
                getBlocks: function () { return beSelect.getBlocks(); },
                getBlock: typeof beSelect.getBlock === 'function' ? function (id) { return beSelect.getBlock(id); } : null,
                replaceBlock: function (id, block) { return beDispatch.replaceBlock(id, block); },
                updateBlock: typeof beDispatch.updateBlock === 'function' ? function (id, attrs) { return beDispatch.updateBlock(id, attrs); } : null,
                replaceInnerBlocks: typeof beDispatch.replaceInnerBlocks === 'function' ? function (id, blocks, preserve) { return beDispatch.replaceInnerBlocks(id, blocks, preserve); } : null,
                savePost: function () { return edDispatch.savePost(); }
            };
        } catch (error) {
            return null;
        }
    }

    async function applyViaBlockEditor(be, applicable, results) {
        var placed = 0;
        var skipped = [];
        var wpBlocks = (window.wp && window.wp.blocks) ? window.wp.blocks : null;
        applicable.forEach(function (item) {
            var blocks = be.getBlocks();
            var name = text(item.title) || 'A suggested post';
            var url = safeUrl(item.url);
            var anchor = text(item.anchor);
            var found = false;
            var bestHint = null;
            if (!Array.isArray(blocks)) blocks = [];
            var stack = blocks.slice().reverse();
            while (stack.length && !found) {
                var block = stack.pop();
                if (typeof block === 'string') {
                    block = be.getBlock ? be.getBlock(block) : null;
                }
                if (!block || typeof block !== 'object') continue;
                if (TEXT_BLOCK_TYPES.indexOf(block.name) !== -1) {
                    var channel = blockContentChannel(block, wpBlocks);
                    var src = channel.src;
                    var out = {};
                    var span = (src !== null) ? findAnchorSpan(src, anchor, out) : null;
                    if (out.hint && (bestHint === null || HINT_RANK[out.hint] > HINT_RANK[bestHint])) bestHint = out.hint;
                    if (span) {
                        found = true;
                        var targetId = block.clientId;
                        if (!targetId) {
                            skipped.push(name + ': the editor refused this change (block identity missing).');
                            break;
                        }
                        var next = src.slice(0, span.index) +
                            '<a href="' + url + '" title="' + htmlEscape(item.title) + '">' + span.span + '</a>' +
                            src.slice(span.index + span.span.length);
                        try {
                            if (channel.isRaw && channel.contentKey === null) {
                                var bName = String(block.name).replace(/^core\//, '');
                                var parsed = null;
                                if (wpBlocks && typeof wpBlocks.parse === 'function' && typeof be.updateBlock === 'function' && typeof be.replaceInnerBlocks === 'function') {
                                    try { parsed = wpBlocks.parse('<!-- wp:' + bName + '-->\n' + next + '\n<!-- /wp:' + bName + ' -->'); } catch (parseError) { parsed = null; }
                                }
                                if (parsed && Array.isArray(parsed) && parsed[0]) {
                                    var inn = Array.isArray(parsed[0].innerContent) ? parsed[0].innerContent : [next];
                                    var kids = Array.isArray(parsed[0].innerBlocks) ? parsed[0].innerBlocks : [];
                                    be.updateBlock(targetId, { innerContent: inn });
                                    be.replaceInnerBlocks(targetId, kids, false);
                                } else if (typeof be.replaceBlock === 'function') {
                                    var rawNext = Object.assign({}, block);
                                    rawNext.innerContent = [next];
                                    be.replaceBlock(targetId, rawNext);
                                } else {
                                    throw new Error('no inner-content dispatcher');
                                }
                            } else {
                                var nextAttrs = {};
                                var ak;
                                for (ak in channel.attrs) {
                                    if (Object.prototype.hasOwnProperty.call(channel.attrs, ak)) nextAttrs[ak] = channel.attrs[ak];
                                }
                                nextAttrs[channel.contentKey] = next;
                                var nextBlock = Object.assign({}, block, { attributes: nextAttrs });
                                nextBlock[channel.contentKey] = next;
                                be.replaceBlock(targetId, nextBlock);
                            }
                            /* Verify after write: never trust a dispatch; the link only counts when the store reflects it (KNOWN-ISSUES #8). */
                            var wrapPrefix = '<a href="' + url + '" title="' + htmlEscape(item.title) + '">';
                            var freshBlock = findBlockByClientId(be.getBlocks(), targetId);
                            var recheck = freshBlock ? blockContentChannel(freshBlock, wpBlocks) : null;
                            if (!recheck || recheck.src === null || recheck.src.indexOf(wrapPrefix) === -1) {
                                skipped.push(name + ': the editor refused this change (the editor store did not accept the change).');
                            } else {
                                placed++;
                            }
                        } catch (error) {
                            skipped.push(name + ': the editor refused this change (' + (error.message || 'unknown error') + ').');
                        }
                        break;
                    }
                }
                var kids = block.innerBlocks;
                if (Array.isArray(kids)) {
                    for (var i = kids.length - 1; i >= 0; i--) {
                        var kid = kids[i];
                        if (typeof kid === 'string') kid = be.getBlock ? be.getBlock(kid) : null;
                        if (kid) stack.push(kid);
                    }
                }
            }
            if (!found) skipped.push(skipSentence(name, bestHint));
        });
        if (!placed) {
            status(results, 'No links could be inserted at their suggested anchors. ' + skipped.join(' '), 'error');
            return;
        }
        try {
            await be.savePost();
        } catch (error) {
            status(results, 'Links were placed in the editor, but the automatic save failed (' + (error.message || 'unknown error') + '). Save the post yourself to keep them.', 'error');
            return;
        }
        var message = 'Inserted ' + placed + ' suggested link' + (placed === 1 ? '' : 's') + ' and saved the post.' +
            (skipped.length ? ' Skipped: ' + skipped.join(' ') : '') + ' Reloading the editor…';
        status(results, message, 'success');
        window.setTimeout(function () { window.location.reload(); }, 1500);
    }

    async function applyViaRest(applicable, results) {
        var postId = Number((window.KnowledgeSpark || {}).postId) || 0;
        if (!postId) throw new Error('This editor can only apply links to an already saved post. Save it once, then apply links.');
        var draft = currentPost();
        if (!String(draft.content).trim()) throw new Error('Write some content in your draft before applying links.');
        var data = await request('apply', {
            post_id: postId,
            content: draft.content,
            links: applicable.map(function (s) {
                return { anchor: s.anchor, url: safeUrl(s.url), title: text(s.title) };
            })
        });
        if (!data || typeof data.applied !== 'number' || data.applied < 0 || !Array.isArray(data.skipped)) {
            throw new Error('The server returned an unexpected apply result. Reload the page and try again.');
        }
        if (data.applied === 0) {
            status(results, 'No links could be inserted. ' + data.skipped.join(' '), 'error');
            return;
        }
        var message = 'Inserted ' + data.applied + ' suggested link' + (data.applied === 1 ? '' : 's') + ' and saved the post.' +
            (data.skipped.length ? ' Skipped: ' + data.skipped.join(' ') : '') + ' Reloading the editor…';
        status(results, message, 'success');
        window.setTimeout(function () { window.location.reload(); }, 1500);
    }

    function renderSuggestions(results, data) {
        if (!Array.isArray(data.suggestions)) throw new Error('The server did not return a suggestions list. Please try again.');
        lastSuggestions = data.suggestions;
        clear(results);
        if (!data.suggestions.length) {
            status(results, text(data.message) || 'No relevant links found. Add more content or index more published posts, then try again.');
            return;
        }
        results.appendChild(element('p', 'ks-help', 'Review each suggestion. Apply the ones you keep with Apply All Links — it wraps the anchor phrase in the target link, saves the post, and reloads the editor — or copy a URL and add it yourself using your editor.'));
        if (text(data.message)) results.appendChild(element('p', 'ks-help', data.message));
        var list = element('ul', 'ks-suggestions');
        data.suggestions.forEach(function (suggestion) {
            if (!suggestion || typeof suggestion !== 'object') return;
            var card = element('li', 'ks-suggestion');
            var heading = element('h4', 'ks-suggestion-title');
            var url = safeUrl(suggestion.url);
            var label = text(suggestion.title) || 'Untitled post';
            if (url) {
                var link = element('a', '', label);
                link.href = url;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                heading.appendChild(link);
            } else {
                heading.textContent = label;
            }
            card.appendChild(heading);
            detail(card, 'Why this link', text(suggestion.reason) || 'Review this post for relevance to your content.');
            detail(card, 'Suggested existing anchor', text(suggestion.anchor) || 'Choose relevant text in your draft.');
            detail(card, 'Context in your draft', text(suggestion.quote) || 'No contextual quote provided.', 'blockquote');
            if (url) {
                var urlField = element('input', 'ks-url');
                urlField.type = 'text';
                urlField.readOnly = true;
                urlField.value = url;
                urlField.setAttribute('aria-label', 'URL for ' + label);
                card.appendChild(urlField);
                var copy = element('button', 'button button-secondary ks-copy', 'Copy URL');
                copy.type = 'button';
                var feedback = element('span', 'ks-copy-status');
                feedback.setAttribute('role', 'status');
                copy.addEventListener('click', async function () {
                    copy.disabled = true;
                    try {
                        if (!navigator.clipboard || !navigator.clipboard.writeText) throw new Error('Clipboard unavailable');
                        await navigator.clipboard.writeText(url);
                        feedback.textContent = 'URL copied. Add it to your chosen text in the editor.';
                    } catch (error) {
                        urlField.focus();
                        urlField.select();
                        feedback.textContent = 'Automatic copy is unavailable. Copy the selected URL with Ctrl+C or Command+C.';
                    } finally {
                        copy.disabled = false;
                    }
                });
                card.appendChild(copy);
                card.appendChild(feedback);
            } else {
                card.appendChild(element('p', 'ks-notice ks-notice--error', 'This suggestion has no valid web URL.'));
            }
            list.appendChild(card);
        });
        results.appendChild(list);
    }

    function setupEditor() {
        var root = document.getElementById('knowledgespark-editor');
        if (!root || root.dataset.ksInitialized) return;
        var button = root.querySelector('#ks-suggest');
        var applyButton = root.querySelector('#ks-apply-all');
        var count = root.querySelector('#ks-count');
        var results = root.querySelector('#ks-results');
        if (!button || !count || !results || !applyButton) return;
        root.dataset.ksInitialized = 'true';
        button.type = 'button';
        applyButton.type = 'button';
        results.setAttribute('aria-live', 'polite');
        function setBusy(value) {
            ksBusy = value;
            button.disabled = value;
            applyButton.disabled = value;
        }
        button.addEventListener('click', async function () {
            if (ksBusy) return;
            var amount = Number(count.value);
            if (!Number.isInteger(amount) || amount < 1 || amount > 10) {
                status(results, 'Choose a whole number of suggestions between 1 and 10.', 'error');
                count.focus();
                return;
            }
            var draft = currentPost();
            if (!draft.content.trim()) {
                status(results, 'Write some content in your draft before requesting link suggestions.');
                return;
            }
            setBusy(true);
            button.textContent = 'Finding suggestions…';
            results.setAttribute('aria-busy', 'true');
            status(results, 'Finding relevant links for your current draft. This may take a moment.');
            try {
                var data = await request('suggest', {
                    post_id: Number((window.KnowledgeSpark || {}).postId) || 0,
                    title: draft.title,
                    content: draft.content,
                    count: amount
                });
                renderSuggestions(results, data);
            } catch (error) {
                status(results, error.message || 'Suggestions could not be loaded. Please try again.', 'error');
            } finally {
                button.textContent = 'KnowledgeSpark';
                setBusy(false);
                results.setAttribute('aria-busy', 'false');
            }
        });
        applyButton.addEventListener('click', async function () {
            if (ksBusy) return;
            var applicable = lastSuggestions.filter(function (s) {
                return s && typeof s === 'object' && typeof s.anchor === 'string' && s.anchor.trim() !== '' && safeUrl(s.url);
            });
            if (!lastSuggestions.length) {
                status(results, 'Run KnowledgeSpark first to get suggestions, then apply the ones you want.', 'info');
                return;
            }
            if (!applicable.length) {
                status(results, 'None of these suggestions include an anchor phrase to wrap, so nothing can be inserted automatically. Use Copy URL to place links manually.', 'error');
                return;
            }
            if (!window.confirm('Insert ' + applicable.length + ' suggested link(s) into this post at their anchor phrases, save the post, and reload the editor? Cancelling leaves your content untouched.')) return;
            setBusy(true);
            applyButton.textContent = 'Applying…';
            results.setAttribute('aria-busy', 'true');
            status(results, 'Inserting links and saving…');
            try {
                var be = blockEditorApi();
                if (be) {
                    await applyViaBlockEditor(be, applicable, results);
                } else {
                    await applyViaRest(applicable, results);
                }
            } catch (error) {
                status(results, error.message || 'Applying links failed. Your content was not changed.', 'error');
            } finally {
                applyButton.textContent = 'Apply All Links';
                setBusy(false);
                results.setAttribute('aria-busy', 'false');
            }
        });
    }

    function setupIndex() {
        var root = document.getElementById('ks-index-controls');
        if (!root || root.dataset.ksInitialized) return;
        var test = root.querySelector('#ks-test');
        var index = root.querySelector('#ks-index');
        var reset = root.querySelector('#ks-reset');
        var output = document.getElementById('ks-index-status');
        if (!test || !index || !reset || !output) return;
        root.dataset.ksInitialized = 'true';
        output.setAttribute('role', 'status');
        output.setAttribute('aria-live', 'polite');
        var buttons = [test, index, reset];
        buttons.forEach(function (button) { button.type = 'button'; });
        var cancel = element('button', 'button button-secondary ks-cancel', 'Stop after current batch');
        cancel.type = 'button';
        cancel.hidden = true;
        root.appendChild(cancel);
        var busy = false;
        var cancelled = false;
        var indexing = false;
        function warnBeforeLeaving(event) {
            if (!indexing) return;
            event.preventDefault();
            event.returnValue = '';
        }
        var states = [];
        function lock() {
            busy = true;
            states = buttons.map(function (button) { return button.disabled; });
            buttons.forEach(function (button) { button.disabled = true; });
        }
        function unlock() {
            buttons.forEach(function (button, i) { button.disabled = states[i]; });
            busy = false;
            indexing = false;
            window.removeEventListener('beforeunload', warnBeforeLeaving);
            cancel.hidden = true;
            cancel.disabled = false;
        }
        cancel.addEventListener('click', function () {
            if (!busy) return;
            cancelled = true;
            cancel.disabled = true;
            status(output, 'Stopping after the current batch finishes. Completed work will be kept.');
        });
        test.addEventListener('click', async function () {
            if (busy) return;
            lock();
            status(output, 'Testing the connection…');
            try {
                var data = await request('test');
                status(output, text(data.message) || 'Connection test succeeded.', 'success');
            } catch (error) {
                status(output, error.message, 'error');
            } finally { unlock(); }
        });
        reset.addEventListener('click', async function () {
            if (busy) return;
            if (!window.confirm('Clear the KnowledgeSpark collection? All indexed data will be removed. Your WordPress posts will stay intact. You will need to index your posts again.')) return;
            lock();
            status(output, 'Clearing the collection…');
            try {
                var data = await request('reset');
                status(output, text(data.message) || 'Collection cleared. Index your posts to enable suggestions again.', 'success');
            } catch (error) {
                status(output, error.message, 'error');
            } finally { unlock(); }
        });
        index.addEventListener('click', async function () {
            if (busy) return;
            lock();
            cancelled = false;
            indexing = true;
            window.addEventListener('beforeunload', warnBeforeLeaving);
            cancel.hidden = false;
            var cursor = 0;
            var indexed = 0;
            var failed = 0;
            var skipped = 0;
            var errors = [];
            var done = false;
            function totals() { return indexed + ' newly indexed, ' + skipped + ' already indexed (skipped), ' + failed + ' failed.'; }
            function showErrors() {
                if (!errors.length) return;
                var list = element('ul', 'ks-index-errors');
                errors.forEach(function (message) { list.appendChild(element('li', '', message)); });
                output.appendChild(list);
                if (failed > errors.length) output.appendChild(element('p', 'ks-help', 'Showing up to 20 error details. Check your settings before indexing again.'));
            }
            status(output, 'Keep this page open — indexing published posts… ' + totals());
            try {
                while (!done && !cancelled) {
                    var data = await request('index', { cursor: cursor });
                    if (typeof data.done !== 'boolean' || !Number.isInteger(data.indexed) || data.indexed < 0 || !Number.isInteger(data.failed) || data.failed < 0) {
                        throw new Error('The server returned invalid batch progress. Indexing stopped; check the plugin settings before retrying.');
                    }
                    skipped += Number.isInteger(data.skipped) ? data.skipped : 0;
                    indexed += data.indexed;
                    failed += data.failed;
                    if (Array.isArray(data.errors)) data.errors.forEach(function (error) {
                        var message = typeof error === 'string' ? error : text(error && error.message);
                        if (message && errors.length < 20) errors.push(message);
                    });
                    done = data.done;
                    if (!done && (!Number.isInteger(data.cursor) || data.cursor <= cursor)) {
                        throw new Error('The index cursor did not advance. Indexing stopped to avoid repeating a batch.');
                    }
                    cursor = data.cursor;
                    status(output, 'Keep this page open — indexing published posts… ' + totals() + (text(data.message) ? ' ' + data.message : ''));
                }
                status(output, (done ? 'Indexing complete. ' : 'Indexing stopped after the current batch. ') + totals() + (failed ? ' Review the errors and try indexing again after fixing the cause.' : ''), failed ? 'error' : 'success');
                showErrors();
            } catch (error) {
                status(output, 'Indexing stopped. ' + totals() + ' ' + (error.message || 'Please try again.') + ' Counts include confirmed batches only.', 'error');
                showErrors();
            } finally { unlock(); }
        });
    }

    function init() {
        setupEditor();
        setupIndex();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
}());
