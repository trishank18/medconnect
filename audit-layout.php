<?php
$page = file_get_contents(__DIR__ . '/patient-login.html');
$script = <<<'HTML'
<script>
setTimeout(() => {
  const qs = ['html', 'body', '.auth-layout', '.auth-visual', '.auth-visual h1', '.login-container', 'form', 'form input'];
  const rows = qs.map(q => { const e=document.querySelector(q),r=e.getBoundingClientRect(),s=getComputedStyle(e); return `${q}: ${r.left.toFixed(0)}..${r.right.toFixed(0)} w${r.width.toFixed(0)} scroll${e.scrollWidth}/${e.clientWidth} display=${s.display} grid=${s.gridTemplateColumns}`; });
  const o=document.createElement('pre'); o.textContent=`inner=${innerWidth} html=${document.documentElement.clientWidth}/${document.documentElement.scrollWidth} body=${document.body.clientWidth}/${document.body.scrollWidth}\\n`+rows.join('\\n'); o.style.cssText='position:fixed;top:2px;left:2px;z-index:999999;background:#fff;color:#000;font:10px monospace;padding:5px;white-space:pre-wrap;max-width:380px'; document.body.appendChild(o);
}, 700);
</script>
HTML;
echo str_replace('</body>', $script . '</body>', $page);
?>
