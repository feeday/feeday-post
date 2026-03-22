<?php
/*
Plugin Name: Floating TOC (Mobile+PC)
Plugin URI: https://github.com/feeday/wordpress-plugins/
Description: 自动生成文章标题目录（H2/H3/H4），左侧悬浮按钮展开抽屉面板，支持手机/PC，下拉折叠子标题，高亮当前章节。
Version: 1.0.1
Author: feeday
License: GPLv2 or later
*/

if (!defined('ABSPATH')) exit;

class Cpuck_Floating_TOC {

    // =========================
    // 配置区（按需修改）
    // =========================
    const LEVELS = ['h2','h3','h4'];

    const DESKTOP_PANEL_WIDTH = 200; // px
    const DESKTOP_BTN_TOP = 260;     // px
    const MOBILE_BTN_BOTTOM = true;

    const DEFAULT_OPEN = false;
    const DEFAULT_COLLAPSE_SUB = true;

    // ✅ 新增：PC 点击目录项后是否自动关闭抽屉（建议 true，解决遮挡）
    const AUTO_CLOSE_ON_CLICK_DESKTOP = true;

    // ✅ 新增：当屏幕足够宽时，把抽屉放到正文左侧“空白区”，尽量不覆盖正文
    // 触发覆盖模式的阈值：正文左边距 < 抽屉宽度 + GUTTER * 2
    const DESKTOP_GUTTER_PX = 16;
    const OVERLAY_MODE_MAX_WIDTH = 1024; // <= 这个宽度优先走覆盖模式（更像手机抽屉）

    const IO_ROOT_MARGIN = '-20% 0px -65% 0px';

    const ID_PREFIX = 'cpuck-toc-';
    const MAX_HEADINGS = 200;

    public static function init() {
        add_filter('the_content', [__CLASS__, 'filter_content_add_heading_ids_and_data'], 20);
        add_action('wp_head', [__CLASS__, 'print_css'], 30);
        add_action('wp_head', [__CLASS__, 'print_js'], 40);
    }

    public static function filter_content_add_heading_ids_and_data($content) {
        if (is_admin()) return $content;
        if (!is_singular()) return $content;

        global $post;
        if (!$post || empty($content)) return $content;

        if (strpos($content, 'cpuck-toc-data') !== false) {
            return $content;
        }

        $levels = self::LEVELS;
        $maxHeadings = intval(self::MAX_HEADINGS);

        libxml_use_internal_errors(true);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $html = '<div id="cpuck-toc-wrap">' . $content . '</div>';
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        if (!$loaded) {
            libxml_clear_errors();
            return $content;
        }

        $xpath = new DOMXPath($dom);

        $queryParts = [];
        foreach ($levels as $lv) $queryParts[] = '//div[@id="cpuck-toc-wrap"]//' . strtolower($lv);
        $query = implode(' | ', $queryParts);

        $nodes = $xpath->query($query);
        if (!$nodes || $nodes->length === 0) {
            libxml_clear_errors();
            return $content;
        }

        $usedIds = [];
        $tocItems = [];
        $count = 0;

        foreach ($nodes as $node) {
            if ($count >= $maxHeadings) break;

            $tag = strtolower($node->nodeName);
            $level = intval(substr($tag, 1)); // h2=>2

            $text = trim(preg_replace('/\s+/u', ' ', $node->textContent));
            if ($text === '') continue;

            $id = trim($node->getAttribute('id'));

            if ($id === '') {
                $base = sanitize_title($text);
                if ($base === '') $base = 'h-' . substr(md5($text), 0, 10);

                $id = self::ID_PREFIX . $base;

                $suffix = 2;
                $finalId = $id;
                while (isset($usedIds[$finalId])) {
                    $finalId = $id . '-' . $suffix;
                    $suffix++;
                }
                $id = $finalId;
                $node->setAttribute('id', $id);
            }

            if (isset($usedIds[$id])) {
                $suffix = 2;
                $finalId = $id . '-' . $suffix;
                while (isset($usedIds[$finalId])) {
                    $suffix++;
                    $finalId = $id . '-' . $suffix;
                }
                $id = $finalId;
                $node->setAttribute('id', $id);
            }

            $usedIds[$id] = true;

            $tocItems[] = [
                'id'    => $id,
                'text'  => $text,
                'level' => $level,
            ];

            $count++;
        }

        $wrap = $dom->getElementById('cpuck-toc-wrap');
        if (!$wrap) {
            libxml_clear_errors();
            return $content;
        }

        $newContent = '';
        foreach ($wrap->childNodes as $child) $newContent .= $dom->saveHTML($child);

        $json = wp_json_encode($tocItems, JSON_UNESCAPED_UNICODE);
        $b64  = base64_encode($json);

        $dataDiv = '<div class="cpuck-toc-data" data-cpuck-toc-b64="' . esc_attr($b64) . '" style="display:none!important;"></div>';

        libxml_clear_errors();

        return $newContent . "\n" . $dataDiv;
    }

    public static function print_css() {
        if (is_admin()) return;
        if (!is_singular()) return;

        $panelW = intval(self::DESKTOP_PANEL_WIDTH);
        $btnTop = intval(self::DESKTOP_BTN_TOP);

        echo "\n<style id='cpuck-toc-css'>\n";
        echo ":root{--cpuck-toc-bg:#ffffff;--cpuck-toc-panel:#ffffff;--cpuck-toc-border:#e5e7eb;--cpuck-toc-text:#111827;--cpuck-toc-muted:#6b7280;--cpuck-toc-accent:#2563eb;--cpuck-toc-shadow:0 12px 34px rgba(0,0,0,.12);} \n";

        echo ".cpuck-toc-overlay{position:fixed;inset:0;background:rgba(0,0,0,.35);opacity:0;pointer-events:none;transition:opacity .18s ease;z-index:99990;} \n";
        echo ".cpuck-toc-overlay.is-open{opacity:1;pointer-events:auto;} \n";

        echo ".cpuck-toc-fab{position:fixed;left:12px;top:{$btnTop}px;z-index:99991;display:inline-flex;align-items:center;gap:8px;padding:10px 12px;border-radius:999px;border:1px solid var(--cpuck-toc-border);background:var(--cpuck-toc-bg);color:var(--cpuck-toc-text);box-shadow:var(--cpuck-toc-shadow);cursor:pointer;user-select:none;} \n";
        echo ".cpuck-toc-fab:hover{transform:translateY(-1px);} .cpuck-toc-fab:active{transform:translateY(0px);} \n";
        echo ".cpuck-toc-fab svg{width:18px;height:18px;} .cpuck-toc-fab .cpuck-toc-fab-text{font-size:13px;font-weight:600;} \n";

        echo ".cpuck-toc-panel{position:fixed;top:0;left:0;height:100vh;width:{$panelW}px;max-width:86vw;background:var(--cpuck-toc-panel);border-right:1px solid var(--cpuck-toc-border);box-shadow:var(--cpuck-toc-shadow);transform:translateX(-105%);transition:transform .22s ease;z-index:99992;display:flex;flex-direction:column;} \n";
        echo ".cpuck-toc-panel.is-open{transform:translateX(0%);} \n";

        echo ".cpuck-toc-header{display:flex;align-items:center;gap:10px;padding:12px 12px;border-bottom:1px solid var(--cpuck-toc-border);} \n";
        echo ".cpuck-toc-title{font-size:14px;font-weight:700;color:var(--cpuck-toc-text);} \n";
        echo ".cpuck-toc-actions{margin-left:auto;display:flex;align-items:center;gap:8px;} \n";
        echo ".cpuck-toc-btn{appearance:none;border:1px solid var(--cpuck-toc-border);background:#fff;color:var(--cpuck-toc-text);font-size:12px;padding:7px 10px;border-radius:10px;cursor:pointer;} \n";
        echo ".cpuck-toc-btn:hover{background:#f3f4f6;} \n";

        echo ".cpuck-toc-body{padding:10px 8px 16px;overflow:auto;} \n";
        echo ".cpuck-toc-list{list-style:none;margin:0;padding:0;} \n";
        echo ".cpuck-toc-item{display:flex;align-items:center;gap:6px;border-radius:10px;padding:7px 8px;cursor:pointer;} \n";
        echo ".cpuck-toc-item:hover{background:#f3f4f6;} \n";
        echo ".cpuck-toc-item.is-active{background:rgba(37,99,235,.10);} \n";
        echo ".cpuck-toc-item.is-active .cpuck-toc-link{color:var(--cpuck-toc-accent);font-weight:700;} \n";
        echo ".cpuck-toc-link{color:var(--cpuck-toc-text);text-decoration:none;font-size:13px;line-height:1.25;flex:1;word-break:break-word;} \n";

        echo ".cpuck-toc-lv-2{padding-left:6px;} .cpuck-toc-lv-3{padding-left:18px;} .cpuck-toc-lv-4{padding-left:30px;} \n";

        echo ".cpuck-toc-toggle{width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;border:1px solid var(--cpuck-toc-border);background:#fff;flex:0 0 auto;} \n";
        echo ".cpuck-toc-toggle:hover{background:#f3f4f6;} \n";
        echo ".cpuck-toc-toggle svg{width:12px;height:12px;transition:transform .18s ease;} \n";
        echo ".cpuck-toc-item.is-collapsed .cpuck-toc-toggle svg{transform:rotate(-90deg);} \n";
        echo ".cpuck-toc-hidden{display:none!important;} \n";

        // 让锚点跳转时标题不被顶部元素盖住（可选）
        echo ".entry-content h2, .entry-content h3, .entry-content h4, article h2, article h3, article h4{scroll-margin-top:84px;} \n";

        echo "@media (max-width:768px){\n";
        echo "  .cpuck-toc-panel{width:56vw;max-width:56vw;}\n";
        echo "  .cpuck-toc-fab{left:12px;";
        if (self::MOBILE_BTN_BOTTOM) echo "top:auto;bottom:35px;";
        else echo "top:52vh;";
        echo "}\n";
        echo "}\n";

        echo "</style>\n";
    }

    public static function print_js() {
        if (is_admin()) return;
        if (!is_singular()) return;

        $defaultOpen = self::DEFAULT_OPEN ? 'true' : 'false';
        $defaultCollapseSub = self::DEFAULT_COLLAPSE_SUB ? 'true' : 'false';
        $autoCloseDesktop = self::AUTO_CLOSE_ON_CLICK_DESKTOP ? 'true' : 'false';
        $rootMargin = esc_js(self::IO_ROOT_MARGIN);

        $gutter = intval(self::DESKTOP_GUTTER_PX);
        $overlayMaxW = intval(self::OVERLAY_MODE_MAX_WIDTH);

        echo "\n<script id='cpuck-toc-js'>(function(){\n";
        echo "  'use strict';\n";
        echo "  var CONFIG={defaultOpen:$defaultOpen, defaultCollapseSub:$defaultCollapseSub, autoCloseDesktop:$autoCloseDesktop, rootMargin:'{$rootMargin}', gutter:$gutter, overlayMaxW:$overlayMaxW};\n";

        echo "  function qs(sel, root){return (root||document).querySelector(sel);} \n";
        echo "  function qsa(sel, root){return Array.prototype.slice.call((root||document).querySelectorAll(sel));}\n";
        echo "  function el(tag, cls, text){var e=document.createElement(tag); if(cls) e.className=cls; if(typeof text==='string') e.textContent=text; return e;}\n";
        echo "  function closestAny(node, selectors){for(var i=0;i<selectors.length;i++){if(node.closest && node.closest(selectors[i])) return node.closest(selectors[i]);}return null;}\n";

        echo "  function decodeB64Unicode(b64){\n";
        echo "    try{\n";
        echo "      var bin=atob(b64);\n";
        echo "      var bytes=new Uint8Array(bin.length);\n";
        echo "      for(var i=0;i<bin.length;i++) bytes[i]=bin.charCodeAt(i);\n";
        echo "      return new TextDecoder('utf-8').decode(bytes);\n";
        echo "    }catch(e){\n";
        echo "      try{ return decodeURIComponent(escape(atob(b64))); }catch(_){ return ''; }\n";
        echo "    }\n";
        echo "  }\n";

        echo "  function iconList(){var s=document.createElementNS('http://www.w3.org/2000/svg','svg');s.setAttribute('viewBox','0 0 24 24');s.innerHTML='<path fill=\"currentColor\" d=\"M4 6h16v2H4V6zm0 5h10v2H4v-2zm0 5h16v2H4v-2z\"/>';return s;}\n";
        echo "  function iconChevron(){var s=document.createElementNS('http://www.w3.org/2000/svg','svg');s.setAttribute('viewBox','0 0 24 24');s.innerHTML='<path fill=\"currentColor\" d=\"M8.59 16.59 13.17 12 8.59 7.41 10 6l6 6-6 6z\"/>';return s;}\n";
        echo "  function iconClose(){var s=document.createElementNS('http://www.w3.org/2000/svg','svg');s.setAttribute('viewBox','0 0 24 24');s.innerHTML='<path fill=\"currentColor\" d=\"M18.3 5.71 12 12l6.3 6.29-1.41 1.42L12 13.41l-6.89 6.3-1.42-1.41L10.59 12 3.69 5.71 5.1 4.29 12 10.59l6.89-6.3z\"/>';return s;}\n";

        echo "  function buildUI(items){\n";
        echo "    if(!items || !items.length) return;\n";

        echo "    var overlay=el('div','cpuck-toc-overlay');\n";
        echo "    var panel=el('aside','cpuck-toc-panel');\n";
        echo "    panel.setAttribute('aria-hidden','true');\n";
        echo "    panel.dataset.overlayMode='1';\n";

        echo "    var header=el('div','cpuck-toc-header');\n";
        echo "    var title=el('div','cpuck-toc-title','目录');\n";
        echo "    header.appendChild(title);\n";

        echo "    var actions=el('div','cpuck-toc-actions');\n";
        echo "    var btnExpandAll=el('button','cpuck-toc-btn','展开'); btnExpandAll.type='button';\n";
        echo "    var btnCollapseAll=el('button','cpuck-toc-btn','收起'); btnCollapseAll.type='button';\n";
        echo "    var btnClose=el('button','cpuck-toc-btn',''); btnClose.type='button';\n";
        echo "    btnClose.style.display='inline-flex'; btnClose.style.alignItems='center'; btnClose.style.justifyContent='center'; btnClose.style.padding='7px 9px';\n";
        echo "    btnClose.appendChild(iconClose());\n";
        echo "    actions.appendChild(btnExpandAll);\n";
        echo "    actions.appendChild(btnCollapseAll);\n";
        echo "    actions.appendChild(btnClose);\n";
        echo "    header.appendChild(actions);\n";

        echo "    var body=el('div','cpuck-toc-body');\n";
        echo "    var ul=el('ul','cpuck-toc-list');\n";

        echo "    items.forEach(function(it, idx){\n";
        echo "      var li=el('li','cpuck-toc-item cpuck-toc-lv-'+it.level);\n";
        echo "      li.setAttribute('data-index', String(idx));\n";
        echo "      li.setAttribute('data-level', String(it.level));\n";
        echo "      li.setAttribute('data-id', it.id);\n";
        echo "      var toggle=el('span','cpuck-toc-toggle'); toggle.appendChild(iconChevron()); li.appendChild(toggle);\n";
        echo "      var a=el('a','cpuck-toc-link'); a.href='#'+it.id; a.textContent=it.text; li.appendChild(a);\n";
        echo "      ul.appendChild(li);\n";
        echo "    });\n";

        echo "    body.appendChild(ul);\n";
        echo "    panel.appendChild(header);\n";
        echo "    panel.appendChild(body);\n";

        echo "    var fab=el('button','cpuck-toc-fab'); fab.type='button';\n";
        echo "    fab.appendChild(iconList());\n";
        echo "    fab.appendChild(el('span','cpuck-toc-fab-text','目录'));\n";

        echo "    document.body.appendChild(overlay);\n";
        echo "    document.body.appendChild(panel);\n";
        echo "    document.body.appendChild(fab);\n";

        // ====== 关键：PC 放到正文左侧空白区，避免遮挡 ======
        echo "    function findContentContainer(){\n";
        echo "      return closestAny(document.body, ['.entry-content','.post-content','article','.site-content','.content-area']) || document.body;\n";
        echo "    }\n";

        echo "    function computeLayout(){\n";
        echo "      var vw = window.innerWidth || document.documentElement.clientWidth;\n";
        echo "      var content = findContentContainer();\n";
        echo "      var rect = content.getBoundingClientRect ? content.getBoundingClientRect() : {left:0};\n";
        echo "      var panelW = panel.getBoundingClientRect().width || 320;\n";
        echo "      var gutter = CONFIG.gutter || 16;\n";
        echo "      var overlayMode = (vw <= CONFIG.overlayMaxW) || (rect.left < (panelW + gutter*2));\n";

        echo "      if(overlayMode){\n";
        echo "        panel.style.left = '0px';\n";
        echo "      }else{\n";
        echo "        var left = Math.floor(rect.left - panelW - gutter);\n";
        echo "        if(left < gutter) left = gutter;\n";
        echo "        panel.style.left = left + 'px';\n";
        echo "      }\n";
        echo "      panel.dataset.overlayMode = overlayMode ? '1' : '0';\n";

        // fab 左边也尽量贴正文左侧，不盖住正文
        echo "      var fabRect = fab.getBoundingClientRect();\n";
        echo "      var fabW = fabRect.width || 80;\n";
        echo "      var fabLeft = overlayMode ? 12 : Math.floor(rect.left - fabW - gutter);\n";
        echo "      if(fabLeft < 12) fabLeft = 12;\n";
        echo "      fab.style.left = fabLeft + 'px';\n";
        echo "    }\n";

        // ====== open / close（仅覆盖模式显示遮罩） ======
        echo "    function openPanel(){\n";
        echo "      computeLayout();\n";
        echo "      panel.classList.add('is-open');\n";
        echo "      panel.setAttribute('aria-hidden','false');\n";
        echo "      if(panel.dataset.overlayMode==='1') overlay.classList.add('is-open');\n";
        echo "      else overlay.classList.remove('is-open');\n";
        echo "    }\n";
        echo "    function closePanel(){\n";
        echo "      panel.classList.remove('is-open');\n";
        echo "      overlay.classList.remove('is-open');\n";
        echo "      panel.setAttribute('aria-hidden','true');\n";
        echo "    }\n";

        echo "    fab.addEventListener('click', function(){\n";
        echo "      if(panel.classList.contains('is-open')) closePanel(); else openPanel();\n";
        echo "    });\n";
        echo "    overlay.addEventListener('click', closePanel);\n";
        echo "    btnClose.addEventListener('click', closePanel);\n";
        echo "    document.addEventListener('keydown', function(e){ if(e.key==='Escape') closePanel(); });\n";

        // 监听 resize：打开状态下动态重新布局
        echo "    window.addEventListener('resize', function(){\n";
        echo "      computeLayout();\n";
        echo "      if(panel.classList.contains('is-open')){\n";
        echo "        if(panel.dataset.overlayMode==='1') overlay.classList.add('is-open'); else overlay.classList.remove('is-open');\n";
        echo "      }\n";
        echo "    });\n";
        echo "    setTimeout(computeLayout, 60);\n";

        // ====== smooth scroll + 点击后自动关闭（PC也关闭） ======
        echo "    ul.addEventListener('click', function(e){\n";
        echo "      var a=e.target && (e.target.closest ? e.target.closest('a.cpuck-toc-link') : null);\n";
        echo "      if(!a) return;\n";
        echo "      var id=a.getAttribute('href').slice(1);\n";
        echo "      var target=document.getElementById(id);\n";
        echo "      if(!target) return;\n";
        echo "      e.preventDefault();\n";
        echo "      var y = target.getBoundingClientRect().top + window.pageYOffset - 12;\n";
        echo "      window.scrollTo({top:y, behavior:'smooth'});\n";
        echo "      var isMobile = (window.matchMedia && window.matchMedia('(max-width:768px)').matches);\n";
        echo "      if(isMobile || CONFIG.autoCloseDesktop) closePanel();\n";
        echo "    });\n";

        // ====== 下拉折叠子标题：以 H2 为组 ======
        echo "    var lis = qsa('.cpuck-toc-item', ul);\n";
        echo "    function computeChildren(){\n";
        echo "      lis.forEach(function(li){\n";
        echo "        var t=li.querySelector('.cpuck-toc-toggle');\n";
        echo "        if(t) t.style.visibility='hidden';\n";
        echo "        li.classList.remove('has-children');\n";
        echo "        li.dataset.childStart=''; li.dataset.childEnd='';\n";
        echo "      });\n";
        echo "      for(var i=0;i<lis.length;i++){\n";
        echo "        var lv=parseInt(lis[i].dataset.level,10);\n";
        echo "        if(lv!==2) continue;\n";
        echo "        var start=i+1, end=i;\n";
        echo "        for(var j=start;j<lis.length;j++){\n";
        echo "          var lvj=parseInt(lis[j].dataset.level,10);\n";
        echo "          if(lvj<=2) break;\n";
        echo "          end=j;\n";
        echo "        }\n";
        echo "        if(end>=start){\n";
        echo "          lis[i].classList.add('has-children');\n";
        echo "          lis[i].dataset.childStart=String(start);\n";
        echo "          lis[i].dataset.childEnd=String(end);\n";
        echo "          var tg=lis[i].querySelector('.cpuck-toc-toggle');\n";
        echo "          if(tg) tg.style.visibility='visible';\n";
        echo "        }\n";
        echo "      }\n";
        echo "    }\n";
        echo "    computeChildren();\n";

        echo "    function setGroupCollapsed(h2Index, collapsed){\n";
        echo "      var li=lis[h2Index];\n";
        echo "      if(!li || !li.classList.contains('has-children')) return;\n";
        echo "      var s=parseInt(li.dataset.childStart,10);\n";
        echo "      var e=parseInt(li.dataset.childEnd,10);\n";
        echo "      if(isNaN(s)||isNaN(e)) return;\n";
        echo "      if(collapsed) li.classList.add('is-collapsed'); else li.classList.remove('is-collapsed');\n";
        echo "      for(var k=s;k<=e;k++){\n";
        echo "        if(collapsed) lis[k].classList.add('cpuck-toc-hidden'); else lis[k].classList.remove('cpuck-toc-hidden');\n";
        echo "      }\n";
        echo "    }\n";

        echo "    function collapseAllSub(){ for(var i=0;i<lis.length;i++){ if(parseInt(lis[i].dataset.level,10)===2) setGroupCollapsed(i,true); } }\n";
        echo "    function expandAllSub(){ for(var i=0;i<lis.length;i++){ if(parseInt(lis[i].dataset.level,10)===2) setGroupCollapsed(i,false); } }\n";
        echo "    if(CONFIG.defaultCollapseSub) collapseAllSub();\n";
        echo "    btnExpandAll.addEventListener('click', expandAllSub);\n";
        echo "    btnCollapseAll.addEventListener('click', collapseAllSub);\n";

        echo "    ul.addEventListener('click', function(e){\n";
        echo "      var tg=e.target && (e.target.closest ? e.target.closest('.cpuck-toc-toggle') : null);\n";
        echo "      if(!tg) return;\n";
        echo "      var li=tg.closest('.cpuck-toc-item');\n";
        echo "      if(!li) return;\n";
        echo "      var lv=parseInt(li.dataset.level,10);\n";
        echo "      if(lv!==2 || !li.classList.contains('has-children')) return;\n";
        echo "      e.preventDefault(); e.stopPropagation();\n";
        echo "      var idx=parseInt(li.dataset.index,10);\n";
        echo "      var collapsed=li.classList.contains('is-collapsed');\n";
        echo "      setGroupCollapsed(idx, !collapsed);\n";
        echo "    });\n";

        // ====== 当前章节高亮 ======
        echo "    var idToLi = new Map(); lis.forEach(function(li){ idToLi.set(li.dataset.id, li); });\n";
        echo "    function clearActive(){ lis.forEach(function(li){ li.classList.remove('is-active'); }); }\n";
        echo "    function setActive(id){\n";
        echo "      var li=idToLi.get(id); if(!li) return;\n";
        echo "      clearActive(); li.classList.add('is-active');\n";
        echo "      if(CONFIG.defaultCollapseSub){\n";
        echo "        var idx=parseInt(li.dataset.index,10);\n";
        echo "        for(var i=idx;i>=0;i--){ if(parseInt(lis[i].dataset.level,10)===2){ setGroupCollapsed(i,false); break; } }\n";
        echo "      }\n";
        echo "    }\n";

        echo "    var headings = items.map(function(it){ return document.getElementById(it.id); }).filter(Boolean);\n";
        echo "    if('IntersectionObserver' in window && headings.length){\n";
        echo "      var io = new IntersectionObserver(function(entries){\n";
        echo "        var visible = entries.filter(function(en){ return en.isIntersecting; });\n";
        echo "        if(!visible.length) return;\n";
        echo "        visible.sort(function(a,b){ return a.boundingClientRect.top - b.boundingClientRect.top; });\n";
        echo "        var id = visible[0].target.id; if(id) setActive(id);\n";
        echo "      }, {root:null, rootMargin: CONFIG.rootMargin, threshold:[0, 1]});\n";
        echo "      headings.forEach(function(h){ io.observe(h); });\n";
        echo "    }\n";

        echo "    if(CONFIG.defaultOpen) openPanel();\n";
        echo "  }\n";

        echo "  function boot(){\n";
        echo "    var dataEl = qs('.cpuck-toc-data[data-cpuck-toc-b64]');\n";
        echo "    if(!dataEl) return;\n";
        echo "    var b64 = dataEl.getAttribute('data-cpuck-toc-b64');\n";
        echo "    if(!b64) return;\n";
        echo "    var jsonText = decodeB64Unicode(b64);\n";
        echo "    if(!jsonText) return;\n";
        echo "    var items=null; try{ items = JSON.parse(jsonText); }catch(e){ return; }\n";
        echo "    if(!items || !items.length) return;\n";
        echo "    buildUI(items);\n";
        echo "  }\n";

        echo "  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', boot); else boot();\n";
        echo "})();</script>\n";
    }
}

Cpuck_Floating_TOC::init();
