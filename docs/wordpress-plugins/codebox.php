<?php
/*
Plugin Name: CodeBox (Collapse + Copy + Highlight)
Plugin URI: https://github.com/feeday/wordpress-plugins
Description: 为文章中的代码块自动增加“收起/展开 + 一键复制 + 语法高亮(Prism)”工具条，支持 Gutenberg 代码块与 <pre>/<code>。
Version: 1.0.2
Author: feeday
License: GPLv2 or later
*/

if (!defined('ABSPATH')) exit;

class Cpuck_CodeBox_Plugin {

    // =========================
    // 可调参数
    // =========================
    const COLLAPSED_HEIGHT_PX       = 260;   // 折叠后显示高度
    const AUTO_COLLAPSE_IF_OVER_PX  = 320;   // 超过该高度默认折叠
    const DEFAULT_COLLAPSE_LONG_CODE = true; // 长代码默认折叠
    const SHOW_TOGGLE_BUTTON        = true;  // 显示 展开/收起
    const SHOW_META                 = true;  // 显示行数
    const FORCE_COLLAPSE_ALL        = false; // 强制所有代码块默认折叠

    // 语法高亮开关
    const ENABLE_HIGHLIGHT          = true;

    // Prism CDN（默认 jsDelivr）
    // 如果你访问不了 jsDelivr，可改成 unpkg：
    // https://unpkg.com/prismjs@1.29.0
    const PRISM_CDN_BASE            = 'https://cdn.jsdelivr.net/npm/prismjs@1.29.0';

    // Prism 主题（浅色推荐：prism / prism-coy / prism-solarizedlight）
    // 这里用 prism（默认浅色）
    const PRISM_THEME_CSS           = 'themes/prism.min.css';

    // 额外语言组件（按需增加）
    const PRISM_LANGS = [
        'components/prism-python.min.js',
        'components/prism-php.min.js',
        'components/prism-bash.min.js',
        'components/prism-json.min.js',
        'components/prism-yaml.min.js',
        'components/prism-sql.min.js',
        // prism.js 自带：markup/css/clike/javascript，无需额外加载
    ];

    public static function init() {
        add_action('wp_head', [__CLASS__, 'print_css'], 30);
        add_action('wp_head', [__CLASS__, 'print_assets_and_js'], 40);
    }

    public static function print_css() {
        if (is_admin()) return;
        if (!is_singular()) return;

        $collapsed = intval(self::COLLAPSED_HEIGHT_PX);

        echo "\n<style id='cpuck-codebox-css'>\n";
        echo "/* Cpuck CodeBox */\n";
        echo ":root{--cpuck-codebox-border:#e5e7eb;--cpuck-codebox-bg:#f3f4f6;--cpuck-codebox-toolbar-bg:#ffffff;--cpuck-codebox-text:#111827;--cpuck-codebox-muted:#6b7280;--cpuck-codebox-btn-bg:#ffffff;--cpuck-codebox-btn-border:#d1d5db;--cpuck-codebox-btn-hover:#f3f4f6;}\n";

        echo ".cpuck-codebox{border:1px solid var(--cpuck-codebox-border);border-radius:12px;overflow:hidden;margin:16px 0;background:var(--cpuck-codebox-bg);}\n";
        echo ".cpuck-codebox .cpuck-codebox-toolbar{display:flex;align-items:center;gap:8px;padding:10px 10px;background:var(--cpuck-codebox-toolbar-bg);border-bottom:1px solid var(--cpuck-codebox-border);}\n";
        echo ".cpuck-codebox .cpuck-codebox-toolbar .cpuck-codebox-title{font-size:12px;color:var(--cpuck-codebox-muted);user-select:none;}\n";
        echo ".cpuck-codebox .cpuck-codebox-toolbar .cpuck-codebox-spacer{margin-left:auto;}\n";
        echo ".cpuck-codebox .cpuck-codebox-btn{appearance:none;border:1px solid var(--cpuck-codebox-btn-border);background:var(--cpuck-codebox-btn-bg);color:var(--cpuck-codebox-text);font-size:12px;line-height:1;padding:8px 10px;border-radius:10px;cursor:pointer;user-select:none;}\n";
        echo ".cpuck-codebox .cpuck-codebox-btn:hover{background:var(--cpuck-codebox-btn-hover);}\n";
        echo ".cpuck-codebox .cpuck-codebox-btn:active{transform:translateY(1px);}\n";

        // ✅ 强制浅灰背景（覆盖主题的 pre 背景）
        echo ".cpuck-codebox pre{margin:0;padding:14px;overflow:auto;white-space:pre;tab-size:4;background:var(--cpuck-codebox-bg) !important;color:var(--cpuck-codebox-text) !important;}\n";
        echo ".cpuck-codebox code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,\"Liberation Mono\",\"Courier New\",monospace;font-size:13px;background:transparent !important;}\n";

        // Prism 有时会给 pre[class*=\"language-\"] 设置背景，这里也统一强制浅灰
        echo ".cpuck-codebox pre[class*=\"language-\"]{background:var(--cpuck-codebox-bg) !important;}\n";

        // 折叠效果
        echo ".cpuck-codebox.is-collapsed pre{max-height:{$collapsed}px;overflow:hidden;position:relative;}\n";
        echo ".cpuck-codebox.is-collapsed pre::after{content:\"\";position:absolute;left:0;right:0;bottom:0;height:64px;background:linear-gradient(to bottom, rgba(243,244,246,0), rgba(243,244,246,1));pointer-events:none;}\n";

        // 移动端
        echo "@media (max-width:768px){.cpuck-codebox .cpuck-codebox-toolbar{padding:8px 8px;}.cpuck-codebox pre{padding:12px;} .cpuck-codebox code{font-size:12px;}}\n";
        echo "</style>\n";
    }

    public static function print_assets_and_js() {
        if (is_admin()) return;
        if (!is_singular()) return;

        // 1) Prism 资源（可关）
        if (self::ENABLE_HIGHLIGHT) {
            $base = rtrim(self::PRISM_CDN_BASE, '/');
            $theme = self::PRISM_THEME_CSS;

            echo "\n<link id='cpuck-prism-css' rel='stylesheet' href='{$base}/{$theme}'>\n";
            echo "<script id='cpuck-prism-core' src='{$base}/prism.min.js'></script>\n";

            // 语言组件
            foreach (self::PRISM_LANGS as $lang_js) {
                $src = "{$base}/{$lang_js}";
                echo "<script class='cpuck-prism-lang' src='{$src}'></script>\n";
            }
        }

        $collapsed = intval(self::COLLAPSED_HEIGHT_PX);
        $auto_over = intval(self::AUTO_COLLAPSE_IF_OVER_PX);
        $default_collapse = self::DEFAULT_COLLAPSE_LONG_CODE ? 'true' : 'false';
        $show_toggle = self::SHOW_TOGGLE_BUTTON ? 'true' : 'false';
        $show_meta = self::SHOW_META ? 'true' : 'false';
        $force_all = self::FORCE_COLLAPSE_ALL ? 'true' : 'false';
        $enable_highlight = self::ENABLE_HIGHLIGHT ? 'true' : 'false';

        echo "\n<script id='cpuck-codebox-js'>(function(){\n";
        echo "  'use strict';\n";
        echo "  var CONFIG={collapsedHeight:$collapsed, autoCollapseIfOver:$auto_over, defaultCollapseLongCode:$default_collapse, showToggle:$show_toggle, showMeta:$show_meta, forceAll:$force_all, enableHighlight:$enable_highlight};\n";
        echo "  function qsAll(sel, root){return Array.prototype.slice.call((root||document).querySelectorAll(sel));}\n";
        echo "  function el(tag, cls, text){var e=document.createElement(tag);if(cls)e.className=cls;if(typeof text==='string')e.textContent=text;return e;}\n";
        echo "  function closestAny(node, selectors){for(var i=0;i<selectors.length;i++){if(node.closest && node.closest(selectors[i])) return node.closest(selectors[i]);}return null;}\n";

        // 复制
        echo "  function copyText(txt){\n";
        echo "    if(!txt) return Promise.reject(new Error('empty'));\n";
        echo "    if(navigator.clipboard && navigator.clipboard.writeText){return navigator.clipboard.writeText(txt);}\n";
        echo "    return new Promise(function(resolve,reject){\n";
        echo "      try{\n";
        echo "        var ta=document.createElement('textarea');\n";
        echo "        ta.value=txt;ta.setAttribute('readonly','');\n";
        echo "        ta.style.position='fixed';ta.style.left='-9999px';ta.style.top='-9999px';\n";
        echo "        document.body.appendChild(ta);ta.select();\n";
        echo "        var ok=document.execCommand('copy');document.body.removeChild(ta);\n";
        echo "        if(ok) resolve(); else reject(new Error('execCommand failed'));\n";
        echo "      }catch(err){reject(err);} }); }\n";

        // 取代码文本
        echo "  function getCodeText(container){\n";
        echo "    var code=container.querySelector('code');\n";
        echo "    var pre=container.matches('pre')?container:container.querySelector('pre');\n";
        echo "    if(code) return code.textContent;\n";
        echo "    if(pre) return pre.textContent;\n";
        echo "    return container.textContent || '';\n";
        echo "  }\n";

        echo "  function countLines(text){ if(!text) return 0; var m=text.match(/\\n/g); return (m?m.length:0)+1; }\n";

        // ✅ 语言猜测（没有 language-xxx 时用）
        echo "  function guessLanguage(text){\n";
        echo "    var t=(text||'').trim();\n";
        echo "    var head=t.slice(0, 800);\n";
        echo "    if(head.indexOf('<?php')>=0) return 'php';\n";
        echo "    if(/\\bimport\\s+\\w+|\\bdef\\s+\\w+\\(|\\bclass\\s+\\w+\\s*\\:/.test(head)) return 'python';\n";
        echo "    if(/\\bconsole\\.log\\b|\\bconst\\b|\\blet\\b|\\bfunction\\b|=>/.test(head)) return 'javascript';\n";
        echo "    if(/\\bSELECT\\b|\\bFROM\\b|\\bWHERE\\b/i.test(head)) return 'sql';\n";
        echo "    if(/\\b(pip\\s+install|sudo\\s+|apt\\s+|yum\\s+|cd\\s+|ls\\b|rm\\b)\\b/.test(head) || head.startsWith('#!/')) return 'bash';\n";
        echo "    if(head.startsWith('{') || head.startsWith('[')) {\n";
        echo "      if(/\"\\s*:\\s*/.test(head)) return 'json';\n";
        echo "    }\n";
        echo "    if(/\\<\\!DOCTYPE|\\<html|\\<div|\\<span|\\<script|\\<style/i.test(head)) return 'markup';\n";
        echo "    if(/\\{\\s*\\n?\\s*[\\.#]?[a-zA-Z0-9_-]+\\s*\\{/.test(head) || /\\bcolor\\s*:\\s*|\\bfont\\s*:\\s*/.test(head)) return 'css';\n";
        echo "    return 'clike';\n";
        echo "  }\n";

        // Prism 高亮
        echo "  function tryHighlight(box){\n";
        echo "    if(!CONFIG.enableHighlight) return;\n";
        echo "    var pre=box.querySelector('pre');\n";
        echo "    if(!pre) return;\n";
        echo "    var code=box.querySelector('code');\n";

        // 如果没有 code，就创建一个（Prism 更稳）
        echo "    if(!code){\n";
        echo "      code=document.createElement('code');\n";
        echo "      code.textContent = pre.textContent;\n";
        echo "      pre.textContent='';\n";
        echo "      pre.appendChild(code);\n";
        echo "    }\n";

        // 语言 class
        echo "    var cls=code.className || '';\n";
        echo "    var hasLang=/\\blanguage-\\w+\\b/.test(cls) || /\\blang-\\w+\\b/.test(cls);\n";
        echo "    if(!hasLang){\n";
        echo "      var lang=guessLanguage(code.textContent);\n";
        echo "      code.classList.add('language-'+lang);\n";
        echo "      pre.classList.add('language-'+lang);\n";
        echo "    }else{\n";
        echo "      // 同步到 pre（Prism 有些主题会用 pre 的 class）\n";
        echo "      var m=cls.match(/language-\\w+/);\n";
        echo "      if(m && m[0]) pre.classList.add(m[0]);\n";
        echo "    }\n";

        // 等 Prism 加载完成再高亮（最多重试 30 次）
        echo "    var tries=0;\n";
        echo "    (function waitPrism(){\n";
        echo "      tries++;\n";
        echo "      if(window.Prism && Prism.highlightElement){\n";
        echo "        try{ Prism.highlightElement(code); }catch(e){}\n";
        echo "        return;\n";
        echo "      }\n";
        echo "      if(tries<30) setTimeout(waitPrism, 100);\n";
        echo "    })();\n";
        echo "  }\n";

        // 增强一个 pre
        echo "  function enhance(pre){\n";
        echo "    if(!pre) return;\n";
        echo "    var wrapTarget=pre;\n";
        echo "    var fig=pre.closest && pre.closest('figure.wp-block-code');\n";
        echo "    if(fig && fig.querySelector('pre')) wrapTarget=fig;\n";
        echo "    if(wrapTarget.dataset && wrapTarget.dataset.cpuckCodebox==='1') return;\n";
        echo "    if(wrapTarget.dataset) wrapTarget.dataset.cpuckCodebox='1';\n";
        echo "    if(wrapTarget.closest && wrapTarget.closest('.cpuck-codebox')) return;\n";

        echo "    var box=el('div','cpuck-codebox');\n";
        echo "    var toolbar=el('div','cpuck-codebox-toolbar');\n";
        echo "    var title=el('div','cpuck-codebox-title','Code');\n";
        echo "    toolbar.appendChild(title);\n";
        echo "    var spacer=el('div','cpuck-codebox-spacer','');\n";
        echo "    toolbar.appendChild(spacer);\n";
        echo "    var meta=el('div','cpuck-codebox-title','');\n";
        echo "    meta.style.display=CONFIG.showMeta?'block':'none';\n";
        echo "    meta.style.whiteSpace='nowrap';\n";
        echo "    toolbar.appendChild(meta);\n";
        echo "    var btnToggle=el('button','cpuck-codebox-btn','展开');\n";
        echo "    btnToggle.type='button';\n";
        echo "    btnToggle.style.display=CONFIG.showToggle?'inline-block':'none';\n";
        echo "    toolbar.appendChild(btnToggle);\n";
        echo "    var btnCopy=el('button','cpuck-codebox-btn','复制');\n";
        echo "    btnCopy.type='button';\n";
        echo "    toolbar.appendChild(btnCopy);\n";

        echo "    var parent=wrapTarget.parentNode;\n";
        echo "    parent.insertBefore(box, wrapTarget);\n";
        echo "    box.appendChild(toolbar);\n";
        echo "    box.appendChild(wrapTarget);\n";
        echo "    if(wrapTarget.tagName && wrapTarget.tagName.toLowerCase()==='figure'){wrapTarget.style.margin='0';}\n";

        echo "    function applyInitial(){\n";
        echo "      var preEl=box.querySelector('pre');\n";
        echo "      if(!preEl) return;\n";
        echo "      var text=getCodeText(box);\n";
        echo "      var lines=countLines(text);\n";
        echo "      if(CONFIG.showMeta) meta.textContent=lines+' lines';\n";
        echo "      var needCollapse = (CONFIG.forceAll===true) ? true : (preEl.scrollHeight > CONFIG.autoCollapseIfOver);\n";
        echo "      if(CONFIG.defaultCollapseLongCode && needCollapse){\n";
        echo "        box.classList.add('is-collapsed'); btnToggle.textContent='展开';\n";
        echo "      }else{\n";
        echo "        box.classList.remove('is-collapsed'); btnToggle.textContent='收起';\n";
        echo "      }\n";
        echo "      if(CONFIG.showToggle){ btnToggle.style.display = needCollapse ? 'inline-block' : 'none'; }\n";
        echo "    }\n";

        // copy
        echo "    btnCopy.addEventListener('click', function(){\n";
        echo "      var text=getCodeText(box);\n";
        echo "      var old=btnCopy.textContent;\n";
        echo "      btnCopy.textContent='复制中...';\n";
        echo "      copyText(text).then(function(){btnCopy.textContent='已复制';setTimeout(function(){btnCopy.textContent=old;},1200);})\n";
        echo "      .catch(function(){btnCopy.textContent='复制失败';setTimeout(function(){btnCopy.textContent=old;},1200);});\n";
        echo "    });\n";

        // toggle
        echo "    btnToggle.addEventListener('click', function(){\n";
        echo "      var isCollapsed=box.classList.toggle('is-collapsed');\n";
        echo "      btnToggle.textContent = isCollapsed ? '展开' : '收起';\n";
        echo "    });\n";
        echo "    toolbar.addEventListener('dblclick', function(){\n";
        echo "      if(btnToggle.style.display==='none') return;\n";
        echo "      var isCollapsed=box.classList.toggle('is-collapsed');\n";
        echo "      btnToggle.textContent = isCollapsed ? '展开' : '收起';\n";
        echo "    });\n";

        echo "    requestAnimationFrame(function(){ applyInitial(); tryHighlight(box); });\n";
        echo "    setTimeout(function(){ applyInitial(); tryHighlight(box); }, 120);\n";
        echo "  }\n";

        // 扫描
        echo "  function scan(){\n";
        echo "    var content=closestAny(document.body, ['.entry-content','.post-content','.site-content','article']);\n";
        echo "    if(!content) content=document;\n";
        echo "    qsAll('figure.wp-block-code pre', content).forEach(function(pre){ enhance(pre); });\n";
        echo "    qsAll('pre', content).forEach(function(pre){ if(pre.closest && pre.closest('.cpuck-codebox')) return; enhance(pre); });\n";
        echo "  }\n";

        echo "  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', scan); else scan();\n";
        echo "  try{ var mo=new MutationObserver(function(muts){ for(var i=0;i<muts.length;i++){ if(muts[i].addedNodes && muts[i].addedNodes.length){ scan(); break; } } }); mo.observe(document.body,{childList:true,subtree:true}); }catch(e){}\n";
        echo "})();</script>\n";
    }
}

Cpuck_CodeBox_Plugin::init();
