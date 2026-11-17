<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../server/core.php';
require __DIR__ . '/../server/delivery.php';

$checks = 0;
function check(bool $ok, string $name): void
{
    global $checks;
    if (!$ok) { throw new RuntimeException($name); }
    $checks++;
}
function rejected(callable $fn, int $status): void
{
    try { $fn(); } catch (Caplock\HttpError $e) { check($e->status === $status, 'HTTP status'); return; }
    throw new RuntimeException('Invalid input accepted');
}
$tmp = sys_get_temp_dir() . '/caplock-review-' . bin2hex(random_bytes(8));
mkdir($tmp, 0700);
$root = realpath($tmp);
try {
    mkdir($tmp . '/public', 0700);
    copy(__DIR__ . '/../consent-2026-11-17.html', $tmp . '/public/consent-2026-11-17.html');
    mkdir($tmp . '/queue', 0700);
    $c = require __DIR__ . '/../server/config.example.php';
    $c['storage'] = $tmp . '/queue';
    $c['document_root'] = $tmp . '/public';
    $c['rate_key'] = str_repeat('test-only-', 8);
    $c['consent_text'] = Caplock\CONSENT_LABEL;
    $document = Caplock\consentDocument($c);
    check($document['version']==='2026-11-17-v1', 'Consent version');
    check($document['document_sha256']===hash_file('sha256',$tmp.'/public/consent-2026-11-17.html'), 'Consent document hash');
    $confirmation=['consent'=>'1','consent_version'=>$document['version'],'consent_sha256'=>$document['document_sha256']];
    Caplock\verifyConsent($confirmation,$document);
    check(true, 'Consent confirmation accepted');
    rejected(fn()=>Caplock\verifyConsent(array_replace($confirmation,['consent'=>'']),$document),422);
    rejected(fn()=>Caplock\verifyConsent(array_replace($confirmation,['consent_version'=>'old']),$document),422);
    rejected(fn()=>Caplock\verifyConsent(array_replace($confirmation,['consent_sha256'=>str_repeat('0',64)]),$document),422);
    $p = ['phone'=>'+7 (999) 123-45-67','city'=>'Test city','email'=>'test@example.invalid',
        'mode'=>'project','page'=>'https://caplock.dayagroup.ru/','details'=>'<script>bad()</script>[url]'];
    $d = Caplock\validate($p, $c['origin']);
    check($d['phone'] === '+79991234567', 'Phone normalization');
    check(Caplock\validate(array_replace($p, ['email'=>'']), $c['origin'])['email'] === '', 'Optional email');
    foreach ([['phone'=>'invalid'],['email'=>'invalid'],['city'=>''],['details'=>str_repeat('a',3001)],
        ['mode'=>'unknown'],['height'=>'-1'],['area'=>'INF'],['page'=>'https://evil.invalid/'],
        ['project_url'=>'javascript:alert(1)'],['phone'=>['bad']]] as $bad) {
        rejected(fn()=>Caplock\validate(array_replace($p, $bad), $c['origin']), 422);
    }
    $server = ['REQUEST_METHOD'=>'POST','HTTP_ORIGIN'=>$c['origin'],'HTTP_SEC_FETCH_SITE'=>'same-origin'];
    $post = ['csrf'=>'test-csrf','consent'=>'1','website'=>''];
    Caplock\guard($server, $post, 'test-csrf', $c['origin']);
    check(true, 'Valid CSRF');
    rejected(fn()=>Caplock\guard(array_replace($server,['HTTP_ORIGIN'=>'https://evil.invalid']),$post,'test-csrf',$c['origin']),403);
    rejected(fn()=>Caplock\guard($server,array_replace($post,['csrf'=>'wrong']),'test-csrf',$c['origin']),403);
    rejected(fn()=>Caplock\guard($server,array_replace($post,['website'=>'spam']),'test-csrf',$c['origin']),422);
    rejected(fn()=>Caplock\guard($server,array_replace($post,['consent'=>'']),'test-csrf',$c['origin']),422);
    rejected(fn()=>Caplock\ready($c),503);
    check(Caplock\outside($tmp.'/queue', $tmp.'/public'), 'Private storage');
    check(!Caplock\outside($tmp.'/public/sub', $tmp.'/public'), 'Public storage rejected');
    $pdf = $tmp . '/test.pdf';
    file_put_contents($pdf, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
    check(Caplock\fileType('test.pdf',$pdf)==='pdf','PDF signature');
    rejected(fn()=>Caplock\fileType('../test.pdf',$pdf),422);
    rejected(fn()=>Caplock\fileType('test.php',$pdf),422);
    rejected(fn()=>Caplock\fileType('test.xlsx',$pdf),422);
    check(Caplock\uploads([])===[],'No files');
    rejected(fn()=>Caplock\uploads(['files'=>['name'=>['test.pdf'],'tmp_name'=>[$pdf],'error'=>[0],'size'=>[1]]]),422);
    for ($i=0;$i<10;$i++) { Caplock\rate($c,'192.0.2.1'); }
    rejected(fn()=>Caplock\rate($c,'192.0.2.1'),429);
    $id = str_repeat('a',32);
    $accepted = Caplock\enqueue($c,$id,$d,[]);
    check($accepted['status']==='received','Durable receipt');
    check(Caplock\enqueue($c,$id,$d,[])===$accepted,'Same ID deduplicated');
    rejected(fn()=>Caplock\enqueue($c,$id,array_replace($d,['city'=>'Changed']),[]),409);
    rejected(fn()=>Caplock\enqueue($c,'../bad',$d,[]),422);
    $c['crm']['files_field']='UF_CRM_TEST_FILES';
    $c['crm']['id_field']='UF_CRM_TEST_ID';
    $c['crm']['source_value']='TEST_ONLY';
    $job = Caplock\read($c['storage'].'/'.$id.'.json');
    check($job['consent']['document_html']===$document['document_html'],'Consent snapshot retained');
    check(!empty($job['consent']['confirmed_at']),'Consent timestamp retained');
    $fields = Caplock\leadFields($c,$job);
    check($fields['ASSIGNED_BY_ID']===32427,'Responsible mapping');
    check($fields['UF_CRM_1667293827361']==='Test city','Dadata mapping');
    check($fields['EMAIL'][0]['VALUE']==='test@example.invalid','Email mapping');
    check(!str_contains($fields['COMMENTS'],'<script>'),'HTML escaped');
    check(str_contains($fields['COMMENTS'],'Согласие: 2026-11-17-v1'),'Consent proof in CRM');
    check(str_contains(Caplock\comment($job,null,[],false),'Копия текста согласия:'),'Consent snapshot in email');
    $relative = '/bitrix/components/bitrix/crm.lead.show/show_file.php?ownerId=5&fieldName=UF_CRM_TEST_FILES&dynamic=Y&fileId=42';
    check(Caplock\safeFileUrl($relative,$c,5)===$c['crm']['portal'].$relative,'CRM file link and port');
    foreach (['https://evil.invalid'.$relative,'//evil.invalid'.$relative,$relative.'&auth=SECRET',str_replace('ownerId=5','ownerId=6',$relative)] as $url) {
        check(Caplock\safeFileUrl($url,$c,5)===null,'Unsafe link rejected');
    }
    $path = $c['storage'].'/'.$id.'.json';
    $calls = [];
    $mock = function ($c,$channel,$job) use (&$calls) {
        $calls[]=$channel;
        if ($channel==='email') { throw new Caplock\DeliveryError('retry'); }
        return $channel==='crm' ? 5 : true;
    };
    Caplock\processJob($c,$path,$mock);
    $j=Caplock\read($path);
    check($j['crm']['status']==='sent' && $j['email']['status']==='retry','Independent channels');
    check($j['crm_comment']['status']==='sent','Comment channel');
    Caplock\processJob($c,$path,$mock);
    check(count($calls)===3,'No duplicate delivery or premature retry');
    $j['email']['status']='sending'; Caplock\atomic($path,$j);
    Caplock\processJob($c,$path,$mock);
    check(Caplock\read($path)['email']['status']==='uncertain','Unknown outcome not blindly retried');
    $job['crm']['result']=5;
    $job['files']=[['name'=>'test.pdf']];
    $written='';
    $api=function ($c,$method,$payload) use ($relative,&$written) {
        if($method==='crm.lead.get')return ['COMMENTS'=>'Manager note','UF_CRM_TEST_FILES'=>[['showUrl'=>$relative]]];
        if($method==='crm.lead.update'){$written=$payload['fields']['COMMENTS'];return true;}
        throw new RuntimeException('Unexpected API call');
    };
    Caplock\deliver($c,'crm_comment',$job,$api);
    check(str_starts_with($written,'Manager note'),'Existing comment preserved');
    check(str_contains($written,'fileId=42'),'File link in comment');
    $job['created']=time()-8*86400; Caplock\atomic($path,$job);
    Caplock\cleanup($c);
    $expired=Caplock\read($path);
    check(($expired['expired']??false) && !isset($expired['data']),'Personal data retention');
    rejected(fn()=>Caplock\enqueue($c,$id,$d,[]),410);
    echo 'Passed ' . $checks . " offline server checks; no network calls.\n";
} finally {
    // Удаляем только созданный этим тестом каталог, после проверки абсолютных путей.
    if ($root && str_starts_with($root, realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR)) {
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $f){$path=$f->getPathname();$resolved=realpath($path);if(!$resolved||!str_starts_with($resolved,$root.DIRECTORY_SEPARATOR)||$f->isLink())continue;$f->isDir()?rmdir($path):unlink($path);}
        rmdir($root);
    }
}
