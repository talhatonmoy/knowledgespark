<?php
// Patch the smoke harness: add a ks_format_fail hook to the chat/completions mock and append AI-resilience tests.
$file = dirname(__DIR__) . '/tests/smoke.php';
$lines = file($file);

// Build correct-by-construction JSON literals for the mock responses.
$validInner = json_encode(['suggestions' => [
    ['post_id' => 2, 'reason' => 'Helps explain setup', 'anchor' => 'invented phrase', 'quote' => 'invented sentence'],
    ['post_id' => 999, 'reason' => 'invented target'],
]]);
$validResp = json_encode(['choices' => [['message' => ['content' => $validInner]]]]);
$badResp = json_encode(['choices' => [['finish_reason' => 'length', 'message' => ['content' => '{"suggestions":[{"post_id":2,"reason":"truncat']]]]);
$validLit = json_encode($validResp); // a valid PHP string literal
$badLit = json_encode($badResp);

$newChatLine = "if(strpos(\$url,'chat/completions')!==false){ if(!empty(\$GLOBALS['ks_format_fail'])) { \$GLOBALS['ks_format_fail']--; return ['status'=>200,'body'=>". $badLit ."]; } return ['status'=>200,'body'=>". $validLit ."]; }";

// Replace the old chat/completions mock line.
$replaced = false;
foreach ($lines as $i => $ln) {
    if (strpos($ln, "if(strpos(\$url,'chat/completions')!==false){return") !== false) {
        $lines[$i] = $newChatLine . "\n";
        $replaced = true;
        break;
    }
}
if (!$replaced) { fwrite(STDERR, "chat mock line not found\n"); exit(1); }

// Append the AI resilience tests.
$tests = <<<'PHP'
// AI format resilience: one automatic retry when the answer is truncated, then succeed.
$GLOBALS['ks_format_fail']=1;
$r=KnowledgeSpark::api_suggest(array('post_id'=>1,'content'=>'<p>This is a sufficiently long article about setting up a website with useful instructions.</p>','count'=>3));
check(!is_wp_error($r) && strpos($r['message'],'AI-reviewed')!==false,'Retry the AI call once when it returns a truncated answer');
// When the model keeps producing an unparseable answer, expose the diagnostics in the fallback message.
$GLOBALS['ks_format_fail']=99;
$r=KnowledgeSpark::api_suggest(array('post_id'=>1,'content'=>'<p>This is a sufficiently long article about setting up a website with useful instructions.</p>','count'=>3));
check(!is_wp_error($r) && strpos($r['message'],'AI returned an invalid recommendation format')!==false && strpos($r['message'],'completion tokens')!==false,'Surface AI format failure with finish/token diagnostics');
unset($GLOBALS['ks_format_fail']);
PHP;
$lines[] = $tests;

file_put_contents($file, implode('', $lines));
echo "chat mock patched, tests appended\n";
