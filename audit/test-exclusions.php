<?php
// Task 13: test the plugin's existing-link detection (api_suggest L165-167, replicated verbatim)
// against real WordPress URL resolution. Runs INSIDE the DevKinsta container; DB reads only.
$d = __DIR__;
while ($d !== '/' && !file_exists($d . '/wp-load.php')) { $d = dirname($d); }
require $d . '/wp-load.php';

$target_id = 410;
$canonical = get_permalink($target_id);
$parts = wp_parse_url($canonical);
$scheme = $parts['scheme'] ?? 'https';
$host = $parts['host'] ?? '';
$path = ($parts['path'] ?? '') ?: '/';
$q = isset($parts['query']) ? '?' . $parts['query'] : '';
$hasWWW = strpos($host, 'www.') === 0;
$altHost = $hasWWW ? substr($host, 4) : 'www.' . $host;
$noScheme = preg_replace('|^https?://|', '', $canonical);
$pretty = $q === ''; // pretty permalinks => no ?p= in canonical

$variants = [
    'canonical permalink' => $canonical,
    'trailing slash removed' => untrailingslashit($canonical),
    'http scheme (site https)' => $pretty ? str_replace('https://', 'http://', $canonical) : str_replace('https://', 'http://', home_url('/?p=' . $target_id)),
    'www vs non-www host' => str_replace('://' . $host, '://' . $altHost, $canonical),
    'site-relative /?p=ID' => home_url('/?p=' . $target_id),
    'bare relative ?p=ID' => '?p=' . $target_id,
    'site-relative path only' => $path,
    'protocol-relative //host/?p=ID' => '//' . $host . '/' . '?p=' . $target_id,
    'UPPERCASE scheme+host (with query)' => strtoupper($scheme) . '://' . strtoupper($host) . '/?p=' . $target_id,
    'fragment appended' => $canonical . '#more-' . $target_id,
    'utm query appended' => $canonical . '?utm_source=test',
    'relative path, no leading slash' => ltrim($path, '/'),
];

$links = '';
foreach ($variants as $v) { $links .= '<a href="' . $v . '">x</a>'; }
$links .= '<a href=' . $canonical . '>unquoted</a>'; // unquoted attribute variant
$links .= '<a href="' . $canonical . '?a=1&amp;b=2">encoded</a>'; // encoded ampersand

// --- Replicated verbatim from api_suggest() L165-167 ---
$existing = [];
preg_match_all('/<a\b[^>]*href\s*=\s*[\"\x27]([^\"\x27]+)[\"\x27]/i', $links, $links_matched);
foreach ($links_matched[1] as $url) {
    $url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
    if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) { $url = home_url($url); }
    $linked = url_to_postid($url);
    if ($linked) { $existing[$linked] = true; }
}
// ------------------------------------------------------

printf("canonical: %s (pretty permalinks: %s)\n\n", $canonical, $pretty ? 'yes' : 'no');
printf("%-32s %-8s %-10s %s\n", 'VARIANT', 'RESOLVED', 'ID', 'HREF USED');
foreach ($variants as $name => $href) {
    $resolved = isset($existing[$target_id]) && in_array($name, ['canonical permalink'], true) ? '' : '';
    // Re-resolve individually for a per-variant verdict:
    $u = html_entity_decode($href, ENT_QUOTES, 'UTF-8');
    if (strpos($u, '/') === 0 && strpos($u, '//') !== 0) { $u = home_url($u); }
    $pid = url_to_postid($u);
    printf("%-32s %-8s %-10s %s\n", $name, $pid === $target_id ? 'YES' : 'no', (string) $pid, mb_substr($u, 0, 60));
}
// Unquoted variant handled separately (regex must capture it for exclusion to work):
$unquoted_captured = (bool) preg_match('/<a\b[^>]*href\s*=\s*[\"\x27]([^\"\x27]+)[\"\x27]/i', '<a href=' . $canonical . '>');
printf("%-32s %-8s %-10s %s\n", 'unquoted href attribute', $unquoted_captured ? 'YES' : 'no', $unquoted_captured ? $target_id : '-', '(regex must capture it first)');
echo "\nDetection result: target appears in \$existing map: " . (isset($existing[$target_id]) ? 'YES' : 'NO') . "\n";
echo "URLs captured by regex: " . count($links_matched[1]) . " of " . (count($variants) + 2) . " links in draft\n";
