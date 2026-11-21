<?php
// Task 11: stage metrics from probe + run files + eval set. Pure JSON transforms; runs on host.
// Usage: php audit/metrics.php
$root = __DIR__;
$set = json_decode(file_get_contents("$root/eval/eval-set.json"), true);

function load_json($f) { $d = json_decode(file_get_contents($f), true); return is_array($d) ? $d : null; }

$rows = [];
$tot = ['retrieved' => 0, 'ideal' => 0, 'pool' => 0, 'final' => 0, 'mustnot_leaked' => 0, 'mustnot_total' => 0, 'overlap_uniq' => 0, 'overlap_slots' => 0];

foreach ($set as $e) {
    $id = (int) $e['post_id'];
    $ideal = array_map(fn($x) => (int) $x['post_id'], $e['ideal']);
    $mustnot = array_map(fn($x) => (int) $x['post_id'], $e['must_not']);

    $probe = load_json("$root/runs/probe-{$id}.json");
    $run = load_json("$root/runs/post-{$id}.json");
    if (!$probe || !$run) { fwrite(STDERR, "missing data for $id\n"); exit(1); }

    // --- Simulate the plugin's pool merge from probe data (self excluded; per-candidate max score) ---
    $best = [];
    foreach ($probe['results'] as $r) {
        foreach ($r['points'] as $p) {
            $pid = (int) $p['id'];
            if ($pid === $id) { continue; }
            $best[$pid] = max($best[$pid] ?? 0, (float) $p['score']);
        }
    }
    arsort($best);
    $pool = array_keys(array_slice($best, 0, 20, true));

    $retrieved = count(array_intersect($ideal, array_keys($best)));
    $inPool = count(array_intersect($ideal, $pool));
    $final = 0; $leaked = 0;
    if (isset($run['suggestions'])) {
        $finalIds = array_map(fn($s) => (int) $s['post_id'], $run['suggestions']);
        $final = count(array_intersect($ideal, $finalIds));
        $leaked = count(array_intersect($mustnot, $finalIds));
    } else { $finalIds = []; }

    // Query overlap: unique candidates vs total slots
    $slots = 0; $uniq = [];
    foreach ($probe['results'] as $r) { $slots += count($r['points']); foreach ($r['points'] as $p) { $uniq[(int) $p['id']] = true; } }

    $rows[] = ['id' => $id, 'ideal_n' => count($ideal), 'retrieved30' => $retrieved, 'pool20' => $inPool, 'final5' => $final, 'mustnot_leaked' => $leaked, 'mustnot_n' => count($mustnot), 'run_mode' => $run['mode'] ?? 'error', 'uniq' => count($uniq), 'slots' => $slots];
    $tot['retrieved'] += $retrieved; $tot['ideal'] += count($ideal); $tot['pool'] += $inPool; $tot['final'] += $final; $tot['mustnot_leaked'] += $leaked; $tot['mustnot_total'] += count($mustnot); $tot['overlap_uniq'] += count($uniq); $tot['overlap_slots'] += $slots;
}

printf("%-6s %-8s %-12s %-9s %-8s %-12s %-7s %s\n", 'POST', 'MODE', 'IDEAL@30', 'POOL@20', 'FINAL@5', 'MUSTNOT', 'UNIQ', 'SLOTS');
foreach ($rows as $r) {
    printf("%-6d %-8s %-12s %-9s %-8s %-12s %-7d %d\n", $r['id'], $r['run_mode'], "{$r['retrieved30']}/{$r['ideal_n']}", "{$r['pool20']}/{$r['ideal_n']}", "{$r['final5']}/{$r['ideal_n']}", "{$r['mustnot_leaked']}/{$r['mustnot_n']}", $r['uniq'], $r['slots']);
}
printf("%-6s %-8s %-12s %-9s %-8s %-12s %-7d %d\n", 'TOTAL', '', "{$tot['retrieved']}/{$tot['ideal']}", "{$tot['pool']}/{$tot['ideal']}", "{$tot['final']}/{$tot['ideal']}", "{$tot['mustnot_leaked']}/{$tot['mustnot_total']}", $tot['overlap_uniq'], $tot['overlap_slots']);
echo 'Slot efficiency (unique/slots): ' . round(100 * $tot['overlap_uniq'] / max(1, $tot['overlap_slots']), 1) . "%\n";
