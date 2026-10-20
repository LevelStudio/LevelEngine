<?php
$footerText=setting('footer_text','© '.date('Y').' '.setting('site_name','LevelEngine CMS').'.');
$links=json_decode(setting('footer_links','[]'),true); if(!is_array($links))$links=[];
?>
<footer class="site-footer"><div class="container footer-inner"><div><div><?=e(str_replace('Wszelkie prawa zastrzeżone.',le_text('Wszelkie prawa zastrzeżone.'),$footerText))?></div><?php if($links):?><nav class="footer-links"><?php foreach($links as $l):if(is_array($l)&&!empty($l['label'])&&!empty($l['url'])):?><a href="<?=e(le_url((string)$l['url']))?>"><?=e($l['label'])?></a><?php endif;endforeach;?></nav><?php endif;?></div><div class="footer-engine"><a href="https://cms.levelgame.pl/" target="_blank" rel="noopener">LevelEngine · by LevelStudio</a></div></div></footer><script src="/assets/js/app.js?v=1.0.0"></script><link rel="stylesheet" href="/assets/css/gallery.css?v=1.0.0"><script src="/assets/js/gallery.js?v=1.0.0"></script></body></html>
