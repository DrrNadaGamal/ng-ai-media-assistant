<?php
require '/wordpress/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php'; require_once ABSPATH.'wp-admin/includes/image.php'; require_once ABSPATH.'wp-admin/includes/file.php'; require_once ABSPATH.'wp-admin/includes/media.php'; require_once ABSPATH.'wp-admin/includes/template.php';
header('Content-Type: text/plain'); wp_set_current_user(1); activate_plugin('ng-ai-media-assistant/ng-ai-media-assistant.php');
$pass=0;$fail=0; $t=function($n,$c) use(&$pass,&$fail){ if($c){$pass++;echo "PASS $n\n";}else{$fail++;echo "FAIL $n\n";} };
$MOCK=isset($_GET['mock'])?$_GET['mock']:'http://127.0.0.1:9500';
add_filter('ngama_endpoint',function($u,$m) use($MOCK){ return $MOCK.'/v1beta/models/'.$m.':generateContent'; },10,2);
$mode=function($m) use($MOCK){ wp_remote_get($MOCK.'/__mode?m='.$m); }; $state=function() use($MOCK){ return json_decode(wp_remote_retrieve_body(wp_remote_get($MOCK.'/__state')),true); };
$set=function($o){ update_option(NGAMA::OPT,array_merge(NGAMA::defaults(),array('key_enc'=>NGAMA::enc('GOODKEY'),'on_upload'=>0),$o)); };
$mk=function($n){ $im=imagecreatetruecolor(1200,800); for($i=0;$i<100;$i++){imagefilledrectangle($im,rand(0,1200),rand(0,800),rand(0,1200),rand(0,800),imagecolorallocate($im,rand(0,255),rand(0,255),rand(0,255)));} $f=tempnam(sys_get_temp_dir(),'t').'.jpg'; imagejpeg($im,$f,85); return media_handle_sideload(array('name'=>$n,'tmp_name'=>$f),0); };
wp_remote_get($MOCK.'/__reset');
// key handling
$set(array()); $raw=get_option(NGAMA::OPT); $t('api key stored encrypted',strpos(json_encode($raw),'GOODKEY')===false&&NGAMA::key()==='GOODKEY');
$set(array('key_enc'=>'')); $id0=$mk('a0.jpg'); $r=NGAMA::generate($id0); $t('no key: clean error, no request',is_wp_error($r)&&$r->get_error_code()==='key'&&strpos(json_encode($state()['log']),'GEMINI')===false);
// generate
$set(array('lang'=>'ar','context'=>'dental clinic')); $id=$mk('a1.jpg'); $p=NGAMA::generate($id);
$t('generate returns alt+description',!is_wp_error($p)&&$p['alt']==='A red abstract pattern'&&!empty($p['description']));
$log=implode("\n",$state()['log']); $t('request sent with api key header',strpos($log,'key=GOODKEY')!==false);
$t('image sent as jpeg base64',strpos($log,'mime=image/jpeg')!==false&&preg_match('/b64len=(\d+)/',$log,$m)&&$m[1]>1000);
$t('prompt asks for Arabic and carries context',strpos($log,'Arabic')!==false);
$t('result is pending, not applied',$p['status']==='pending'&&get_post_meta($id,'_wp_attachment_image_alt',true)==='');
// review mode process
$r=NGAMA::process($id); $t('review mode: suggestion stored, alt untouched',get_post_meta($id,NGAMA::META,true)['status']==='pending'&&get_post_meta($id,'_wp_attachment_image_alt',true)==='');
$t('approve applies alt',NGAMA::apply($id)&&get_post_meta($id,'_wp_attachment_image_alt',true)==='A red abstract pattern'&&get_post_meta($id,NGAMA::META,true)['status']==='applied');
$t('approve fills empty description',get_post_field('post_content',$id)==='Colorful shapes on a plain background.');
// auto mode never overwrites
$set(array('mode'=>'auto')); $idA=$mk('a2.jpg'); NGAMA::process($idA); $t('auto: empty alt filled',get_post_meta($idA,'_wp_attachment_image_alt',true)==='A red abstract pattern');
$idB=$mk('a3.jpg'); update_post_meta($idB,'_wp_attachment_image_alt','Handwritten alt'); NGAMA::process($idB); $t('auto: existing alt NOT overwritten',get_post_meta($idB,'_wp_attachment_image_alt',true)==='Handwritten alt');
// on upload
$set(array('mode'=>'review','on_upload'=>1)); wp_remote_get($MOCK.'/__reset'); $idU=$mk('a4.jpg'); $t('on upload creates suggestion',(get_post_meta($idU,NGAMA::META,true)['status']??'')==='pending');
$set(array('on_upload'=>0)); $n=count($state()['log']); $idV=$mk('a5.jpg'); $t('on_upload off: no API call',count($state()['log'])===$n);
// failures
$set(array('key_enc'=>NGAMA::enc('WRONG'))); $idF=$mk('a6.jpg'); $r=NGAMA::process($idF); $t('wrong key: error stored, alt untouched',is_wp_error($r)&&get_post_meta($idF,NGAMA::META,true)['status']==='error'&&get_post_meta($idF,'_wp_attachment_image_alt',true)==='');
$set(array()); $mode('garbage'); $r=NGAMA::generate($idF); $t('garbage model answer rejected',is_wp_error($r)&&$r->get_error_code()==='parse');
$mode('fence'); $r=NGAMA::generate($idF); $t('json inside code fence parsed',!is_wp_error($r)&&strpos($r['alt'],'dentist')!==false);
$t('html in model answer stripped',!is_wp_error($r)&&strpos($r['alt'],'<b>')===false);
$mode('ok');
// non-image
$tx=tempnam(sys_get_temp_dir(),'x').'.txt'; file_put_contents($tx,'hi'); $x=media_handle_sideload(array('name'=>'x.txt','tmp_name'=>$tx),0); $t('non-image skipped',is_wp_error(NGAMA::generate($x)));
// missing list
wp_remote_get($MOCK.'/__reset'); $m=NGAMA::missing(); $t('missing() lists images without alt and without suggestion',in_array($idV,$m,true)&&!in_array($id,$m,true)&&!in_array($idB,$m,true));
// parse
$t('parse rejects non-string alt',NGAMA::parse('{"alt":{"x":1}}')===null); $t('parse caps length',mb_strlen(NGAMA::parse('{"alt":"'.str_repeat('a',500).'","description":"d"}')['alt'])<=200);
// permissions / admin
wp_set_current_user(0); ob_start(); NGAMA::page(); $o=ob_get_clean(); $t('page empty without capability',$o===''); wp_set_current_user(1);
foreach(array('en_US'=>'AI Media Assistant','ar'=>'مساعد الوسائط الذكي','fr_FR'=>'Assistant média IA','de_DE'=>'KI-Medienassistent') as $loc=>$w){ add_filter('ngama_locale',function()use($loc){return $loc;}); ob_start(); NGAMA::page(); $o=ob_get_clean(); $t("admin page $loc",strpos($o,$w)!==false); remove_all_filters('ngama_locale'); }
$t('key never printed in settings page',strpos($o,'GOODKEY')===false&&strpos($o,'WRONG')===false);
$_GET['msg']='<script>alert(1)</script>'; ob_start(); NGAMA::page(); $o=ob_get_clean(); $t('msg escaped',strpos($o,'<script>alert')===false);
update_post_meta($id,NGAMA::META,array('status'=>'pending','alt'=>'<img src=x onerror=1>','description'=>'<script>1</script>')); ob_start(); NGAMA::page(); $o=ob_get_clean(); $t('suggestions escaped in queue',strpos($o,'<img src=x')===false&&strpos($o,'<script>1')===false);
echo "\nTOTAL pass=$pass fail=$fail\n";
