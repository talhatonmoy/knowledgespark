// Verifies the shipped admin.js anchor-span logic against the same vectors as tests/smoke.php.
const fs = require('fs');
const src = fs.readFileSync(__dirname + '/../assets/admin.js', 'utf8');
const start = src.indexOf('var TEXT_BLOCK_TYPES');
const end = src.indexOf('function blockEditorApi');
if (start < 0 || end < 0) { console.error('FAIL: could not locate functions in admin.js'); process.exit(1); }
const window = {}; // blockEditorApi reference, unused by pure functions
const fnCode = src.slice(start, end);
const scope = new Function('window', fnCode + '\nreturn { findAnchorSpan };');
const { findAnchorSpan } = scope(window);

function span(content, text) {
    const s = findAnchorSpan(content, text);
    return s === null ? null : content.slice(s.index, s.index + s.span.length);
}
let failed = 0;
function check(cond, msg) {
    if (!cond) { console.error('FAIL: ' + msg); failed++; } else { console.log('PASS: ' + msg); }
}
check(span('<p>The loop wobbles when fog rolls in.</p>', 'loop wobbles') === 'loop wobbles', 'Locate plain anchor span');
check(span('<p>an <em>exact spot</em> to link</p>', 'exact spot') === 'exact spot', 'Locate anchor fully inside an inline tag');
check(span('<p>an <em>exact spot</em> to link</p>', 'exact spot to link') === null, 'Refuse span that would leave an open inline tag');
check(span('<p>x <em>y tail</em></p>', 'x y') === null, 'Refuse span that would break open formatting');
check(span('<p>a <em>x</em> b</p>', 'a x b') === 'a <em>x</em> b', 'Allow span containing a complete inline tag');
check(span('<p>alpha and beta</p><p>gamma delta</p>', 'beta gamma') === null, 'Refuse anchor across paragraph boundary');
check(span('<p>see <a href="https://example.com/existing">the guide</a> again</p>', 'the guide') === null, 'Refuse anchor already inside a link');
check(span('<p>see <a href="https://example.com/existing">the guide</a> again</p>', 'see the') === null, 'Refuse anchor overlapping a link opening');
check(span('<p>finish</p>', 'finish') === 'finish', 'Allow anchor at end of document');
check(span('<p>one<!-- comment -->two</p>', 'one') === 'one', 'Locate anchor beside markup');
// fix-apply-all-links-on-wp7: protected markup (script/style/comment) is opaque, mirroring tests/smoke.php.
const spanHint = (content, textA) => {
    const out = {};
    const s = findAnchorSpan(content, textA, out);
    return { text: s === null ? null : content.slice(s.index, s.index + s.span.length), hint: out.hint, idx: s === null ? null : s.index };
};
let r = spanHint('<script type="application/ld+json">{"@type":"FAQPage","mainEntity":[{"name":"How often should I water"}]}</script><p>visible body text</p>', 'How often should I water');
check(r.text === null && r.hint === 'in_protected', 'Refuse anchor that exists only inside script markup (hint in_protected)');
r = spanHint('<p>the marked trail ends at tar</p><script> </script><p>zan tail follows</p>', 'tarzan');
check(r.text === null && r.hint === 'in_protected', 'Refuse anchor bridged only across a closed script (hint in_protected)');
r = spanHint('<style>.note{color:red} tight pairing sample</style><p>plain text</p>', 'tight pairing');
check(r.text === null && r.hint === 'in_protected', 'Refuse anchor that exists only inside style markup (hint in_protected)');
r = spanHint('<p>visible text</p><!-- hidden phrase lives here -->', 'hidden phrase');
check(r.text === null && r.hint === 'in_protected', 'Refuse anchor that exists only inside an html comment (hint in_protected)');
r = spanHint('<p>good text</p><script>unclosed tail', 'good text');
check(r.text === 'good text' && r.hint === null, 'Unclosed script leaves text before it untouched');
r = spanHint('<p>unclosed trail</p><script>after text', 'after text');
check(r.text === null && r.hint === 'in_protected', 'Unclosed script over-mask refuses text after it');
const doc = '<script type="application/ld+json">{"@type":"BlogPosting","headline":"clean loop cycles"}</script><p>Text about clean loop cycles here.</p>';
r = spanHint(doc, 'clean loop cycles');
check(r.text === 'clean loop cycles' && r.idx > doc.indexOf('</script>') && r.hint === null, 'Prefer body occurrence over same-case script copy');
r = spanHint('<p>see <a href="https://example.com/existing">the guide</a> again</p>', 'the guide');
check(r.text === null && r.hint === 'in_link', 'Report in_link when every occurrence sits inside a link');
r = spanHint('<p>nothing here at all</p>', 'fogs hard all day');
check(r.text === null && r.hint === null, 'Report no hint when the anchor is absent');
r = spanHint('<p>see <a href="https://example.com/homeostasis-in-miniature">other text</a></p>', 'homeostasis');
check(r.text === null && r.hint === null, 'Report no hint when the only occurrence is inside tag markup');
// fix-apply-all-links-on-wp7: stubbed block-editor harness for the attribute chain and skip taxonomy.
const aStart = src.indexOf('async function applyViaBlockEditor');
const aEnd = src.indexOf('async function applyViaRest');
if (aStart < 0 || aEnd < 0) { console.error('FAIL: could not locate applyViaBlockEditor in admin.js'); process.exit(1); }
const domNode = (tag) => ({
    tagName: tag, className: '', children: [], textContent: '',
    setAttribute() {},
    appendChild(c) { this.children.push(c); return c; },
    removeChild(c) { const i = this.children.indexOf(c); if (i >= 0) this.children.splice(i, 1); return c; },
    get firstChild() { return this.children.length ? this.children[0] : null; }
});
global.document = { createElement: (t) => domNode(t) };
const fakeWindow = { setTimeout: () => 0 };
const scope2 = new Function('window', fnCode + '\n' + src.slice(aStart, aEnd) + '\nreturn { applyViaBlockEditor };');
const { applyViaBlockEditor } = scope2(fakeWindow);

function fakeBe(blocks) {
    const replaced = {};
    let saved = false;
    return {
        replaced,
        saved: () => saved,
        be: {
            getBlocks: () => blocks,
            replaceBlock: (id, b) => { replaced[id] = b; },
            savePost: () => { saved = true; return Promise.resolve(); }
        }
    };
}
const readResults = (node) => node.children.map((c) => c.textContent).join('\n');
const articleHtml = '<script type="application/ld+json">{"@type":"FAQPage","name":"json only phrase"}</script><p>plain loop phrase here</p><p>linked already phrase in <a href="https://example.com/existing">linked phrase</a> zone</p>';

(async function harness() {
    const articleBlock = { name: 'core/html', blockId: 'b1', attributes: { content: articleHtml }, innerBlocks: [] };
    const f = fakeBe([articleBlock]);
    const results = domNode('div');
    await applyViaBlockEditor(f.be, [
        { title: 'Plain target', url: 'https://example.com/?p=1', anchor: 'plain loop phrase' },
        { title: 'Linked target', url: 'https://example.com/?p=2', anchor: 'linked phrase' },
        { title: 'Json target', url: 'https://example.com/?p=3', anchor: 'json only phrase' }
    ], results);
    const msg = readResults(results);
    check(f.saved(), 'Save the post when at least one anchor is placeable');
    check(/Inserted 1 suggested link and saved the post\./.test(msg), 'Report the inserted count in the mixed outcome');
    check(msg.indexOf('Linked target: its anchor is already inside a link, so it was left untouched.') !== -1, 'JS cause: already inside a link');
    check(msg.indexOf('Json target: its anchor is only inside script, style, or comment markup, so it was left untouched.') !== -1, 'JS cause: protected markup only');
    check(msg.indexOf('not in the current draft') === -1, 'JS absent-wording not used for found-but-unplaceable anchors');
    const repA = f.replaced.b1;
    check(repA && repA.attributes.content.indexOf('<a href="https://example.com/?p=1" title="Plain target">plain loop phrase</a>') !== -1, 'Insertion goes through the content attribute (WP 7 html block)');
    check(repA && repA.content.indexOf('<a href="https://example.com/?p=1" title="Plain target">plain loop phrase</a>') !== -1, 'Write-back uses the read attribute key at block level');
    check(repA && repA.attributes.content.indexOf('{"@type":"FAQPage","name":"json only phrase"}') !== -1, 'JSON-LD script text left byte-identical in the block');

    const f2 = fakeBe([{ name: 'core/html', blockId: 'b5', attributes: { content: articleHtml }, innerBlocks: [] }]);
    const results2 = domNode('div');
    await applyViaBlockEditor(f2.be, [
        { title: 'Linked target', url: 'https://example.com/?p=2', anchor: 'linked phrase' },
        { title: 'Json target', url: 'https://example.com/?p=3', anchor: 'json only phrase' }
    ], results2);
    const msg2 = readResults(results2);
    check(msg2.indexOf('No links could be inserted at their suggested anchors.') === 0, 'All-unplaceable summary message');
    check(msg2.indexOf('Linked target: its anchor is already inside a link') !== -1 && msg2.indexOf('Json target: its anchor is only inside script') !== -1, 'Per-cause lines in the all-fail summary');
    check(!f2.saved(), 'No save attempted when nothing was inserted');

    const bothBlock = { name: 'core/paragraph', blockId: 'b3', attributes: { innerHTML: '<p>inner wins placeholder</p>', content: '<p>content loses here</p>' }, innerBlocks: [] };
    const f3 = fakeBe([bothBlock]);
    const results3 = domNode('div');
    await applyViaBlockEditor(f3.be, [{ title: 'T', url: 'https://example.com/?p=9', anchor: 'inner wins placeholder' }], results3);
    const repC = f3.replaced.b3;
    check(repC && repC.attributes.innerHTML.indexOf('<a href="https://example.com/?p=9" title="T">inner wins placeholder</a>') !== -1, 'Attribute chain prefers innerHTML when both exist');
    check(repC && repC.attributes.content === '<p>content loses here</p>', 'Attribute chain leaves the unused key untouched');

    const f4 = fakeBe([{ name: 'core/html', blockId: 'b4', attributes: {}, innerBlocks: [] }]);
    const results4 = domNode('div');
    await applyViaBlockEditor(f4.be, [{ title: 'Ghost', url: 'https://example.com/?p=8', anchor: 'plain loop phrase' }], results4);
    const msg4 = readResults(results4);
    check(msg4.indexOf('Ghost: its anchor is not in the current draft.') !== -1, 'Block with no readable content attribute skips as not in the current draft');
    check(!f4.saved(), 'No save when a block has no readable content attribute');
})().then(() => {
    process.exit(failed === 0 ? 0 : 1);
}).catch((err) => {
    console.error('FAIL: harness error: ' + (err && err.message));
    process.exit(1);
});
