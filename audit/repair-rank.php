<?php
// Repair: remove both misplaced parse_rank_response copies and insert exactly one after rank()'s closing brace.
$file = dirname(__DIR__) . '/includes/class-knowledgespark.php';
$lines = file($file, FILE_IGNORE_NEW_LINES);

// Pass 1: drop every in-body helper copy.
$out = [];
$removed = 0;
$n = count($lines);
for ($i = 0; $i < $n; $i++) {
    $ln = $lines[$i];
    if (strpos($ln, 'private static function parse_rank_response($response) {') !== false) {
        $j = $i + 1;
        while ($j < $n && trim($lines[$j]) !== '}' ) { $j++; }
        $i = $j; // skip through the closing brace (loop increments past it)
        // drop a directly inserted blank line that sits between "return $out;" and the helper
        if (count($out) >= 2 && end($out) === '' && trim(end(array_slice($out, -2, 1))) === 'return $out;') { array_pop($out); }
        $removed++;
        continue;
    }
    $out[] = $ln;
}

// Pass 2: insert one copy after the "}" line that directly precedes api_apply.
$helper = file(dirname(__DIR__) . '/audit/rank-helper.txt', FILE_IGNORE_NEW_LINES);
$final = [];
$inserted = 0;
for ($k = 0; $k < count($out); $k++) {
    $final[] = $out[$k];
    if (trim($out[$k]) === '}' && isset($out[$k + 1]) && $inserted === 0
        && strpos(ltrim($out[$k + 1]), 'public static function api_apply') === 0) {
        foreach ($helper as $h) { $final[] = $h; }
        $inserted++;
    }
}

file_put_contents($file, implode("\n", $final) . "\n");
echo "removed in-body copies: $removed, inserted after rank: $inserted\n";
