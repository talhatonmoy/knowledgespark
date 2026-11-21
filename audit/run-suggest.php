<?php
// Task 09: live-run harness — call api_suggest() for each eval post with SAVED content.
// Run inside the DevKinsta FPM container (host PHP cannot reach devkinsta_db):
//   docker exec devkinsta_fpm php <plugin>/audit/run-suggest.php
$d = __DIR__;
while ($d !== '/' && !file_exists($d . '/wp-load.php')) { $d = dirname($d); }
if (!file_exists($d . '/wp-load.php')) { fwrite(STDERR, "wp-load.php not found\n"); exit(1); }
require $d . '/wp-load.php';

if (!class_exists('KnowledgeSpark')) { fwrite(STDERR, "KnowledgeSpark plugin is not active in this WordPress\n"); exit(1); }

$set = json_decode(file_get_contents(__DIR__ . '/eval/eval-set.json'), true);
if (!is_array($set)) { fwrite(STDERR, "eval-set.json invalid\n"); exit(1); }

foreach ($set as $entry) {
    $id = (int) $entry['post_id'];
    $req = ['post_id' => $id, 'count' => 5]; // no content param -> saved post_content is used
    $out = KnowledgeSpark::api_suggest($req);
    if (is_wp_error($out)) {
        $payload = ['error' => $out->get_error_message(), 'code' => $out->get_error_code()];
    } else {
        $mode = (strpos($out['message'] ?? '', 'AI-reviewed') === 0) ? 'ai' : ((strpos($out['message'] ?? '', 'AI ranking unavailable') === 0) ? 'ai-failed-fallback' : 'semantic');
        $payload = ['mode' => $mode, 'message' => $out['message'] ?? '', 'suggestions' => $out['suggestions'] ?? []];
    }
    file_put_contents(
        __DIR__ . '/runs/post-' . $id . '.json',
        wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    echo $id . " done (" . (isset($payload['mode']) ? $payload['mode'] : 'ERROR') . ")\n";
}
echo "ALL RUNS COMPLETE\n";
