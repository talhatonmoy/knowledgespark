<?php
// Task 16: query-variant experiment for posts with RETRIEVAL_MISS.
// Variant A: main query uses first 1500 chars (instead of 600); heading queries unchanged.
// Variant B: main query unchanged; heading queries = heading + its next paragraph.
// Search-only (no inference). Run inside the DevKinsta container:
//   docker exec devkinsta_fpm php <plugin>/audit/probe-variants.php
$d = __DIR__;
while ($d !== '/' && !file_exists($d . '/wp-load.php')) { $d = dirname($d); }
require $d . '/wp-load.php';

$affected = [410, 406, 402, 399, 394, 393, 381]; // posts with >=1 RETRIEVAL_MISS (Task 12)
$setRaw = json_decode(file_get_contents(__DIR__ . '/eval/eval-set.json'), true);
$idealByPost = [];
foreach ($setRaw as $e) { $idealByPost[(int) $e['post_id']] = array_map(fn($x) => (int) $x['post_id'], $e['ideal']); }
$s = KnowledgeSpark::settings();

function ks_clip($t, $n) { return function_exists('mb_substr') ? mb_substr($t, 0, $n) : substr($t, 0, $n); }

// heading => following-paragraph segments
function ks_head_paragraphs($content) {
    preg_match_all('/<h[1-6]\b[^>]*>(.*?)<\/h[1-6]>(.*?)(?=<h[1-6]\b|$)/is', $content, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $seg) { $out[] = [KnowledgeSpark::plain($seg[1]), KnowledgeSpark::plain($seg[2])]; }
    return $out;
}

function ks_probe($s, $queries) {
    $searches = [];
    foreach (array_values(array_unique($queries)) as $q) { $searches[] = ['query' => ['text' => $q, 'model' => $s['embedding']], 'limit' => 30, 'with_payload' => false]; }
    $r = wp_safe_remote_request($s['url'] . '/collections/' . rawurlencode($s['collection']) . '/points/query/batch', ['method' => 'POST', 'timeout' => 45, 'redirection' => 0, 'headers' => ['Content-Type' => 'application/json', 'api-key' => $s['key']], 'body' => wp_json_encode(['searches' => $searches])]);
    if (is_wp_error($r)) { return ['error' => $r->get_error_message()]; }
    $st = wp_remote_retrieve_response_code($r);
    $b = json_decode(wp_remote_retrieve_body($r), true);
    if ($st < 200 || $st >= 300 || !is_array($b)) { return ['error' => 'HTTP ' . $st]; }
    $all = [];
    foreach (($b['result'] ?? []) as $res) { foreach (($res['points'] ?? []) as $p) { $all[(int) $p['id']] = max($all[(int) $p['id']] ?? 0, (float) $p['score']); } }
    return ['candidates' => $all];
}

foreach ($affected as $id) {
    $post = get_post($id);
    $title = KnowledgeSpark::plain($post->post_title);
    $text = KnowledgeSpark::plain($post->post_content);
    $heads = ks_head_paragraphs($post->post_content);

    // Original construction (baseline reference)
    $origMain = ks_clip($title, 200) . ' ' . ks_clip($text, 600);
    $origHeads = [];
    foreach (array_slice($heads, 0, 5) as $h) { $origHeads[] = ks_clip($title, 160) . ' ' . ks_clip($h[0], 400); }

    // Variant A: 1500-char main
    $aQueries = array_merge([$origMain0 = ks_clip($title, 200) . ' ' . ks_clip($text, 1500)], $origHeads);
    // Variant B: heading + next paragraph
    $bQueries = array_merge([$origMain], []);
    foreach (array_slice($heads, 0, 5) as $h) { $bQueries[] = ks_clip($title, 160) . ' ' . ks_clip($h[0], 200) . ' ' . ks_clip($h[1], 400); }

    $resA = ks_probe($s, $aQueries);
    $resB = ks_probe($s, $bQueries);
    $ideal = $idealByPost[$id];

    $orig = json_decode(file_get_contents(__DIR__ . '/runs/probe-' . $id . '.json'), true);
    $origCand = [];
    foreach (($orig['results'] ?? []) as $r) { foreach (($r['points'] ?? []) as $p) { $origCand[(int) $p['id']] = true; } }

    $sum = function ($cand) use ($ideal) { return isset($cand['error']) ? -1 : count(array_intersect($ideal, array_keys($cand['candidates']))); };

    file_put_contents(__DIR__ . '/runs/probe-alt-A-' . $id . '.json', wp_json_encode(['queries' => array_values(array_unique($aQueries)), 'result' => $resA], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents(__DIR__ . '/runs/probe-alt-B-' . $id . '.json', wp_json_encode(['queries' => array_values(array_unique($bQueries)), 'result' => $resB], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    printf("%d | ideal=%d | orig=%d | A(1500)=%d | B(head+para)=%d\n", $id, count($ideal), count(array_intersect($ideal, array_keys($origCand))), $sum($resA), $sum($resB));
}
echo "VARIANTS COMPLETE\n";
