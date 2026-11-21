<?php
// Line-based patcher: swaps rank()'s AI request block for the 16000-token + retry + diagnostics version,
// and inserts the parse_rank_response() helper.
$file = dirname(__DIR__) . '/includes/class-knowledgespark.php';
$lines = file($file);
$newBlock = file(dirname(__DIR__) . '/audit/rank-new.txt', FILE_IGNORE_NEW_LINES);
$helper = file(dirname(__DIR__) . '/audit/rank-helper.txt', FILE_IGNORE_NEW_LINES);

// 1) Locate the block to replace.
$startIdx = null; $endIdx = null;
foreach ($lines as $i => $ln) {
    if ($startIdx === null && strpos($ln, "$r=self::request(\$s['ai_url'].'/chat/completions'") !== false && strpos($ln, "max_tokens") !== false) { $startIdx = $i; }
    if ($startIdx !== null && strpos($ln, "AI returned an invalid recommendation format") !== false) { $endIdx = $i; break; }
}
if ($startIdx === null || $endIdx === null || $endIdx < $startIdx) { fwrite(STDERR, "block anchors not found: start=" . var_export($startIdx, true) . " end=" . var_export($endIdx, true) . "\n"); exit(1); }

// 2) Splice in the new block (rank-new.txt already carries correct indentation).
$out = array_merge(array_slice($lines, 0, $startIdx), $newBlock, array_slice($lines, $endIdx + 1));

// 3) Insert helper after rank()'s closing brace (the "return $out;" + next "}") line.
$final = [];
for ($i = 0; $i < count($out); $i++) {
    $final[] = $out[$i];
    if (trim($out[$i]) === 'return $out;' && isset($out[$i + 1]) && trim($out[$i + 1]) === '}') {
        $final[] = '';
        foreach ($helper as $h) { $final[] = $h; }
    }
}

file_put_contents($file, implode("\n", $final) . "\n");
echo "patched: replaced lines " . ($startIdx + 1) . "-" . ($endIdx + 1) . " and inserted helper\n";
