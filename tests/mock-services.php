<?php
// Test-only service isolation; excluded from the installable ZIP.
if (getenv('VISITAS_TESTING')!=='1') { return; }
$register=static function(string $hook,callable $callback,int $args): void {
    if (function_exists('add_filter')) { add_filter($hook,$callback,999,$args); }
    else { $GLOBALS['wp_filter'][$hook][999][]=['function'=>$callback,'accepted_args'=>$args]; }
};
$register('pre_wp_mail',static fn()=>true,1);
$register('pre_http_request',static function($result,$args,$url) {
    $fixture = getenv('VISITAS_UPDATE_FIXTURE');
    if ($fixture && is_file($fixture)) {
        $release = json_decode(file_get_contents($fixture), true);
        if ($url === 'https://raw.githubusercontent.com/paulommaeda/visitas/main/dist/update.json') {
            return ['headers'=>[],'response'=>['code'=>200],'body'=>wp_json_encode($release),'cookies'=>[]];
        }
        if ($url === ($release['download_url'] ?? '') && !empty($args['stream']) && is_file(getenv('VISITAS_TEST_PACKAGE') ?: '')) {
            copy(getenv('VISITAS_TEST_PACKAGE'), $args['filename']);
            return ['headers'=>[],'response'=>['code'=>200],'body'=>'','cookies'=>[]];
        }
        if (str_contains($url, 'api.wordpress.org/plugins/update-check/')) {
            return ['headers'=>[],'response'=>['code'=>200],'body'=>wp_json_encode(['plugins'=>[],'no_update'=>[],'translations'=>[]]),'cookies'=>[]];
        }
    }
    if (str_starts_with($url,'https://api.sms-gate.app/')) {
        $post=($args['method']??'GET')==='POST';
        $payload=$post?json_decode($args['body'],true):[];
        $authorization=(string)($args['headers']['Authorization']??'');
        $denied=$post && str_starts_with((string)base64_decode(substr($authorization,6)),'qa-denied-user:');
        return ['headers'=>[],'response'=>['code'=>$denied?401:($post?202:200),'message'=>'Test response'],
            'body'=>wp_json_encode(['id'=>$post?$payload['id']:basename($url),'state'=>$post?'Pending':'Delivered']),'cookies'=>[]];
    }
    return new WP_Error('visitas_test_http_blocked','External HTTP is blocked during tests.');
},3);
