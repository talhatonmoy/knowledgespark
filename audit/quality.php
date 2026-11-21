<?php
// Task 14: redundancy, anchor blank rate, and reason specificity across run files. Host-runnable.
// Usage: php audit/quality.php
$root = __DIR__;
$set = json_decode(file_get_contents("$root/eval/eval-set.json"), true);

// Generic-reason heuristics: reasons that carry no post-specific signal.
$generic_patterns = 'related to|not been assessed|helps (the reader|readers)|useful (for|in)|relevant (to|for)';
// Specific-reason heuristics: names a concrete concept/action/section from the domain.
$specific_patterns = '/\b(test|prune|sterili[sz]|gnat|moss|seal|open|trap|springtail|isopod|substrate|drainage|light|water|timeline|rebalance|identify|prevent|eliminat)\w*/i';

$totSets = 0; $totRedundant = 0; $totSuggestions = 0; $totAnchored = 0; $totSpecific = 0; $totGeneric = 0; $perMode = [];
foreach ($set as $e) {
    $id = (int) $e['post_id'];
    $run = json_decode(file_get_contents("$root/runs/post-{$id}.json"), true);
    if (!isset($run['suggestions'])) { echo "$id: RUN ERROR — skipped\n"; continue; }
    $sugg = $run['suggestions'];
    $totSets++; $totSuggestions += count($sugg);
    $mode = $run['mode'] ?? '?';

    // Redundancy: >=3 of 5 suggestions sharing a loose topical keyword bucket
    $buckets = [];
    foreach ($sugg as $s) {
        $t = mb_strtolower($s['title'] . ' ' . ($s['reason'] ?? ''));
        foreach (['gnat', 'moss', 'jar', 'water', 'light', 'self-sustaining', 'history', 'beginner'] as $kw) {
            if (strpos($t, $kw) !== false) { $buckets[$kw] = ($buckets[$kw] ?? 0) + 1; break; }
        }
    }
    $redundant = max($buckets ?: [0]) >= 3;
    if ($redundant) { $totRedundant++; }

    $anchored = 0; $specific = 0;
    foreach ($sugg as $s) {
        if (!empty($s['anchor'])) { $anchored++; }
        $reason = mb_strtolower($s['reason'] ?? '');
        $isSpecific = preg_match($specific_patterns, $reason) && !preg_match('/^' . $generic_patterns . '$/i', trim($reason)) && strlen($reason) > 40;
        if ($isSpecific) { $specific++; } else { $totGeneric++; }
    }
    $totAnchored += $anchored; $totSpecific += $specific;
    $perMode[$mode][] = $id . ($redundant ? ' (REDUNDANT)' : '');
    printf("%-4d %-18s suggestions=%d anchored=%d specific_reasons=%d %s\n", $id, $mode, count($sugg), $anchored, $specific, $redundant ? 'REDUNDANT-SET' : '');
}
echo "\nSet redundancy: {$totRedundant}/{$totSets}\n";
echo "Anchor coverage: {$totAnchored}/{$totSuggestions}\n";
echo "Reason quality: specific {$totSpecific} vs generic {$totGeneric}\n";
foreach ($perMode as $mode => $ids) { echo $mode . ': ' . implode(', ', $ids) . "\n"; }
