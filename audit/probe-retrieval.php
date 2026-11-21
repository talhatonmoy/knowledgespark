<?php
// Task 10: probe the pre-AI retrieval stage with the EXACT query construction from api_suggest().
// Run inside the DevKinsta FPM container:
//   docker exec devkinsta_fpm php <plugin>/audit/probe-retrieval.php
$d = __DIR__;
while ($d !== '/' && !file_exists($d . '/wp-load.php')) { $d = dirname($d); }
if (!file_exists($d . '/wp-load.php')) { fwrite(STDERR, "wp-load.php not found\n"); exit(1); }
require $d . '/wp-load.php';

if (!class_exists('KnowledgeSpark')) { fwrite(STDERR, "KnowledgeSpark plugin is not active\n"); exit(1); }

$set = json_decode(file_get_contents(__DIR__ . '/eval/eval-set.json'), true);
$s = KnowledgeSpark::settings();

function ks_clip($text, $length) { return function_exists('mb_substr') ? mb_substr($text, 0, $length) : substr($text, 0, $length); }

$only = isset($argv[1]) ? (int) $argv[1] : null; // optional: probe a single post ID
foreach ($set as $entry) {
    $id = (int) $entry['post_id'];
    if ($only && $id !== $only) { continue; }
    $post = get_post($id);
    if (!$post) { echo "$id missing\n"; continue; }

    // --- Query construction replicated from api_suggest() L158-170 (saved-content mode) ---
    $title = KnowledgeSpark::plain($post->post_title);
    $text = KnowledgeSpark::plain($post->post_content);
    $queries = [ks_clip($title, 200) . ' ' . ks_clip($text, 600)];
    preg_match_all('/<h[1-6]\b[^>]*>(.*?)<\/h[1-6]>/is', $post->post_content, $heads);
    foreach (array_slice($heads[1], 0, 5) as $head) {
        $queries[] = ks_clip($title, 160) . ' ' . ks_clip(KnowledgeSpark::plain($head), 400);
    }
    $queries = array_values(array_unique($queries));

    $searches = [];
    foreach ($queries as $q) {
        $searches[] = ['query' => ['text' => $q, 'model' => $s['embedding']], 'limit' => 30, 'with_payload' => false];
    }
    $url = $s['url'] . '/collections/' . rawurlencode($s['collection']) . '/points/query/batch';
    $resp = wp_safe_remote_request($url, [
        'method' => 'POST',
        'timeout' => 45,
        'redirection' => 0,
        'headers' => ['Content-Type' => 'application/json', 'api-key' => $s['key']],
        'body' => wp_json_encode(['searches' => $searches]),
    ]);
    if (is_wp_error($resp)) {
        file_put_contents(__DIR__ . '/runs/probe-' . $id . '.json', wp_json_encode(['error' => $resp->get_error_message()], JSON_PRETTY_PRINT));
        echo "$id PROBE ERROR\n";
        continue;
    }
    $status = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if ($status < 200 || $status >= 300 || !is_array($body)) {
        file_put_contents(__DIR__ . '/runs/probe-' . $id . '.json', wp_json_encode(['error' => 'HTTP ' . $status], JSON_PRETTY_PRINT));
        echo "$id PROBE HTTP $status\n";
        continue;
    }
    $out = ['embedding_model' => $s['embedding'], 'queries' => [], 'results' => []];
    foreach ($queries as $i => $q) {
        $out['queries'][] = ['index' => $i, 'text' => $q];
    }
    foreach (($body['result'] ?? []) as $i => $result) {
        $points = [];
        foreach (($result['points'] ?? []) as $p) {
            $points[] = ['id' => (int) $p['id'], 'score' => (float) $p['score']];
        }
        $out['results'][] = ['query_index' => $i, 'points' => $points];
    }
    file_put_contents(__DIR__ . '/runs/probe-' . $id . '.json', wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "$id probed (" . count($queries) . " queries)\n";
}
echo "ALL PROBES COMPLETE\n";
