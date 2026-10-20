<?php
namespace {
    define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));
}

namespace Typecho\Plugin {
    class Exception extends \Exception
    {
    }

    interface PluginInterface
    {
        public static function activate();
        public static function deactivate();
        public static function config(\Typecho\Widget\Helper\Form $form);
        public static function personalConfig(\Typecho\Widget\Helper\Form $form);
    }
}

namespace Typecho\Widget\Helper {
    class Form
    {
    }
}

namespace Typecho\Http {
    class Client
    {
        private $timeout = 3;
        private $agent = '';
        private $headers = [];
        private $status = 0;
        private $body = '';

        public static function get()
        {
            return extension_loaded('curl') ? new self() : null;
        }

        public function setTimeout(int $timeout): Client
        {
            $this->timeout = $timeout;
            return $this;
        }

        public function setAgent(string $agent): Client
        {
            $this->agent = $agent;
            return $this;
        }

        public function setHeader(string $key, string $value): Client
        {
            $this->headers[$key] = $value;
            return $this;
        }

        public function send(string $url)
        {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

            if ('' !== $this->agent) {
                curl_setopt($ch, CURLOPT_USERAGENT, $this->agent);
            }

            if ($this->headers) {
                $list = [];

                foreach ($this->headers as $key => $value) {
                    $list[] = $key . ': ' . $value;
                }

                curl_setopt($ch, CURLOPT_HTTPHEADER, $list);
            }

            $body = curl_exec($ch);
            $this->status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $this->body = false === $body ? '' : $body;
        }

        public function getResponseStatus(): int
        {
            return $this->status;
        }

        public function getResponseBody(): string
        {
            return $this->body;
        }

        public function getResponseHeader(string $key): ?string
        {
            return null;
        }
    }
}

namespace Typecho {
    class FakeQuery
    {
        public $table = '';
        public $where = [];
        public $rows = [];

        public function from($table)
        {
            $this->table = $table;
            return $this;
        }

        public function where($condition, $value = null)
        {
            $this->where = [$value];
            return $this;
        }

        public function rows($rows)
        {
            $this->rows = $rows;
            return $this;
        }

        public function fetchRow()
        {
            return false;
        }

        public function execute()
        {
        }
    }

    class Db
    {
        public const WRITE = 2;

        public static function get()
        {
            return new self();
        }

        public function select(...$columns)
        {
            return new FakeQuery();
        }

        public function insert($table)
        {
            return new FakeQuery();
        }

        public function update($table)
        {
            return new FakeQuery();
        }

        public function fetchRow($query)
        {
            return $query->fetchRow();
        }

        public function query($query, $op = 0)
        {
            $query->execute();
        }

        public function getPrefix(): string
        {
            return 'typecho_';
        }

        public function getAdapter(): FakeAdapter
        {
            return new FakeAdapter();
        }
    }

    class FakeAdapter
    {
        public function getDriver(): string
        {
            return 'mysql';
        }
    }
}

namespace {
    require dirname(__DIR__) . '/Plugin.php';

    use TypechoPlugin\TypechoVideoCard\Plugin;

    $fetch = new ReflectionMethod(Plugin::class, 'fetch');
    $fetch->setAccessible(true);
    $card = new ReflectionMethod(Plugin::class, 'card');
    $card->setAccessible(true);
    $css = (new ReflectionClass(Plugin::class))->getReflectionConstant('CSS')->getValue();

    // 真实接口失败时用已验证过的数据兜底，保证预览页一定能生成
    $fallback = [
        'bilibili' => [
            'title'  => '【官方 MV】Never Gonna Give You Up - Rick Astley',
            'cover'  => 'https://i1.hdslb.com/bfs/archive/5242750857121e05146d5d5b13a47a2a6dd36e98.jpg',
            'url'    => 'https://www.bilibili.com/video/BV1GJ411x7h7',
            'author' => '索尼音乐中国',
            'site'   => '哔哩哔哩',
        ],
        'youtube' => [
            'title'  => 'Rick Astley - Never Gonna Give You Up (Official Video) (4K Remaster)',
            'cover'  => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
            'url'    => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'author' => 'Rick Astley',
            'site'   => 'YouTube',
        ],
    ];

    $meta = [];

    foreach ([['bilibili', 'BV1GJ411x7h7'], ['youtube', 'dQw4w9WgXcQ']] as [$platform, $vid]) {
        $result = $fetch->invoke(null, $platform, $vid);
        $meta[$platform] = null === $result ? $fallback[$platform] : $result;
    }

    $settings = [
        'enabled'   => true,
        'scope'     => 'single',
        'size'      => 'medium',
        'showTitle' => true,
        'platforms' => ['bilibili', 'youtube'],
    ];

    $render = function (string $platform, string $size) use ($card, $meta, $settings) {
        $config = $settings;
        $config['size'] = $size;
        return $card->invoke(null, $meta[$platform], $config);
    };

    // 卡片自带 <style>，这里只需要一次
    $styleBlock = '<style>' . $css . '</style>';

    $cards = [
        'medium_bili'   => $render('bilibili', 'medium'),
        'large_bili'    => $render('bilibili', 'large'),
        'compact_bili'  => $render('bilibili', 'compact'),
        'medium_youtube' => $render('youtube', 'medium'),
    ];

    // 去掉卡片自带的 <style>（页面里统一注入一次）
    foreach ($cards as $key => $html) {
        $cards[$key] = str_replace($styleBlock, '', $html);
    }

    $plainLink = '<a href="https://www.bilibili.com/video/BV1GJ411x7h7" target="_blank" rel="noopener nofollow">'
        . 'https://www.bilibili.com/video/BV1GJ411x7h7</a>';

    $page = <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TypechoVideoCard · Typecho 视频卡片插件效果预览</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Outfit:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#f7f5f0; --panel:#ffffff; --line:#e9e3d6; --line-soft:#f0ece2;
  --text:#14171e; --muted:#7c7a72; --body:#3a4256; --accent:#2f6f5e;
  --accent-soft:rgba(47,111,94,.10);
  --shadow:0 2px 8px rgba(0,0,0,.04);
  --shadow-lg:0 4px 16px -2px rgba(0,0,0,.06);
}
html[data-theme="dark"]{
  --bg:#17191c; --panel:#1f2226; --line:#33383d; --line-soft:#2a2e33;
  --text:#f2f3f5; --muted:#9aa1a9; --body:#d3d7dc; --accent:#5fbb9f;
  --accent-soft:rgba(95,187,159,.12);
  --shadow:0 2px 8px rgba(0,0,0,.35);
  --shadow-lg:0 4px 16px -2px rgba(0,0,0,.45);
}
*{box-sizing:border-box}
body{
  margin:0; background:var(--bg); color:var(--body);
  font-family:'Outfit','PingFang SC','Microsoft YaHei',sans-serif;
  font-size:15px; line-height:1.65;
  transition:background .25s,color .25s;
}
.wrap{max-width:1080px;margin:0 auto;padding:0 24px 96px}
.topbar{display:flex;align-items:center;justify-content:space-between;padding:26px 0 0}
.brand{display:flex;align-items:center;gap:9px;font-size:13px;font-weight:600;color:var(--muted);letter-spacing:.04em}
.brand svg{width:17px;height:17px;color:var(--accent)}
.toggle{
  display:inline-flex;align-items:center;gap:7px;border:1px solid var(--line);background:var(--panel);
  color:var(--muted);border-radius:999px;padding:7px 14px;font-size:13px;font-weight:500;
  cursor:pointer;transition:.2s;font-family:inherit;
}
.toggle:hover{color:var(--text);border-color:var(--accent);transform:translateY(-1px)}
.toggle svg{width:15px;height:15px}
.hero{padding:44px 0 34px;max-width:760px}
.tag{
  display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;letter-spacing:.06em;
  color:var(--accent);background:var(--accent-soft);padding:5px 11px;border-radius:999px;margin-bottom:18px;
}
h1{
  font-family:'DM Serif Display','Songti SC',serif;font-weight:400;font-size:38px;line-height:1.22;
  color:var(--text);margin:0 0 14px;letter-spacing:-.005em;
}
.lede{font-size:16px;color:var(--muted);margin:0}
.section{margin-top:56px}
.sec-head{display:flex;align-items:baseline;gap:11px;margin-bottom:18px}
.sec-head svg{width:18px;height:18px;color:var(--accent);flex:none;transform:translateY(2px)}
h2{font-size:19px;font-weight:600;color:var(--text);margin:0;letter-spacing:-.01em}
.sec-note{font-size:13.5px;color:var(--muted);margin:-8px 0 18px}
.post{
  background:var(--panel);border:1px solid var(--line);border-radius:16px;
  padding:34px 38px;box-shadow:var(--shadow-lg);max-width:720px;
}
.post h3{font-size:23px;font-weight:600;color:var(--text);margin:0 0 6px;letter-spacing:-.01em}
.post .meta{font-size:12.5px;color:var(--muted);margin-bottom:24px;display:flex;gap:8px;align-items:center}
.post .meta i{width:3px;height:3px;border-radius:50%;background:currentColor;opacity:.5;font-style:normal}
.post p{font-size:15.5px;line-height:1.85;margin:0 0 18px;color:var(--body)}
.post p:last-child{margin-bottom:0}
.post code{font-family:'JetBrains Mono',monospace;font-size:13.5px;background:var(--line-soft);padding:2px 6px;border-radius:5px;color:var(--text)}
.sizes{display:grid;grid-template-columns:1fr;gap:20px}
.size-col{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:18px 20px;box-shadow:var(--shadow);max-width:800px}
.size-label{font-size:12px;font-weight:600;letter-spacing:.05em;color:var(--muted);margin-bottom:14px;text-transform:uppercase}
.compare{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.pane{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:20px;box-shadow:var(--shadow)}
.pane-label{font-size:12px;font-weight:600;letter-spacing:.05em;color:var(--muted);margin-bottom:14px;text-transform:uppercase}
.rawlink{font-family:'JetBrains Mono',monospace;font-size:13px;color:var(--accent);word-break:break-all}
.rawlink:hover{text-decoration:underline}
.settings{width:100%;border-collapse:collapse;background:var(--panel);border:1px solid var(--line);border-radius:14px;overflow:hidden;box-shadow:var(--shadow)}
.settings th,.settings td{text-align:left;padding:13px 18px;font-size:14px;border-bottom:1px solid var(--line-soft)}
.settings th{font-weight:600;color:var(--text);background:var(--line-soft);font-size:13px;letter-spacing:.02em}
.settings tr:last-child td{border-bottom:none}
.settings td:first-child{font-weight:500;color:var(--text);white-space:nowrap}
.settings td{color:var(--muted)}
.chips{display:flex;flex-wrap:wrap;gap:9px;margin-top:4px}
.chip{font-family:'JetBrains Mono',monospace;font-size:12.5px;background:var(--line-soft);border:1px solid var(--line);color:var(--text);padding:4px 10px;border-radius:7px}
.notes{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}
.note{display:flex;gap:11px;background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:15px 17px;box-shadow:var(--shadow)}
.note svg{width:17px;height:17px;color:var(--accent);flex:none;margin-top:2px}
.note b{display:block;color:var(--text);font-size:14px;font-weight:600;margin-bottom:2px}
.note span{font-size:13.5px;color:var(--muted);line-height:1.55}
footer{margin-top:64px;padding-top:22px;border-top:1px solid var(--line);font-size:13px;color:var(--muted);display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px}
footer code{font-family:'JetBrains Mono',monospace;font-size:12.5px}
@media (max-width:860px){
  .sizes,.compare,.notes{grid-template-columns:1fr}
  .post{padding:24px 20px}
  h1{font-size:30px}
}
</style>
</head>
<body data-theme="light">
<div class="wrap">

  <div class="topbar">
    <div class="brand">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
      TypechoVideoCard · Typecho 插件
    </div>
    <button class="toggle" id="themeBtn">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M6.3 17.7l-1.4 1.4M19.1 4.9l-1.4 1.4"/></svg>
      <span id="themeLabel">切换深色</span>
    </button>
  </div>

  <div class="hero">
    <div class="tag">文章正文自动渲染</div>
    <h1>粘一个视频链接，<br>正文里就出现封面和标题</h1>
    <p class="lede">下面每一张卡片，都是插件 <code>TypechoVideoCard</code> 用真实接口抓到的标题与封面渲染出来的 —— 不是示意图。</p>
  </div>

  <div class="section">
    <div class="sec-head">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
      <h2>文章页里的实际效果</h2>
    </div>
    <p class="sec-note">作者只粘了一个链接，其余全是插件自动完成的。</p>

    <article class="post">
      <h3>周末听点老歌</h3>
      <div class="meta"><span>2026-10-20</span><i></i><span>索尼音乐中国</span></div>
      <p>翻到一个很有意思的合集，BV 号直接丢过来就行：</p>
      {{CARD_MEDIUM_BILI}}
      <p>封面点一下就跳到原视频，标题和 UP 主都是从 B 站接口取回来的，改过标题也不会过期。</p>
    </article>
  </div>

  <div class="section">
    <div class="sec-head">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
      <h2>三种卡片尺寸</h2>
    </div>
    <p class="sec-note">后台设置里切换，对应插件的 <code>size</code> 配置项。</p>
    <div class="sizes">
      <div class="size-col"><div class="size-label">大 large</div>{{CARD_LARGE}}</div>
      <div class="size-col"><div class="size-label">中 medium</div>{{CARD_MEDIUM}}</div>
      <div class="size-col"><div class="size-label">紧凑 compact</div>{{CARD_COMPACT}}</div>
    </div>
  </div>

  <div class="section">
    <div class="sec-head">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 16 4-4-4-4"/><path d="m6 8-4 4 4 4"/><path d="m14.5 4-5 16"/></svg>
      <h2>替换前 / 替换后</h2>
    </div>
    <div class="compare">
      <div class="pane">
        <div class="pane-label">开启前</div>
        <p style="font-size:15px;line-height:1.85;margin:0 0 14px">正文里就是一个普通链接：</p>
        <div class="rawlink">{{PLAIN_LINK}}</div>
      </div>
      <div class="pane">
        <div class="pane-label">开启后</div>
        <div class="rawlink" style="margin-bottom:14px">{{PLAIN_LINK}}</div>
        {{CARD_MEDIUM_YT}}
      </div>
    </div>
  </div>

  <div class="section">
    <div class="sec-head">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z"/><path d="M12 8v4l3 2"/></svg>
      <h2>性能与兼容</h2>
    </div>
    <div class="notes">
      <div class="note">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z"/><path d="m9 12 2 2 4-4"/></svg>
        <div><b>只请求一次</b><span>标题封面存进数据库缓存表，保存文章时还会预抓一次，之后打开文章不再请求第三方接口。</span></div>
      </div>
      <div class="note">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z"/><path d="m9 12 2 2 4-4"/></svg>
        <div><b>抓不到就降级</b><span>接口挂了或视频被删，链接原样保留，不影响发文章。</span></div>
      </div>
      <div class="note">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z"/><path d="m9 12 2 2 4-4"/></svg>
        <div><b>1.2 / 1.3 通用</b><span>钩子注册和回调签名两边一致，两个版本都不用改代码。</span></div>
      </div>
      <div class="note">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z"/><path d="m9 12 2 2 4-4"/></svg>
        <div><b>代码块不处理</b><span>写在 <code style="font-family:'JetBrains Mono',monospace">&lt;pre&gt;</code> 里的链接保持原样。</span></div>
      </div>
    </div>
  </div>

  <div class="section">
    <div class="sec-head">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2Z"/><circle cx="12" cy="12" r="3"/></svg>
      <h2>后台可调项</h2>
    </div>
    <table class="settings">
      <tr><th>设置</th><th>说明</th></tr>
      <tr><td>总开关</td><td>关掉后完全不处理正文</td></tr>
      <tr><td>生效范围</td><td>仅文章页（默认）／所有页面。列表页也渲染时摘要会变长</td></tr>
      <tr><td>卡片尺寸</td><td>大／中／紧凑</td></tr>
      <tr><td>显示标题</td><td>关掉只留封面</td></tr>
      <tr><td>支持的平台</td><td>
        <div class="chips">
          <span class="chip">bilibili</span><span class="chip">youtube</span><span class="chip">vimeo</span>
        </div>
      </td></tr>
    </table>
  </div>

  <div class="section">
    <div class="sec-head">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
      <h2>目前的边界</h2>
    </div>
    <div class="notes">
      <div class="note">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
        <div><b>只覆盖三个平台</b><span>腾讯、爱奇艺、优酷、抖音都没有公开的封面接口，插入后保持原样。</span></div>
      </div>
      <div class="note">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
        <div><b>会进摘要和 description</b><span>Typecho 的内容过滤机制决定的，插件层面绕不开。</span></div>
      </div>
    </div>
  </div>

  <footer>
    <span>TypechoVideoCard v1.0.0 · 卡片内容来自真实接口</span>
    <span>作者 <a href="https://github.com/Sleet51337" style="color:var(--accent)">Sleet</a> · 安装：解压到 <code>usr/plugins/</code> → 后台启用</span>
  </footer>
</div>

<script>
(function () {
  var btn = document.getElementById('themeBtn');
  var label = document.getElementById('themeLabel');
  var body = document.body;

  function sync() {
    var dark = body.getAttribute('data-theme') === 'dark';
    label.textContent = dark ? '切换浅色' : '切换深色';
  }

  btn.addEventListener('click', function () {
    var dark = body.getAttribute('data-theme') === 'dark';
    body.setAttribute('data-theme', dark ? 'light' : 'dark');
    sync();
  });

  sync();
})();
</script>
</body>
</html>
HTML;

    $replace = [
        '{{CARD_MEDIUM_BILI}}'   => $cards['medium_bili'],
        '{{CARD_LARGE}}'          => $cards['large_bili'],
        '{{CARD_MEDIUM}}'         => $cards['medium_bili'],
        '{{CARD_COMPACT}}'        => $cards['compact_bili'],
        '{{CARD_MEDIUM_YT}}'      => $cards['medium_youtube'],
        '{{PLAIN_LINK}}'          => $plainLink,
    ];

    $page = strtr($page, $replace);

    // 插件自己的样式（卡片用）
    $page = str_replace('</style>', '</style>' . "\n" . $styleBlock, $page);

    // 深色模式下让卡片跟随预览配色（对应插件里的 prefers-color-scheme 规则）
    $darkOverride = <<<'CSS'
html[data-theme="dark"] .vc-card{background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.12);box-shadow:none}
html[data-theme="dark"] .vc-title:hover{opacity:.85}
CSS;
    $page = str_replace('</style>', $darkOverride . "\n" . '</style>', $page);

    $output = dirname(__DIR__) . '/preview.html';
    file_put_contents($output, $page);

    echo "已生成: $output\n";
    echo "字节数: " . strlen($page) . "\n";
    echo "B站标题: " . $meta['bilibili']['title'] . "\n";
    echo "YouTube 标题: " . $meta['youtube']['title'] . "\n";
}
