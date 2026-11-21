// Verifies the shipped admin.js anchor-span logic against the same vectors as tests/smoke.php.
const fs = require('fs');
const src = fs.readFileSync(__dirname + '/../assets/admin.js', 'utf8');
const start = src.indexOf('var VOID_TAGS');
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
process.exit(failed === 0 ? 0 : 1);
