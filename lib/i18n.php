<?php
declare(strict_types=1);
// Locale selection never depends on database availability (also works in installer).
function le_i18n_init(bool $promo=false): void {
    $GLOBALS['le_i18n_promo']=$promo;
    $path=(string)parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
    $admin=(bool)preg_match('~^/(admin|upgrade)(?:/|$)~',$path);
    $install=(bool)preg_match('~^/install(?:/|$)~',$path);
    $GLOBALS['le_i18n_scope']=$admin?'admin':'site';
    $cookie=$admin?'le_admin_lang':'le_site_lang';
    $default=(string)($GLOBALS['lg_config']['default_locale']??'pl');
    $locale=le_valid_locale($_COOKIE[$cookie]??null)??le_valid_locale($default)??'pl';
    $requested=($admin||$install)?le_valid_locale($_GET['lang']??null):null;
    if(!$admin&&!$install)$locale=le_valid_locale($default)??'pl';
    if($requested&&($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&(!$promo||!$admin)){
        $locale=$requested;
        $options=['expires'=>time()+31536000,'path'=>'/','secure'=>le_https(),'httponly'=>true,'samesite'=>'Lax'];
        if(!headers_sent())setcookie($cookie,$locale,$options);
        $_COOKIE[$cookie]=$locale;
        if($install){if(!headers_sent())setcookie('le_admin_lang',$locale,$options);$_COOKIE['le_admin_lang']=$locale;}
    }
    $GLOBALS['le_locale']=$promo&&$admin?'pl':$locale;
    if(!headers_sent()){header('Content-Language: '.le_locale());header('Vary: Cookie',false);}
}
function le_valid_locale(mixed $value): ?string {return is_string($value)&&in_array($value,['pl','en','de'],true)?$value:null;}
function le_locale(): string {return $GLOBALS['le_locale']??'pl';}
function le_catalog(string $locale): array {
    static $catalogs=[];
    if(!isset($catalogs[$locale])){
        $path=__DIR__.'/../lang/'.$locale.'.json';
        $data=is_file($path)?json_decode((string)file_get_contents($path),true):[];
        $catalogs[$locale]=is_array($data)?$data:[];
    }
    return $catalogs[$locale];
}
function __(string $key,array $params=[]): string {
    $locale=le_locale();$catalog=le_catalog($locale);$fallback=le_catalog('pl');
    $text=(string)($GLOBALS['le_plugin_lang'][$locale][$key]??$catalog[$key]??$GLOBALS['le_plugin_lang']['pl'][$key]??$fallback[$key]??$key);
    foreach($params as $name=>$value)$text=str_replace('{'.$name.'}',(string)$value,$text);
    return $text;
}
// Localize known product/default UI copy; user-written content is left unchanged.
function le_text(string $text): string {
    $trimmed=trim($text);$key='ui.'.substr(hash('sha256',$trimmed),0,16);
    if(!array_key_exists($key,le_catalog('pl')))return $text;
    $start=strlen($text)-strlen(ltrim($text));$end=strlen($text)-strlen(rtrim($text));
    return substr($text,0,$start).__($key).($end?substr($text,-$end):'');
}
function le_i18n_register(string $namespace,array $catalogs): void {
    if(!preg_match('/^[a-z][a-z0-9_-]{0,79}$/',$namespace))throw new InvalidArgumentException('Invalid translation namespace.');
    foreach($catalogs as $locale=>$strings){if(!le_valid_locale($locale)||!is_array($strings))continue;
        foreach($strings as $key=>$value)if(is_string($key)&&is_string($value))$GLOBALS['le_plugin_lang'][$locale]['plugin.'.$namespace.'.'.$key]=$value;
    }
}
function le_language_switcher(): void {
    $path=(string)parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
    if(!preg_match('~^/(admin|upgrade|install)(?:/|$)~',$path))return;
    if(!empty($GLOBALS['le_i18n_promo'])&&($GLOBALS['le_i18n_scope']??'site')==='admin')return;
    $uri=(string)($_SERVER['REQUEST_URI']??'/');$path=(string)parse_url($uri,PHP_URL_PATH);
    if(!str_starts_with($path,'/')||str_starts_with($path,'//')||str_contains($path,"\r")||str_contains($path,"\n"))$path='/';
    parse_str((string)parse_url($uri,PHP_URL_QUERY),$query);unset($query['lang']);
    echo '<nav class="le-language" aria-label="'.e(__('language.label')).'">';
    foreach(['pl'=>'PL','en'=>'EN','de'=>'DE'] as $locale=>$label){$query['lang']=$locale;$url=$path.'?'.http_build_query($query);echo '<a lang="'.$locale.'" hreflang="'.$locale.'" href="'.e($url).'"'.($locale===le_locale()?' aria-current="true"':'').'>'.$label.'</a>';}
    echo '</nav>';
}
function le_i18n_assets(): void {
    static $done=false;if($done)return;$done=true;
    $messages=[];foreach(le_catalog('pl') as $key=>$source)if(str_starts_with($key,'ui.'))$messages[$source]=__($key);
    echo '<style>.le-language{display:inline-flex;gap:3px;align-items:center;flex-wrap:wrap;margin:6px}.le-language a{font:600 12px system-ui;padding:5px 7px;border:1px solid #596273;border-radius:6px;color:inherit;text-decoration:none}.le-language a[aria-current]{background:#334155;color:#fff}@media(max-width:600px){.le-language a{padding:4px 5px}}</style>';
    echo '<script>window.leLocale='.json_encode(le_locale()).';window.leMessages='.json_encode($messages,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE).';window.leT=(text)=>{const key=text.trim();return window.leMessages[key]===undefined?text:text.slice(0,text.length-text.trimStart().length)+window.leMessages[key]+text.slice(text.trimEnd().length)};</script>';
}

function le_localized_e(mixed $text): string {return e(le_text((string)$text));}

function le_i18n_site_default(): void {
    if(($GLOBALS['le_i18n_scope']??'site')!=='site')return;
    try{$default=le_valid_locale(setting('site_language',(string)($GLOBALS['lg_config']['default_locale']??'pl')));if($default){$GLOBALS['le_locale']=$default;if(!headers_sent())header('Content-Language: '.$default);}}catch(Throwable $error){}
}
