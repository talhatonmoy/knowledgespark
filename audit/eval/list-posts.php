<?php
// Task 08: list 30 recent published posts to pick the 10-post eval set.
$d = __DIR__;
while ($d !== '/' && !file_exists($d . '/wp-load.php')) { $d = dirname($d); }
if (!file_exists($d . '/wp-load.php')) { fwrite(STDERR, "wp-load.php not found\n"); exit(1); }
require $d . '/wp-load.php';
$posts = get_posts(['numberposts' => 30, 'post_type' => 'post', 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC']);
printf("%-6s %-60s %-19s %s\n", 'ID', 'TITLE', 'DATE', 'WORDS');
foreach ($posts as $p) {
    $words = str_word_count(wp_strip_all_tags($p->post_content));
    printf("%-6d %-60s %-19s %d\n", $p->ID, mb_substr($p->post_title, 0, 60), $p->post_date, $words);
}
echo 'TOTAL: ' . count($posts) . "\n";
