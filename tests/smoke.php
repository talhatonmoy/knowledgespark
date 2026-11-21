<?php
// Standalone behavioral checks; no WordPress installation or external services needed.
define('ABSPATH', __DIR__);
class WP_Error { private $code; private $message; private $data; public function __construct($code,$message,$data=null){$this->code=$code;$this->message=$message;$this->data=$data;} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} public function get_error_data(){return $this->data;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_parse_args($a,$b){return array_merge($b,$a);}
function get_option($k,$d=[]){if($k==='knowledgespark_index_job'){return $GLOBALS['options'][$k] ?? $d;}return $GLOBALS['settings'] ?? [];}
function home_url($p=''){return 'https://example.com'.$p;}
function get_post($id){return $GLOBALS['posts'][$id] ?? null;}
function is_post_publicly_viewable($p){return $p->post_status==='publish';}
function get_post_type($id){$p=get_post($id);return $p?$p->post_type:false;}
function wp_strip_all_tags($s){return strip_tags($s);}
function strip_shortcodes($s){return $s;}
function absint($v){return abs((int)$v);}
function get_permalink($id){return 'https://example.com/?p='.$id;}
function url_to_postid($url){ if(strpos($url,'existing')!==false){return 3;} if(preg_match('/[?&]p=(\d+)/',$url,$m)){$id=(int)$m[1]; return isset($GLOBALS['posts'][$id]) && $GLOBALS['posts'][$id]->post_type==='post' ? $id : 0;} return 0;}
function wp_parse_url($url,$component=-1){return parse_url($url,$component);}
function wp_json_encode($v){return json_encode($v);}
function sanitize_text_field($s){return trim(strip_tags($s));}
function wp_remote_retrieve_response_code($r){return $r['status'];}
function wp_remote_retrieve_body($r){return $r['body'];}
function delete_post_meta($id,$key){if(isset($GLOBALS['ks_meta'][$id][$key])){unset($GLOBALS['ks_meta'][$id][$key]);}}
function update_post_meta($id,$key,$value){$GLOBALS['ks_meta'][$id][$key]=$value;}
function update_option($k,$v,$autoload=null){$GLOBALS['options'][$k]=$v;return true;}
function wp_schedule_single_event($ts,$hook,$args=[]){$GLOBALS['ks_cron'][$hook]=$ts;return true;}
function wp_next_scheduled($hook,$args=[]){return isset($GLOBALS['ks_cron'][$hook])?$GLOBALS['ks_cron'][$hook]:false;}
function wp_clear_scheduled_hook($hook,$args=[]){unset($GLOBALS['ks_cron'][$hook]);}
function spawn_cron(){$GLOBALS['ks_spawns']=isset($GLOBALS['ks_spawns'])?$GLOBALS['ks_spawns']+1:1;}
function wp_safe_remote_request($url,$args){
 if(isset($GLOBALS['flaky']) && $GLOBALS['flaky']>=0 && strpos($url,'chat/completions')===false){
  $GLOBALS['flaky']--;
  return new WP_Error('http_request_failed','cURL error 60: SSL: no alternative certificate subject name matches target host name');
 }
 if(isset($GLOBALS['index_test'])) {
  if($args['method']==='PUT') {
   $GLOBALS['writes']++;
   if(!empty($GLOBALS['fail_write'])) { return new WP_Error('failed','Write failed'); }
   $body=json_decode($args['body'],true);
   $GLOBALS['remote_payload']=$body['points'][0]['payload'];
   return ['status'=>200,'body'=>'{"result":{}}'];
  }
  return ['status'=>200,'body'=>json_encode(['result'=>isset($GLOBALS['remote_payload'])?[['payload'=>$GLOBALS['remote_payload']]]:[]])];
 }
 if(isset($GLOBALS['ks_batch'])) {
  $GLOBALS['ks_calls'][]=$args['method'];
  $body=$args['body']===null?null:json_decode($args['body'],true);
  if($args['method']==='GET') { return ['status'=>200,'body'=>json_encode(['result'=>['status'=>'green','config'=>['params'=>['vectors'=>['size'=>384,'distance'=>'Cosine']]]]])]; }
  if($args['method']==='DELETE') { $GLOBALS['ks_reset']=true; return ['status'=>200,'body'=>'{"result":true}']; }
  if($args['method']==='POST' && isset($body['ids'])) {
   if(!empty($GLOBALS['ks_delete_during_get']) && isset($GLOBALS['posts'][$GLOBALS['ks_delete_during_get']])) { $GLOBALS['posts'][$GLOBALS['ks_delete_during_get']]->post_status='draft'; $GLOBALS['ks_delete_during_get']=0; }
   if(!empty($GLOBALS['ks_fail_get'])) { return new WP_Error('http_request_failed','Retrieve failed'); }
   $GLOBALS['ks_get_ids']=$body['ids'];
   $out=[]; foreach($body['ids'] as $pid){ if(isset($GLOBALS['ks_stored'][$pid])) { $out[]=['id'=>(int)$pid,'payload'=>$GLOBALS['ks_stored'][$pid]]; } }
   return ['status'=>200,'body'=>json_encode(['result'=>$out])];
  }
  if($args['method']==='POST') { if(isset($body['points'])) { $GLOBALS['ks_point_deletes']=array_merge((array)($GLOBALS['ks_point_deletes'] ?? []),$body['points']); } return ['status'=>200,'body'=>'{"result":{}}']; }
  if($args['method']==='PUT') {
   if(!empty($GLOBALS['ks_fail_put'])) { return new WP_Error('http_request_failed','Write failed'); }
   $GLOBALS['ks_put_ids']=array_column($body['points'] ?? [],'id');
   return ['status'=>200,'body'=>'{"result":{}}'];
  }
 }
if(strpos($url,'chat/completions')!==false){ if(isset($GLOBALS['ks_chat_body'])){ return ['status'=>200,'body'=>$GLOBALS['ks_chat_body']]; } if(!empty($GLOBALS['ks_format_fail'])) { $GLOBALS['ks_format_fail']--; return ['status'=>200,'body'=>"{\"choices\":[{\"finish_reason\":\"length\",\"message\":{\"content\":\"{\\\"suggestions\\\":[{\\\"post_id\\\":2,\\\"reason\\\":\\\"truncat\"}}]}"]; } return ['status'=>200,'body'=>"{\"choices\":[{\"message\":{\"content\":\"{\\\"suggestions\\\":[{\\\"post_id\\\":2,\\\"reason\\\":\\\"Helps explain setup\\\",\\\"anchor\\\":\\\"invented phrase\\\",\\\"quote\\\":\\\"invented sentence\\\"},{\\\"post_id\\\":999,\\\"reason\\\":\\\"invented target\\\"}]}\"}}]}"]; }
 if(isset($GLOBALS['ks_points_ids'])){ $pts=[]; foreach($GLOBALS['ks_points_ids'] as $i=>$pid){ $pts[]=['id'=>(int)$pid,'score'=>1-$i*0.1]; } return ['status'=>200,'body'=>json_encode(['result'=>[['points'=>$pts]]])]; }
 return ['status'=>200,'body'=>json_encode(['result'=>[['points'=>[['id'=>1,'score'=>1],['id'=>2,'score'=>0.9],['id'=>3,'score'=>0.8],['id'=>4,'score'=>0.7],['id'=>5,'score'=>0.6]]]]])];
}
require __DIR__.'/../includes/class-knowledgespark.php';
$GLOBALS['settings']=['url'=>'https://qdrant.example.com','key'=>'test'];
foreach([1,2,3,4,5] as $id){$GLOBALS['posts'][$id]=(object)['post_type'=>'post','post_status'=>$id===4?'draft':'publish','post_password'=>$id===5?'secret':'','post_title'=>'Post '.$id,'post_content'=>'Useful reference content for setting up your website.'];}
$request=['post_id'=>1,'title'=>'Setup','content'=>'<p>This is a sufficiently long article about setting up a website with useful instructions.</p><a href="https://example.com/existing">Existing link</a>','count'=>5];
function check($condition,$message){if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}echo "PASS: $message\n";}
$r=KnowledgeSpark::api_suggest($request);
check(count($r['suggestions'])===1 && $r['suggestions'][0]['post_id']===2,'Exclude self, already linked, private and unpublished targets');
$GLOBALS['settings']['ai_key']='test';
$r=KnowledgeSpark::api_suggest($request);
check(count($r['suggestions'])===1,'Reject invented AI target IDs');
check($r['suggestions'][0]['anchor']==='' && $r['suggestions'][0]['quote']==='','Reject invented anchor and quote');
$request['content']='short';
check(is_wp_error(KnowledgeSpark::api_suggest($request)),'Reject insufficient source content');
$GLOBALS['index_test']=true;
$GLOBALS['writes']=0;
$GLOBALS['posts'][2]->post_modified_gmt='2026-11-16 12:00:00';
$GLOBALS['posts'][2]->post_excerpt='';
check(KnowledgeSpark::sync(2)===true && $GLOBALS['writes']===1,'Embed missing point');
check(KnowledgeSpark::sync(2)==='skipped' && $GLOBALS['writes']===1,'Skip unchanged fingerprint without embedding');
$GLOBALS['posts'][2]->post_content.=' Changed content.';
check(KnowledgeSpark::sync(2)===true && $GLOBALS['writes']===2,'Re-embed changed content');
unset($GLOBALS['remote_payload']);
check(KnowledgeSpark::sync(2)===true && $GLOBALS['writes']===3,'Re-embed after collection reset or point deletion');
$GLOBALS['remote_payload']=['modified'=>'2026-11-16 12:00:00','url'=>get_permalink(2)];
check(KnowledgeSpark::sync(2)==='skipped' && $GLOBALS['writes']===3,'Skip existing original MVP embeddings');
$GLOBALS['posts'][2]->post_modified_gmt='2026-11-16 13:00:00';
$GLOBALS['fail_write']=true;
check(is_wp_error(KnowledgeSpark::sync(2)),'Report failed embedding');
$GLOBALS['fail_write']=false;
check(KnowledgeSpark::sync(2)===true,'Retry failed embedding instead of skipping');
$order=KnowledgeSpark::box_order(['side'=>'submitdiv,knowledgespark','normal'=>'otherbox']);
check($order['side']==='submitdiv' && $order['normal']==='knowledgespark,otherbox','Move saved sidebar placement below editor without disturbing other boxes');
// 0.0.1: Apply All Links anchor-span matcher.
function span($content,$text){$s=KnowledgeSpark::find_anchor_span($content,$text);return $s===null?null:substr($content,$s[0],$s[1]-$s[0]);}
check(span('<p>The loop wobbles when fog rolls in.</p>','loop wobbles')==='loop wobbles','Locate plain anchor span');
check(span('<p>an <em>exact spot</em> to link</p>','exact spot')==='exact spot','Locate anchor fully inside an inline tag');
check(span('<p>an <em>exact spot</em> to link</p>','exact spot to link')===null,'Refuse span that would leave an open inline tag');
check(span('<p>x <em>y tail</em></p>','x y')===null,'Refuse span that would break open formatting');
check(span('<p>a <em>x</em> b</p>','a x b')==='a <em>x</em> b','Allow span containing a complete inline tag');
check(span('<p>alpha and beta</p><p>gamma delta</p>','beta gamma')===null,'Refuse anchor across paragraph boundary');
check(span('<p>see <a href="https://example.com/existing">the guide</a> again</p>','the guide')===null,'Refuse anchor already inside a link');
check(span('<p>see <a href="https://example.com/existing">the guide</a> again</p>','see the')===null,'Refuse anchor overlapping a link opening');
check(span('<p>finish</p>','finish')==='finish','Allow anchor at end of document');
check(span('<p>one<!-- comment -->two</p>','one')==='one','Locate anchor beside markup');
// 0.0.1 fix-apply-all-links-on-wp7: protected markup (script/style/comment) is opaque to anchor spans.
check(span('<script type="application/ld+json">{"@type":"FAQPage","mainEntity":[{"name":"How often should I water"}]}</script><p>visible body text</p>','How often should I water')===null,'Refuse anchor that exists only inside script markup');
check(span('<p>the marked trail ends at tar</p><script> </script><p>zan tail follows</p>','tarzan')===null,'Refuse anchor bridged only across a closed script');
check(span('<style>.note{color:red} tight pairing sample</style><p>plain text</p>','tight pairing')===null,'Refuse anchor that exists only inside style markup');
check(span('<p>visible text</p><!-- hidden phrase lives here -->','hidden phrase')===null,'Refuse anchor that exists only inside an html comment');
check(span('<p>good text</p><script>unclosed tail','good text')==='good text','Unclosed script leaves text before it untouched');
check(span('<p>unclosed trail</p><script>after text','after text')===null,'Unclosed script over-mask refuses text after it');
$doc='<script type="application/ld+json">{"@type":"BlogPosting","headline":"clean loop cycles"}</script><p>Text about clean loop cycles here.</p>';
$sr=KnowledgeSpark::find_anchor_span($doc,'clean loop cycles');
check($sr!==null && substr($doc,$sr[0],$sr[1]-$sr[0])==='clean loop cycles' && $sr[0]>strpos($doc,'</script>'),'Prefer body occurrence over same-case script copy');
$reason=null; KnowledgeSpark::find_anchor_span('<p>see <a href="https://example.com/existing">the guide</a> again</p>','the guide',$reason);
check($reason==='in_link','Report in_link when every occurrence sits inside a link');
$reason=null; KnowledgeSpark::find_anchor_span('<script>{"q":"only phrase here"}</script><p>other text</p>','only phrase',$reason);
check($reason==='in_protected','Report in_protected when every occurrence sits inside script markup');
$reason=null; KnowledgeSpark::find_anchor_span('<p>nothing here at all</p>','fogs hard all day',$reason);
check($reason===null,'Report no reason when the anchor is absent');
$reason=null; KnowledgeSpark::find_anchor_span('<p>see <a href="https://example.com/homeostasis-in-miniature">other text</a></p>','homeostasis',$reason);
check($reason===null,'Report no reason when the only occurrence is inside tag markup');
// 0.0.1.4 fix-apply-all-links-on-wp71: entity-aware matching (same token table and joiner as the JS mirror).
check(span('<p>Balance Light, Water &amp; Plants is about three things.</p>','Light, Water & Plants')==='Light, Water &amp; Plants','Ampersand anchor matches its &amp; form and keeps the raw slice');
check(span('<p>Balance Light, Water &#38; Plants one system.</p>','Light, Water & Plants')==='Light, Water &#38; Plants','Ampersand anchor matches its decimal numeric form');
check(span('<p>Balance Light, Water &#x26; Plants one system.</p>','Light, Water & Plants')==='Light, Water &#x26; Plants','Ampersand anchor matches its hex numeric form');
check(span('<p>strong&nbsp;bond forms here</p>','strong bond')==='strong&nbsp;bond','Anchor space matches &nbsp;');
check(span('<p>strong&#160;bond forms here</p>','strong bond')==='strong&#160;bond','Anchor space matches the decimal no-break form');
check(span('<p>pair a&nbsp;b here</p>','ab')==='a&nbsp;b','Joiner bridges a no-break-space between plain characters');
check(span('<span title="Light, Water &amp; Plants">other words</span>','Light, Water & Plants')===null,'Entity forms inside attribute values stay unresolvable');
check(span('<p>odd &#39;quote&#39; style marks</p>',"odd 'quote' style")==='odd &#39;quote&#39; style','Apostrophe anchor matches its entity forms');
check(span('<p>danger &lt;tag&gt; words</p>','danger <tag> words')==='danger &lt;tag&gt; words','Angle brackets match their entity forms');
check(span('<p>a&amp;b pair stays one word</p>','a b')===null,'Ampersand is not whitespace for a plain space anchor');
check(span('<p>self-sustaining jar loops on</p>','self-sustaining')==='self-sustaining','Plain non-entity anchors resolve unchanged');
// 0.0.1: Apply All Links REST endpoint.
function wp_update_post($a){$GLOBALS['__last_update']=$a;return $a['ID'];}
function esc_url($u){return $u;}
function esc_attr($s){return $s;}
$r=KnowledgeSpark::api_apply(['post_id'=>999,'links'=>[]]);
check(is_wp_error($r),'Reject apply for an unknown post');
$r=KnowledgeSpark::api_apply(['post_id'=>1,'content'=>'<p>The loop wobbles badly here. Second sentence about fog.</p>','links'=>[
 ['title'=>'Target A','url'=>'https://example.com/existing','anchor'=>'loop wobbles'],
 ['title'=>'Target B','url'=>'https://example.com/existing','anchor'=>'no such phrase'],
 ['title'=>'Bad URL','url'=>'ftp://example.com/file','anchor'=>'Second sentence'],
]]);
check($r['applied']===1 && count($r['skipped'])===2,'Apply one valid link and report the rest');
$u=$GLOBALS['__last_update'];
check($u && strpos($u['post_content'],'<a href="https://example.com/existing">loop wobbles</a>')!==false,'Wrap the anchor in the saved content');
check(strpos($u['post_content'],'Second sentence about fog.</p>')!==false,'Leave untouched text intact');
$r=KnowledgeSpark::api_apply(['post_id'=>1,'content'=>'<script type="application/ld+json">{"headline":"Balance Light, Water &amp; Plants"}</script><p>Balance Light, Water &amp; Plants is about three things.</p>','links'=>[['title'=>'W & P target','url'=>'https://example.com/existing','anchor'=>'Light, Water & Plants']]]);
check($r['applied']===1 && strpos($GLOBALS['__last_update']['post_content'],'<a href="https://example.com/existing">Light, Water &amp; Plants</a>')!==false,'REST apply wraps the entity anchor and preserves the raw &amp; slice');
check(strpos($GLOBALS['__last_update']['post_content'],'{"headline":"Balance Light, Water &amp; Plants"}')!==false,'REST apply leaves the JSON-LD headline byte-identical');
$r=KnowledgeSpark::api_apply(['post_id'=>1,'content'=>'<p>fogs <strong>hard</strong> all day here</p>','links'=>[['title'=>'T','url'=>'https://example.com/existing','anchor'=>'hard all day']]]);
check($r['applied']===0,'Refuse anchor that starts inside bold and ends outside');
$r=KnowledgeSpark::api_apply(['post_id'=>1,'content'=>'<p>fogs <strong>hard all day</strong> here</p>','links'=>[['title'=>'T','url'=>'https://example.com/existing','anchor'=>'hard all day']]]);
check($r['applied']===1 && strpos($GLOBALS['__last_update']['post_content'],'<strong><a href="https://example.com/existing">hard all day</a></strong>')!==false,'Wrap anchor fully inside bold');
unset($GLOBALS['__last_update']);
$r=KnowledgeSpark::api_apply(['post_id'=>1,'content'=>$doc,'links'=>[['title'=>'T','url'=>'https://example.com/existing','anchor'=>'clean loop cycles']]]);
$saved=$GLOBALS['__last_update']['post_content'];
check($r['applied']===1 && strpos($saved,'<a href="https://example.com/existing">clean loop cycles</a>')!==false,'Link the body copy of an anchor that also exists in script markup');
check(substr($saved,0,strpos($saved,'</script>')+8)===substr($doc,0,strpos($doc,'</script>')+8),'Keep script markup byte-identical when the body copy is linked');
unset($GLOBALS['__last_update']);
// 0.0.1 fix-apply-all-links-on-wp7: honest skip causes.
$skipdoc='<script>{"q":"script phrase only"}</script><p>see <a href="https://example.com/existing">the guide</a> again</p>';
$r=KnowledgeSpark::api_apply(['post_id'=>1,'content'=>$skipdoc,'links'=>[
 ['title'=>'Linked','url'=>'https://example.com/existing','anchor'=>'the guide'],
 ['title'=>'Gone','url'=>'https://example.com/existing','anchor'=>'fogs hard all day'],
 ['title'=>'Scripted','url'=>'https://example.com/existing','anchor'=>'script phrase only'],
]]);
check($r['applied']===0 && count($r['skipped'])===3,'Report every skip with its actual cause');
check($r['skipped'][0]==='Linked: its anchor is already inside a link, so it was left untouched.','Cause: already inside a link');
check($r['skipped'][1]==='Gone: its anchor is not in the current draft.','Cause: not in the current draft');
check($r['skipped'][2]==='Scripted: its anchor is only inside script, style, or comment markup, so it was left untouched.','Cause: protected markup only');
unset($GLOBALS['__last_update']);
// 0.0.2: automatic retry for transient Qdrant TLS/network glitches.
$GLOBALS['ks_backoff_scale']=0;
$GLOBALS['settings']=array('url'=>'https://qdrant.example.com','key'=>'test');
$GLOBALS['settings']['ai_key']='';
$GLOBALS['flaky']=1;
$r=KnowledgeSpark::api_suggest(array('post_id'=>1,'content'=>'<p>This is a sufficiently long article about setting up a website with useful instructions.</p>','count'=>3));
check(!is_wp_error($r) && isset($r['suggestions']),'Retry transient Qdrant TLS error and recover');
$GLOBALS['flaky']=9;
$r=KnowledgeSpark::api_suggest(array('post_id'=>1,'content'=>'<p>This is a sufficiently long article about setting up a website with useful instructions.</p>','count'=>3));
check(is_wp_error($r) && $r->get_error_code()==='ks_qdrant_tls','Report a persistent TLS mismatch with guidance');
unset($GLOBALS['flaky'],$GLOBALS['ks_backoff_scale']);
// AI format resilience: one automatic retry when the answer is truncated, then succeed.
unset($GLOBALS['index_test']);
$GLOBALS['settings']['ai_key']='test';
$GLOBALS['ks_format_fail']=1;
$r=KnowledgeSpark::api_suggest(array('post_id'=>1,'content'=>'<p>This is a sufficiently long article about setting up a website with useful instructions.</p>','count'=>3));
check(!is_wp_error($r) && strpos($r['message'],'AI-reviewed')!==false,'Retry the AI call once when it returns a truncated answer');
// When the model keeps producing an unparseable answer, expose the diagnostics in the fallback message.
$GLOBALS['ks_format_fail']=99;
$r=KnowledgeSpark::api_suggest(array('post_id'=>1,'content'=>'<p>This is a sufficiently long article about setting up a website with useful instructions.</p>','count'=>3));
check(!is_wp_error($r) && strpos($r['message'],'AI returned an invalid recommendation format')!==false && strpos($r['message'],'completion tokens')!==false,'Surface AI format failure with finish/token diagnostics');
unset($GLOBALS['ks_format_fail']);
// 0.0.2 add-agent-interlink-tools: agent abilities registration.
$GLOBALS['ks_abilities']=[]; $GLOBALS['ks_categories']=[];
KnowledgeSpark::abilities();
KnowledgeSpark::ability_category();
check($GLOBALS['ks_abilities']===[] && $GLOBALS['ks_categories']===[],'No ability registration when the Abilities API is absent');
if(true){ function wp_register_ability($name,$args){ $GLOBALS['ks_abilities'][$name]=$args; return true; } function wp_register_ability_category($slug,$args){ $GLOBALS['ks_categories'][$slug]=$args; return true; } }
KnowledgeSpark::ability_category();
KnowledgeSpark::abilities();
check(count($GLOBALS['ks_categories'])===1 && isset($GLOBALS['ks_categories']['knowledgespark']),'Register the knowledgespark ability category');
check(count($GLOBALS['ks_abilities'])===4,'Register exactly four abilities');
foreach(['knowledgespark/find-suggestions','knowledgespark/apply-all-links','knowledgespark/interlink','knowledgespark/list-posts'] as $ability){ check(isset($GLOBALS['ks_abilities'][$ability]),'Register ability '.$ability); }
foreach($GLOBALS['ks_abilities'] as $ability=>$args){
 check($args['category']==='knowledgespark','Ability '.$ability.' uses the plugin category');
 check(!empty($args['meta']['public']),'Ability '.$ability.' is marked public for MCP exposure');
 check(is_array($args['input_schema']) && !empty($args['input_schema']['properties']),'Ability '.$ability.' declares an input schema');
 check(is_callable($args['execute_callback']) && is_callable($args['permission_callback']),'Ability '.$ability.' declares valid callbacks');
}
check(in_array('post_id',$GLOBALS['ks_abilities']['knowledgespark/find-suggestions']['input_schema']['required'],true) && in_array('post_id',$GLOBALS['ks_abilities']['knowledgespark/apply-all-links']['input_schema']['required'],true) && in_array('post_id',$GLOBALS['ks_abilities']['knowledgespark/interlink']['input_schema']['required'],true),'Post-targeted abilities require a post id');
check(!empty($GLOBALS['ks_abilities']['knowledgespark/find-suggestions']['meta']['annotations']['readonly']) && !empty($GLOBALS['ks_abilities']['knowledgespark/list-posts']['meta']['annotations']['readonly']),'Read-only abilities are annotated as read-only');
check(empty($GLOBALS['ks_abilities']['knowledgespark/apply-all-links']['meta']['annotations']['readonly']) && empty($GLOBALS['ks_abilities']['knowledgespark/interlink']['meta']['annotations']['readonly']),'Mutating abilities are annotated as not read-only');
// Permission callbacks mirror the REST route permissions.
function current_user_can($capability,$object_id=null){ if(isset($GLOBALS['ks_caps'][$capability])) { return (bool)$GLOBALS['ks_caps'][$capability]; } return true; }
$GLOBALS['ks_caps']=['edit_post'=>false];
check(KnowledgeSpark::ability_may_edit_post(['post_id'=>1])===false,'Permission callback refuses actors who cannot edit the post');
unset($GLOBALS['ks_caps']);
check(KnowledgeSpark::ability_may_edit_post(['post_id'=>1])===true && KnowledgeSpark::ability_may_edit_posts()===true,'Permission callbacks allow editors');
check(KnowledgeSpark::ability_may_edit_post(['post_id'=>0])===false,'Permission callback refuses a missing post id');
$GLOBALS['ks_caps']=['edit_posts'=>false];
check(KnowledgeSpark::ability_may_edit_posts()===false,'Permission callback refuses listing for non-editors');
unset($GLOBALS['ks_caps']);
// 0.0.2 add-agent-interlink-tools: find-suggestions parity and target listing.
$via_rest=KnowledgeSpark::api_suggest(['post_id'=>1,'count'=>3]);
$via_ability=KnowledgeSpark::ability_suggest(['post_id'=>1,'count'=>3]);
check(is_array($via_rest) && $via_ability===$via_rest,'Find-suggestions ability returns the same result as the REST route');
check(count($via_ability['suggestions'])===1 && $via_ability['suggestions'][0]['post_id']===2,'Find-suggestions keeps the suggestion fields and count limit');
class KS_WPDB {
 public $posts='wp_posts';
 public function prepare($q,...$args){ return vsprintf($q,$args); }
 public function get_col($q){
  if(!preg_match('/ID > (\d+) ORDER BY ID ASC LIMIT (\d+)$/',$q,$m)) { return []; }
  $cursor=(int)$m[1]; $limit=(int)$m[2]; $ids=[];
  foreach($GLOBALS['posts'] as $id=>$p){
   if($p->post_type!=='post' || $p->post_status!=='publish' || $p->post_password!=='') { continue; }
   if((int)$id<=$cursor) { continue; }
   $ids[]=(int)$id;
  }
  sort($ids,SORT_NUMERIC);
  return array_slice($ids,0,$limit);
 }
}
$GLOBALS['wpdb']=new KS_WPDB();
$GLOBALS['posts'][6]=(object)['post_type'=>'post','post_status'=>'publish','post_password'=>'','post_title'=>'Post 6','post_content'=>'Sixth fixture body for link targets.','post_modified_gmt'=>'2026-11-21 00:00:00'];
$r=KnowledgeSpark::ability_list_posts(['cursor'=>0,'limit'=>50]);
check(array_column($r['posts'],'post_id')===[1,2,3,6],'List published posts in ascending ID order');
check(!in_array(4,array_column($r['posts'],'post_id'),true) && !in_array(5,array_column($r['posts'],'post_id'),true),'Listing excludes draft and password-protected posts');
check($r['done']===true && $r['cursor']===6,'A complete listing reports done with the paging cursor');
$one=KnowledgeSpark::ability_list_posts(['cursor'=>0,'limit'=>2]);
$two=KnowledgeSpark::ability_list_posts(['cursor'=>$one['cursor'],'limit'=>2]);
check(array_column($one['posts'],'post_id')===[1,2] && array_column($two['posts'],'post_id')===[3,6] && $two['done']===true,'Cursor paging returns every post once with no gaps or repeats');
$entry=null; foreach($r['posts'] as $p){ if($p['post_id']===6){ $entry=$p; } }
check($entry!==null && $entry['title']==='Post 6' && $entry['url']===get_permalink(6) && $entry['modified']==='2026-11-21 00:00:00','List entries carry title, URL, and modified time');
// 0.0.2 add-agent-interlink-tools: apply-all-links parity and error surfacing.
$GLOBALS['__last_update']=null;
$via_rest=KnowledgeSpark::api_apply(['post_id'=>1,'links'=>[['title'=>'Target A','url'=>'https://example.com/existing','anchor'=>'Useful reference']]]);
$via_ability=KnowledgeSpark::ability_apply(['post_id'=>1,'links'=>[['title'=>'Target A','url'=>'https://example.com/existing','anchor'=>'Useful reference']]]);
check($via_ability===$via_rest && $via_ability['applied']===1,'Apply-all-links ability matches the REST route and inserts the link');
check(strpos($GLOBALS['__last_update']['post_content'],'<a href="https://example.com/existing">Useful reference</a>')!==false,'Ability apply saves the wrapped anchor to the post');
check(is_wp_error(KnowledgeSpark::ability_apply(['post_id'=>999,'links'=>[]])),'Ability apply surfaces engine errors instead of silent success');
// 0.0.2 add-agent-interlink-tools: one-shot interlink mirrors the button pair.
$original_content=$GLOBALS['posts'][1]->post_content;
$GLOBALS['posts'][1]->post_content='<p>This is a sufficiently long article about setting up a website with useful instructions.</p><a href="https://example.com/legacy">Legacy guide text</a>';
$GLOBALS['ks_points_ids']=[1,2,3,6];
$ks_suggestions=[
 ['post_id'=>2,'reason'=>'Explains the setup','anchor'=>'sufficiently long article','quote'=>'a sufficiently long article about'],
 ['post_id'=>3,'reason'=>'Related guide','anchor'=>'Legacy guide text','quote'=>'Legacy guide text'],
 ['post_id'=>6,'reason'=>'No natural spot','anchor'=>'','quote'=>''],
];
$GLOBALS['ks_chat_body']=wp_json_encode(['choices'=>[['message'=>['content'=>wp_json_encode(['suggestions'=>$ks_suggestions])]]]]);
$GLOBALS['__last_update']=null;
$r=KnowledgeSpark::ability_interlink(['post_id'=>1]);
check($r['suggestions']===3 && $r['applicable']===2,'Interlink filters suggestions to those with an anchor and a valid URL');
check($r['applied']===1,'Interlink inserts the placeable link and saves the post');
check(isset($r['skipped'][0]) && $r['skipped'][0]==='Post 6: it has no anchor to insert.','Interlink reports the anchorless suggestion');
check(isset($r['skipped'][1]) && $r['skipped'][1]==='Post 3: its anchor is already inside a link, so it was left untouched.','Interlink surfaces the engine skip cause for the in-link anchor');
check(strpos($GLOBALS['__last_update']['post_content'],'<a href="https://example.com/?p=2">sufficiently long article</a>')!==false,'Interlink saved content wraps the anchor in the suggested target');
$ks_suggestions=[
 ['post_id'=>2,'reason'=>'No spot','anchor'=>'','quote'=>''],
 ['post_id'=>3,'reason'=>'No spot either','anchor'=>'','quote'=>''],
];
$GLOBALS['ks_chat_body']=wp_json_encode(['choices'=>[['message'=>['content'=>wp_json_encode(['suggestions'=>$ks_suggestions])]]]]);
$GLOBALS['__last_update']=null;
$r=KnowledgeSpark::ability_interlink(['post_id'=>1]);
check($r['applied']===0 && $r['applicable']===0 && strpos($r['message'],'No suggestions were applicable')!==false,'Interlink applies nothing when no suggestion is applicable');
check($GLOBALS['__last_update']===null,'Interlink saves nothing when no suggestion is applicable');
unset($GLOBALS['ks_chat_body'],$GLOBALS['ks_points_ids']);
$GLOBALS['posts'][1]->post_content=$original_content;
// 0.0.2 add-agent-interlink-tools: application password opt-in.
$GLOBALS['settings']=['url'=>'https://qdrant.example.com','key'=>'test','agent_access'=>false];
check(KnowledgeSpark::app_passwords_available(false)===false,'Application Passwords stay unavailable when the opt-in is off');
check(KnowledgeSpark::app_passwords_available(true)===true,'Core availability on HTTPS sites is untouched by the opt-in');
function settings_fields($group){} function submit_button(){ } function esc_html($text){return $text;}
ob_start(); KnowledgeSpark::page(); $page=ob_get_clean();
check(strpos($page,'Allow Application Password access')!==false,'Settings page renders the agent-access row');
check(strpos($page,'credentials travel unencrypted')!==false,'Settings page warns about plain-HTTP use');
check(strpos($page,'name="knowledgespark_settings[agent_access]"')!==false,'The agent-access checkbox posts to the plugin settings');
check(strpos($page,' checked')===false,'The checkbox is unchecked while the opt-in is off');
$GLOBALS['settings']['agent_access']=true;
check(KnowledgeSpark::app_passwords_available(false)===true,'The opt-in enables Application Passwords on plain-HTTP sites');
ob_start(); KnowledgeSpark::page(); $page=ob_get_clean();
check(strpos($page,' checked>')!==false,'The checkbox reflects the enabled opt-in');
$GLOBALS['settings']['agent_access']=false;
// 0.0.3 background-batch-indexing: batch embedding (100 posts per request pair).
function ks_fp($id){ $s=KnowledgeSpark::settings(); $p=$GLOBALS['posts'][$id]; return hash('sha256',wp_json_encode([KnowledgeSpark::profile($p),$s['embedding'],$s['dimensions'],get_permalink($id),$p->post_modified_gmt])); }
function ks_batch_mode($ids){
 foreach(array_keys($GLOBALS['posts']) as $pid){ if((int)$pid>9) { unset($GLOBALS['posts'][$pid]); } }
 foreach($ids as $pid){ $GLOBALS['posts'][(int)$pid]=(object)['post_type'=>'post','post_status'=>'publish','post_password'=>'','post_title'=>'Post '.$pid,'post_content'=>'Long enough useful body text about terrarium care and setup steps.','post_excerpt'=>'','post_modified_gmt'=>'2026-11-21 10:00:00']; }
 $GLOBALS['ks_batch']=true; $GLOBALS['ks_calls']=[]; $GLOBALS['ks_stored']=[]; $GLOBALS['ks_get_ids']=null; $GLOBALS['ks_put_ids']=null; $GLOBALS['ks_fail_get']=false; $GLOBALS['ks_fail_put']=false; $GLOBALS['ks_delete_during_get']=false; $GLOBALS['ks_point_deletes']=[]; $GLOBALS['ks_meta']=[]; $GLOBALS['ks_reset']=false;
}
function ks_run_job($cursor=0){ $GLOBALS['options']=['knowledgespark_index_job'=>['status'=>'running','cursor'=>$cursor,'indexed'=>0,'skipped'=>0,'failed'=>0,'errors'=>[],'started'=>1,'updated'=>1]]; $GLOBALS['ks_cron']=[]; KnowledgeSpark::index_batch(); return $GLOBALS['options']['knowledgespark_index_job']; }
$ks_low=[]; foreach([1,2,3,6] as $pid){ if(isset($GLOBALS['posts'][$pid])) { $ks_low[$pid]=$GLOBALS['posts'][$pid]; unset($GLOBALS['posts'][$pid]); } }
ks_batch_mode(range(1001,1100));
$job=ks_run_job(0);
check($job['status']==='done' && $job['indexed']===100,'Index a full batch of 100 posts in one run');
check(count($GLOBALS['ks_calls'])===2 && count($GLOBALS['ks_get_ids'])===100,'A 100-post batch costs one retrieve and one upsert');
check(count($GLOBALS['ks_put_ids'])===100,'The upsert carries all 100 points with inference objects');
check($job['cursor']===1100 && !isset($GLOBALS['ks_cron']['knowledgespark_index_batch']),'The batch cursor advances and a completed run clears its schedule');
ks_batch_mode([11,12]);
$GLOBALS['ks_stored']=[11=>['fingerprint'=>ks_fp(11)]];
$job=ks_run_job(6);
check($job['skipped']===1 && $job['indexed']===1,'Batch skips current fingerprints and embeds only stale points');
check($GLOBALS['ks_put_ids']===[12],'Only the stale point joins the upsert');
ks_batch_mode([11,12]);
$GLOBALS['ks_stored']=[11=>['fingerprint'=>ks_fp(11)],12=>['fingerprint'=>ks_fp(12)]];
$job=ks_run_job(6);
check($job['skipped']===2 && $job['indexed']===0,'A fully current batch re-embeds nothing');
check(count($GLOBALS['ks_calls'])===1,'A fully current batch only reads the payloads');
ks_batch_mode([11]);
$GLOBALS['ks_stored']=[11=>['modified'=>'2026-11-21 10:00:00','url'=>get_permalink(11)]];
$job=ks_run_job(6);
check($job['skipped']===1 && count($GLOBALS['ks_calls'])===1,'Legacy points without fingerprints are preserved');
ks_batch_mode([11,12,13]);
$GLOBALS['ks_delete_during_get']=12;
$job=ks_run_job(6);
check($job['indexed']===2 && $job['failed']===0,'A post deleted mid-batch is not counted');
check($GLOBALS['ks_put_ids']===[11,13],'A post deleted mid-batch never joins the upsert');
check(in_array(12,$GLOBALS['ks_point_deletes'],false),'Its point is removed from the collection');
ks_batch_mode([11,12]);
$GLOBALS['ks_fail_get']=true;
$job=ks_run_job(6);
check($job['failed']===2 && $job['indexed']===0,'Retrieve failure fails the batch honestly');
check(count($job['errors'])===1 && strpos($job['errors'][0],'Posts 11-12:')===0,'The failure names the batch ID range');
check(isset($GLOBALS['ks_meta'][11]['_ks_sync_error']) && isset($GLOBALS['ks_meta'][12]['_ks_sync_error']),'Failed posts carry the sync error meta');
check($job['cursor']===12,'A failed batch still advances the cursor');
ks_batch_mode([11,12]);
$GLOBALS['ks_stored']=[11=>['fingerprint'=>ks_fp(11)]];
$GLOBALS['ks_fail_put']=true;
$job=ks_run_job(6);
check($job['skipped']===1 && $job['failed']===1,'Upsert failure fails only the attempted points');
check(strpos($job['errors'][0],'Posts 12-12:')===0,'The upsert failure names only the attempted points');
check(!isset($GLOBALS['ks_meta'][11]['_ks_sync_error']) && isset($GLOBALS['ks_meta'][12]['_ks_sync_error']),'Meta is cleared for skipped posts and set for failed ones');
foreach([1,2,3,6] as $pid){ if(isset($GLOBALS['posts'][$pid])) { $ks_low[$pid]=$GLOBALS['posts'][$pid]; unset($GLOBALS['posts'][$pid]); } }
ks_batch_mode(range(1001,1250));
$job=ks_run_job(0);
check($job['status']==='done' && $job['indexed']===250,'One cron run loops batches within its time budget');
check(count($GLOBALS['ks_calls'])===6,'250 posts cost six Qdrant requests in total');
ks_batch_mode(range(1001,1250));
$GLOBALS['ks_fail_put']=true;
$job=ks_run_job(0);
check($job['status']==='done' && $job['failed']===250 && $job['indexed']===0,'Failed batches are recorded without stalling the run');
check(count($job['errors'])===3,'Each failed batch contributes one error entry');
foreach($ks_low as $pid=>$p){ $GLOBALS['posts'][$pid]=$p; }
ks_batch_mode([11]);
$GLOBALS['flaky']=1; $GLOBALS['ks_backoff_scale']=0;
$job=ks_run_job(6);
unset($GLOBALS['flaky'],$GLOBALS['ks_backoff_scale']);
check($job['indexed']===1 && $job['failed']===0,'Transient Qdrant TLS errors are retried and the batch succeeds');
check(count($GLOBALS['ks_calls'])===2,'The retry recovers with no duplicate batch work');
ks_batch_mode([11]);
$GLOBALS['flaky']=9; $GLOBALS['ks_backoff_scale']=0;
$job=ks_run_job(6);
unset($GLOBALS['flaky'],$GLOBALS['ks_backoff_scale']);
check($job['failed']===1 && strpos($job['errors'][0],'mismatched TLS certificate')!==false,'Persistent TLS failures surface the retry-later guidance');
// 0.0.3 background-batch-indexing: background job lifecycle.
$GLOBALS['options']=[]; $GLOBALS['ks_cron']=[]; $GLOBALS['ks_spawns']=0; $GLOBALS['ks_calls']=[];
$job=KnowledgeSpark::api_index();
check($job['status']==='running','Start returns the running job immediately');
check($GLOBALS['options']['knowledgespark_index_job']['status']==='running' && $GLOBALS['options']['knowledgespark_index_job']['indexed']===0,'Start persists a fresh job state');
check(isset($GLOBALS['ks_cron']['knowledgespark_index_batch']),'Start schedules the batch event');
check($GLOBALS['ks_spawns']===1,'Start kicks the cron loop');
check(count($GLOBALS['ks_calls'])===1 && $GLOBALS['ks_calls'][0]==='GET','Start only validates the collection without embedding inline');
$started=$job['started'];
$job=KnowledgeSpark::api_index();
check($job['started']===$started && count($GLOBALS['ks_calls'])===1,'Starting while running does not start a second job');
$GLOBALS['options']['knowledgespark_index_job']=['status'=>'done','cursor'=>999,'indexed'=>500,'skipped'=>41,'failed'=>0,'errors'=>[],'started'=>1,'updated'=>1];
$GLOBALS['ks_cron']=[]; $GLOBALS['ks_spawns']=0; $GLOBALS['ks_calls']=[];
$job=KnowledgeSpark::api_index();
check($job['status']==='running' && $job['indexed']===0 && $job['cursor']===0,'Starting after completion begins a fresh pass');
check($GLOBALS['ks_spawns']===1,'The fresh pass schedules a new batch event');
$GLOBALS['options']=[];
$job=KnowledgeSpark::api_status();
check($job===['status'=>'idle','cursor'=>0,'indexed'=>0,'skipped'=>0,'failed'=>0,'errors'=>[]],'Status reports idle before any run');
$GLOBALS['options']=['knowledgespark_index_job'=>['status'=>'running','cursor'=>12,'indexed'=>2,'skipped'=>1,'failed'=>0,'errors'=>[],'started'=>1,'updated'=>1]];
$GLOBALS['ks_cron']=['knowledgespark_index_batch'=>12345]; $GLOBALS['ks_calls']=[];
$job=KnowledgeSpark::api_status();
check($job['status']==='running' && $job['cursor']===12 && count($GLOBALS['ks_calls'])===0,'Status exposes the persisted state without Qdrant calls');
$job=KnowledgeSpark::api_stop();
check($job['status']==='stopped' && !isset($GLOBALS['ks_cron']['knowledgespark_index_batch']),'Stop halts the job and clears the schedule');
KnowledgeSpark::index_batch();
check($GLOBALS['options']['knowledgespark_index_job']['status']==='stopped' && count($GLOBALS['ks_calls'])===0 && !isset($GLOBALS['ks_cron']['knowledgespark_index_batch']),'The runner honors a stopped job');
$GLOBALS['options']=['knowledgespark_index_job'=>['status'=>'running','cursor'=>12,'indexed'=>2,'skipped'=>1,'failed'=>0,'errors'=>[],'started'=>1,'updated'=>1]];
$GLOBALS['ks_cron']=['knowledgespark_index_batch'=>999]; $GLOBALS['ks_calls']=[];
$r=KnowledgeSpark::api_reset();
check(!is_wp_error($r) && $GLOBALS['options']['knowledgespark_index_job']['status']==='stopped' && !isset($GLOBALS['ks_cron']['knowledgespark_index_batch']),'Reset stops a running job before deleting the collection');
check($GLOBALS['ks_reset']===true && count($GLOBALS['ks_calls'])===1,'Reset deletes the collection');
unset($GLOBALS['ks_batch']);
// 0.0.3 background-batch-indexing: settings page copy.
ob_start(); KnowledgeSpark::page(); $page=ob_get_clean();
check(strpos($page,'Indexing runs on the server')!==false,'Settings page describes background indexing');
check(strpos($page,'Keep this page open')===false,'The keep-page-open warning is removed');
