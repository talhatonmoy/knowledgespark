<?php
// Audit harness: complete the collection index using the plugin's own sync() logic.
// Resumable: already-indexed posts are skipped by the plugin's fingerprint/legacy checks.
// Run inside the DevKinsta FPM container:
//   docker exec devkinsta_fpm php <plugin>/audit/index-all.php [limit]
$d = __DIR__;
while ($d !== '/' && !file_exists($d . '/wp-load.php')) { $d = dirname($d); }
if (!file_exists($d . '/wp-load.php')) { fwrite(STDERR, "wp-load.php not found\n"); exit(1); }
require $d . '/wp-load.php';

if (!class_exists('KnowledgeSpark')) { fwrite(STDERR, "KnowledgeSpark plugin is not active\n"); exit(1); }

$limit = isset($argv[1]) ? (int) $argv[1] : 0;
$stateFile = __DIR__ . '/runs/index-progress.json';

global $wpdb;
$ids = array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_password = '' ORDER BY ID ASC"));
$total = count($ids);
if ($limit > 0) { $ids = array_slice($ids, 0, $limit); }

$state = ['total' => $total, 'todo' => count($ids), 'processed' => 0, 'indexed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'started' => date('c'), 'updated' => date('c'), 'last_id' => 0, 'done' => false];
file_put_contents($stateFile, wp_json_encode($state));

foreach ($ids as $i => $id) {
    $r = KnowledgeSpark::sync($id);
    if (is_wp_error($r)) {
        $state['failed']++;
        if (count($state['errors']) < 50) { $state['errors'][] = 'Post ' . $id . ': ' . $r->get_error_message(); }
    } elseif ($r === 'skipped') {
        $state['skipped']++;
    } else {
        $state['indexed']++;
    }
    $state['processed']++;
    $state['last_id'] = $id;
    $state['updated'] = date('c');
    $state['done'] = ($i + 1 >= count($ids));
    file_put_contents($stateFile, wp_json_encode($state));
    if (($i + 1) % 25 === 0 || $state['done']) {
        echo "processed {$state['processed']}/{$state['todo']} (indexed {$state['indexed']}, skipped {$state['skipped']}, failed {$state['failed']})\n";
    }
}
echo "INDEXING HARNESS COMPLETE\n";
