<?php
// Standalone behavioral checks; no WordPress installation or external services needed.
define('ABSPATH', __DIR__);
class WP_Error { private $code; private $message; private $data; public function __construct($code,$message,$data=null){$this->code=$code;$this->message=$message;$this->data=$data;} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} public function get_error_data(){return $this->data;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_parse_args($a,$b){return array_merge($b,$a);}
function get_option($k,$d=[]){return $GLOBALS['settings'] ?? [];}
function home_url($p=''){return 'https://example.com'.$p;}
function get_post($id){return $GLOBALS['posts'][$id] ?? null;}
function is_post_publicly_viewable($p){return $p->post_status==='publish';}
function wp_strip_all_tags($s){return strip_tags($s);}
function strip_shortcodes($s){return $s;}
function absint($v){return abs((int)$v);}
function get_permalink($id){return 'https://example.com/?p='.$id;}
function url_to_postid($url){return strpos($url,'existing')!==false?3:0;}
function wp_parse_url($url,$component=-1){return parse_url($url,$component);}
function wp_json_encode($v){return json_encode($v);}
function sanitize_text_field($s){return trim(strip_tags($s));}
function wp_remote_retrieve_response_code($r){return $r['status'];}
function wp_remote_retrieve_body($r){return $r['body'];}
function delete_post_meta($id,$key){}
function update_post_meta($id,$key,$value){}
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
if(strpos($url,'chat/completions')!==false){ if(!empty($GLOBALS['ks_format_fail'])) { $GLOBALS['ks_format_fail']--; return ['status'=>200,'body'=>"{\"choices\":[{\"finish_reason\":\"length\",\"message\":{\"content\":\"{\\\"suggestions\\\":[{\\\"post_id\\\":2,\\\"reason\\\":\\\"truncat\"}}]}"]; } return ['status'=>200,'body'=>"{\"choices\":[{\"message\":{\"content\":\"{\\\"suggestions\\\":[{\\\"post_id\\\":2,\\\"reason\\\":\\\"Helps explain setup\\\",\\\"anchor\\\":\\\"invented phrase\\\",\\\"quote\\\":\\\"invented sentence\\\"},{\\\"post_id\\\":999,\\\"reason\\\":\\\"invented target\\\"}]}\"}}]}"]; }
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