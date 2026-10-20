<?php
declare(strict_types=1);
function engine_install_package_legacy(array $upload,string $type='plugin',bool $local=false,?array $expected=null): void {
    if(!class_exists('ZipArchive'))throw new RuntimeException(le_text('Brak ZipArchive.'));
    if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||(!$local&&!is_uploaded_file($upload['tmp_name'])))throw new RuntimeException(le_text('Wybierz ZIP pluginu.'));
    if(filesize($upload['tmp_name'])>25*1024*1024)throw new RuntimeException(le_text('ZIP pluginu jest za duży (maks. 25 MB).'));
    $zip=new ZipArchive();if($zip->open($upload['tmp_name'],ZipArchive::CHECKCONS)!==true)throw new RuntimeException(le_text('Nieprawidłowy ZIP.'));
    $dest=null;
    try{
        $manifest=null;$prefix='';$entries=[];$total=0;
        if($zip->numFiles>2000)throw new RuntimeException(le_text('Za dużo plików w pluginie.'));
        for($i=0;$i<$zip->numFiles;$i++){
            $n=(string)$zip->getNameIndex($i);
            if(str_contains($n,"\0")||str_contains($n,'\\')||str_starts_with($n,'/')||str_contains($n,':')||preg_match('~(^|/)\.\.?(/|$)~',$n))throw new RuntimeException(le_text('Niebezpieczna ścieżka w ZIP-ie.'));
            $stat=$zip->statIndex($i);$total+=(int)($stat['size']??0);if($total>50*1024*1024||($stat['size']??0)>10*1024*1024)throw new RuntimeException(le_text('Rozpakowany plugin jest za duży.'));
            $opsys=0;$attrs=0;if($zip->getExternalAttributesIndex($i,$opsys,$attrs)&&(($attrs>>16)&0170000)===0120000)throw new RuntimeException(le_text('Linki symboliczne w ZIP-ie są niedozwolone.'));
            if(basename($n)==='manifest.json'&&substr_count(trim($n,'/'),'/')<=1){
                if($manifest!==null)throw new RuntimeException(le_text('ZIP ma więcej niż jeden manifest pluginu.'));
                $manifest=json_decode((string)$zip->getFromIndex($i),true,512,JSON_THROW_ON_ERROR);$prefix=dirname($n)==='.'?'':dirname($n).'/';
            }
            $entries[]=$n;
        }
        if(!is_array($manifest)||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/',(string)($manifest['id']??''))||trim((string)($manifest['name']??''))==='')throw new RuntimeException(le_text('Manifest wymaga poprawnego id i name.'));
        $pid=(string)$manifest['id'];
        if(!in_array($type,['plugin','theme'],true))throw new RuntimeException(le_text('Nieprawidłowy typ paczki.'));
        if($expected&&($pid!==$expected['id']||(string)($manifest['version']??'')!==(string)$expected['version']))throw new RuntimeException(le_text('Manifest nie zgadza się z repozytorium.'));
        if($type==='theme'&&$pid==='default')throw new RuntimeException(le_text('Nie można nadpisać motywu systemowego.'));
        $dest=__DIR__.'/../'.($type==='theme'?'themes':'plugins').'/'.$pid;
        if(is_dir($dest)){$dest=null;throw new RuntimeException(le_text('Plugin o tym ID już istnieje.'));}
        if(!mkdir($dest,0755,true)){$dest=null;throw new RuntimeException(le_text('Nie można utworzyć katalogu pluginu.'));}
        $written=[];
        foreach($entries as $i=>$n){
            if($prefix!==''&&!str_starts_with($n,$prefix))throw new RuntimeException(le_text('Pliki pluginu muszą być w jednym folderze z manifestem.'));
            $rel=substr($n,strlen($prefix));if($rel===''||str_ends_with($rel,'/'))continue;
            if(isset($written[strtolower($rel)]))throw new RuntimeException(le_text('Zduplikowany plik w pluginie.'));$written[strtolower($rel)]=true;
            if($type==='theme'&&!in_array($rel,['manifest.json','package-signature.json'],true)&&!preg_match('/\.(css|png|jpg|jpeg|webp|gif|woff2?|ttf|otf)$/i',$rel))throw new RuntimeException(le_text('Motywy mogą zawierać wyłącznie CSS, obrazy i fonty.'));
            $file=$dest.'/'.$rel;if(!is_dir(dirname($file))&&!mkdir(dirname($file),0755,true))throw new RuntimeException(le_text('Nie można utworzyć katalogu.'));
            $content=$zip->getFromIndex($i);if($content===false||file_put_contents($file,$content)===false)throw new RuntimeException(le_text('Nie można zapisać pliku pluginu.'));
        }
        foreach($type==='plugin'?['bootstrap','entry','main','admin','api','public','frontend']:[] as $key)if(isset($manifest[$key])){
            $value=$manifest[$key];if(is_array($value))$value=$value['file']??$value['path']??$value['entry']??null;
            if(!is_string($value)||!isset($written[strtolower($value)])||!str_ends_with(strtolower($value),'.php'))throw new RuntimeException(le_text('Nieprawidłowy plik ').$key.le_text(' w manifeście.'));
        }
        if($type==='theme'){
            $css=(string)($manifest['css']??'');if(!preg_match('~^[a-zA-Z0-9._/-]+\.css$~',$css)||!isset($written[strtolower($css)]))throw new RuntimeException(le_text('Brak zadeklarowanego CSS.'));
            if($css!=='style.css'&&!copy($dest.'/'.$css,$dest.'/style.css'))throw new RuntimeException(le_text('Nie można zapisać CSS.'));
            $manifest['css']='style.css';file_put_contents($dest.'/manifest.json',json_encode($manifest,JSON_UNESCAPED_UNICODE));
            db()->prepare('INSERT INTO themes(theme_key,name,version,author,description,path,is_system) VALUES(?,?,?,?,?,?,0)')->execute([$pid,$manifest['name'],$manifest['version']??'1.0.0',$manifest['author']??'',$manifest['description']??'','themes/'.$pid]);
        }else{
        db()->prepare('INSERT INTO plugins(plugin_key,name,version,author,description,path,enabled,manifest_json) VALUES(?,?,?,?,?,?,0,?)')->execute([$pid,(string)$manifest['name'],(string)($manifest['version']??'1.0.0'),(string)($manifest['author']??''),(string)($manifest['description']??''),'plugins/'.$pid,json_encode($manifest,JSON_UNESCAPED_UNICODE)]);
        }
    }catch(Throwable $e){
        if($dest&&is_dir($dest)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dest,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($dest);}throw $e;
    }finally{$zip->close();}
}
