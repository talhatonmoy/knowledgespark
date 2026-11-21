<?php
require "/www/kinsta/public/dev-site/wp-load.php";
$pid = wp_insert_post(array("post_type"=>"post","post_status"=>"draft","post_title"=>"KnowledgeSpark Apply E2E (delete me)","post_content"=>"<p>The loop wobbles when the <em>water loop</em> overheats.</p><p>Second paragraph: fogs settle into the soil fast.</p>"));
echo "temp post id: $pid\n";
$r = KnowledgeSpark::api_apply(array(
  "post_id" => $pid,
  "links" => array(
    array("title"=>"How to Make a Terrarium Jar More Self-Sustaining","url"=>get_permalink(410),"anchor"=>"water loop"),
    array("title"=>"Foggy Terrarium Jar? How to Reduce Condensation","url"=>get_permalink(307),"anchor"=>"fogs settle"),
    array("title"=>"Missing one","url"=>get_permalink(410),"anchor"=>"no such words"),
  ),
));
echo "applied: ".$r["applied"]." skipped: ".count($r["skipped"])."\n";
foreach ($r["skipped"] as $s) { echo "  skip: $s\n"; }
$content = get_post($pid)->post_content;
echo "content after apply:\n$content\n";
$ok1 = strpos($content, '<em><a href="'.get_permalink(410).'">water loop</a></em>') !== false;
$ok2 = strpos($content, '<a href="'.get_permalink(307).'">fogs settle</a>') !== false;
echo "wrap-1 (anchor fully inside em): ".($ok1 ? "OK" : "FAIL")."\n";
echo "wrap-2 (plain anchor): ".($ok2 ? "OK" : "FAIL")."\n";
wp_delete_post($pid, true);
echo "temp post deleted: ".(get_post($pid) === null ? "OK" : "FAIL")."\n";
