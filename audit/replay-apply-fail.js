// Replays the shipped applyAll client logic against the real post 410 draft to diagnose "anchor was not found in the current draft".
const fs = require('fs');
const src = fs.readFileSync(__dirname + '/../assets/admin.js', 'utf8');
const start = src.indexOf('var VOID_TAGS');
const end = src.indexOf('function blockEditorApi');
const scope = new Function('window', src.slice(start, end) + '\nreturn { findAnchorSpan };');
const { findAnchorSpan } = scope({});

const content = fs.readFileSync(__dirname + '/post-410-content.html', 'utf8');

const anchors = [
    'closed terrarium water cycle',
    'homeostasis',
    'fogs hard all day',
    'early warning signs',
    'A sealed lid',
];

// Split the post into top-level blocks the way the editor stores them (their innerHTML).
const blockTags = [
    ['p', /<p\b[^>]*>([\s\S]*?)<\/p>/g],
    ['heading', /<h[1-6]\b[^>]*>([\s\S]*?)<\/h[1-6]>/g],
    ['ul', /<ul\b[^>]*>([\s\S]*?)<\/ul>/g],
    ['ol', /<ol\b[^>]*>([\s\S]*?)<\/ol>/g],
    ['blockquote', /<blockquote\b[^>]*>([\s\S]*?)<\/blockquote>/g],
    ['pre', /<pre\b[^>]*>([\s\S]*?)<\/pre>/g],
    ['figure', /<figure\b[^>]*>([\s\S]*?)<\/figure>/g],
    ['table', /<table\b[^>]*>([\s\S]*?)<\/table>/g],
];
const found = [];
for (const [kind, re] of blockTags) {
    let m;
    while ((m = re.exec(content)) !== null) {
        found.push({ kind, index: m.index, inner: m[1] });
    }
}
found.sort((a, b) => a.index - b.index);
console.log('top-level blocks extracted: ' + found.length + '\n');

for (const anchor of anchors) {
    const whole = findAnchorSpan(content, anchor);
    const hits = found.filter((b) => findAnchorSpan(b.inner, anchor));
    const idx = content.indexOf(anchor);
    console.log('anchor: ' + JSON.stringify(anchor));
    console.log('  plain indexOf in raw HTML: ' + (idx === -1 ? 'MISSING' : 'at ' + idx));
    console.log('  findAnchorSpan over WHOLE content: ' + (whole ? 'OK span=' + JSON.stringify(whole.span) : 'NULL'));
    console.log('  findAnchorSpan over per-block innerHTML: ' + (hits.length ? 'found in ' + hits.length + ' block(s): [' + hits.map((b) => b.kind).join(', ') + ']' : 'NOT FOUND IN ANY BLOCK'));
    if (idx !== -1) {
        // which top-level block encloses the raw indexOf position?
        const enc = found.filter((b) => b.index <= idx && idx <= b.index + (content.length - idx ? 1e9 : 0));
        const host = found.filter((b) => {
            const blockEnd = content.indexOf('>', b.index) === -1 ? b.index : b.index; // approximate: containment by start order
            return b.index <= idx;
        }).pop();
        if (host) console.log('  raw-text position sits after block start of: ' + host.kind + ' (block starts at ' + host.index + ')');
    }
    console.log('');
}
