<?php
/**
 * 视频卡片
 *
 * 在文章里插入哔哩哔哩 / YouTube / Vimeo 的视频链接，自动渲染成带封面和标题的卡片，
 * 点击封面跳转原视频。视频信息（标题、封面）抓取一次后缓存到数据库，不再重复请求。
 *
 * @package TypechoVideoCard
 * @author Sleet
 * @version 1.0.0
 * @since 1.2.0
 * @link https://github.com/Sleet51337
 */

namespace TypechoPlugin\TypechoVideoCard;

use Typecho\Db;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Typecho\Widget\Helper\Form\Element\Radio;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Plugin implements PluginInterface
{
    /** 缓存表名（不含前缀） */
    private const TABLE = 'videocard';

    /** 支持的平台 */
    private const PLATFORMS = [
        'bilibili' => '哔哩哔哩',
        'youtube'  => 'YouTube',
        'vimeo'    => 'Vimeo',
    ];

    /** 请求超时（秒） */
    private const TIMEOUT = 5;

    /** 单个请求的尝试次数 */
    private const RETRY = 3;

    private const AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    /**
     * 启用插件：注册钩子、建缓存表
     */
    public static function activate()
    {
        // 正文过滤：B 站 / YouTube / Vimeo 链接 → 卡片
        \Typecho\Plugin::factory('Widget\Base\Contents')->contentEx = __CLASS__ . '::filterContent';

        // 保存文章时顺手把视频信息抓下来，前台就不用等网络请求
        \Typecho\Plugin::factory('Widget\Contents\Post\Edit')->write = __CLASS__ . '::onWrite';
        \Typecho\Plugin::factory('Widget\Contents\Page\Edit')->write = __CLASS__ . '::onWrite';

        self::install();

        return _t('视频卡片已启用，在文章中粘贴视频链接即可显示封面和标题');
    }

    /**
     * 禁用插件。钩子由 Typecho 自动摘除，缓存表保留（可自行删除）
     */
    public static function deactivate()
    {
        return _t('视频卡片已禁用');
    }

    /**
     * 插件设置
     */
    public static function config(Form $form)
    {
        $form->addInput(new Checkbox(
            'enabled',
            ['1' => _t('启用视频卡片')],
            ['1'],
            _t('总开关')
        ));

        $form->addInput(new Radio(
            'scope',
            [
                'single' => _t('仅文章页'),
                'all'    => _t('所有页面'),
            ],
            'single',
            _t('生效范围')
        ));

        $form->addInput(new Radio(
            'size',
            [
                'large'  => _t('大'),
                'medium' => _t('中'),
                'compact' => _t('紧凑'),
            ],
            'medium',
            _t('卡片尺寸')
        ));

        $form->addInput(new Checkbox(
            'showTitle',
            ['1' => _t('显示标题')],
            ['1'],
            _t('标题')
        ));

        $form->addInput(new Checkbox(
            'platforms',
            self::PLATFORMS,
            ['bilibili', 'youtube'],
            _t('支持的平台')
        ));
    }

    /**
     * 个人设置（本插件用不到）
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 正文过滤：把视频链接换成卡片
     *
     * @param string $content 正文 HTML
     * @param mixed  $widget  内容组件
     */
    public static function filterContent($content, $widget = null)
    {
        $settings = self::settings();

        if (!$settings['enabled']) {
            return $content;
        }

        if ('all' !== $settings['scope'] && !self::isSingle($widget)) {
            return $content;
        }

        // RSS/feed 里不输出卡片
        if (self::isFeed($widget)) {
            return $content;
        }

        // 代码块里的链接不动
        $protected = [];
        $content = preg_replace_callback(
            '/<(pre|code)\b[^>]*>.*?<\/\1>/is',
            function ($matches) use (&$protected) {
                $key = "\x00vc" . count($protected) . "\x00";
                $protected[$key] = $matches[0];
                return $key;
            },
            $content
        );

        // <a href="视频链接">文字</a>
        $content = preg_replace_callback(
            '/<a\s[^>]*href=("|\')(https?:\/\/[^"\']+)\1[^>]*>.*?<\/a>/is',
            function ($matches) use ($settings) {
                return self::replace($matches[2], $matches[0], $settings);
            },
            $content
        );

        // 裸露的链接。排除引号和等号，避免误伤属性里的 URL；不能排除 ">"，
        // 因为 Typecho 不会把裸 URL 自动转成超链接，正文里常见 "<p>https://..." 这种形式
        $content = preg_replace_callback(
            '/(?<!["\'=\w\/])(https?:\/\/[^\s<>"\']+)/u',
            function ($matches) use ($settings) {
                return self::replace($matches[1], $matches[1], $settings);
            },
            $content
        );

        foreach ($protected as $key => $html) {
            $content = str_replace($key, $html, $content);
        }

        return $content;
    }

    /**
     * 保存文章时预抓视频信息
     *
     * @param array $contents 文章字段
     * @param mixed $widget   编辑组件
     */
    public static function onWrite($contents, $widget = null)
    {
        $settings = self::settings();

        if (!$settings['enabled'] || !is_array($contents)) {
            return $contents;
        }

        $text = '';

        foreach (['text', 'content'] as $key) {
            if (isset($contents[$key]) && is_string($contents[$key])) {
                $text .= ' ' . $contents[$key];
            }
        }

        if ('' === trim($text)) {
            return $contents;
        }

        if (preg_match_all('#https?://[^\s<>"\']+#i', $text, $matches)) {
            foreach ($matches[0] as $url) {
                $info = self::parse($url);

                if (null === $info || !in_array($info['platform'], $settings['platforms'], true)) {
                    continue;
                }

                self::meta($info['platform'], $info['vid']);
            }
        }

        return $contents;
    }

    /**
     * 读取插件设置
     */
    private static function settings(): array
    {
        $default = [
            'enabled'   => false,
            'scope'     => 'single',
            'size'      => 'medium',
            'showTitle' => true,
            'platforms' => ['bilibili', 'youtube'],
        ];

        try {
            $config = Options::alloc()->plugin('TypechoVideoCard');
        } catch (\Throwable $e) {
            return $default;
        }

        if (null === $config) {
            return $default;
        }

        $platforms = $config->platforms;

        return [
            'enabled'   => (bool) $config->enabled,
            'scope'     => $config->scope ?: 'single',
            'size'      => $config->size ?: 'medium',
            'showTitle' => null === $config->showTitle ? true : (bool) $config->showTitle,
            'platforms' => is_array($platforms) ? $platforms : [],
        ];
    }

    /**
     * 是否文章页
     */
    private static function isSingle($widget): bool
    {
        return is_object($widget) && method_exists($widget, 'is') && $widget->is('single');
    }

    /**
     * 是否订阅源
     */
    private static function isFeed($widget): bool
    {
        return is_object($widget) && method_exists($widget, 'is') && $widget->is('feed');
    }

    /**
     * 把一个链接换成卡片；不是视频链接或抓取失败就原样返回
     */
    private static function replace(string $url, string $original, array $settings): string
    {
        $info = self::parse($url);

        if (null === $info || !in_array($info['platform'], $settings['platforms'], true)) {
            return $original;
        }

        $meta = self::meta($info['platform'], $info['vid']);

        if (null === $meta) {
            return $original;
        }

        return self::card($meta, $settings);
    }

    /**
     * 解析视频链接
     *
     * @return array|null ['platform' => ..., 'vid' => ...]
     */
    private static function parse(string $url): ?array
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES, 'UTF-8');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = (string) preg_replace('/^www\./', '', $host);
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = (string) parse_url($url, PHP_URL_QUERY);

        // 短链：展开后再解析
        if ('b23.tv' === $host) {
            $expanded = self::expand($url);
            return null === $expanded ? null : self::parse($expanded);
        }

        if (in_array($host, ['bilibili.com', 'm.bilibili.com', 'space.bilibili.com'], true)) {
            if (preg_match('#/video/(BV[0-9A-Za-z]{10})#i', $path, $matches)) {
                return ['platform' => 'bilibili', 'vid' => $matches[1]];
            }

            if (preg_match('#/video/av(\d+)#i', $path, $matches)) {
                return ['platform' => 'bilibili', 'vid' => 'av' . $matches[1]];
            }
        }

        if (in_array($host, ['youtube.com', 'm.youtube.com', 'youtube-nocookie.com'], true)) {
            if (preg_match('#/(?:embed|shorts|live|v)/([0-9A-Za-z_-]{6,})#', $path, $matches)) {
                return ['platform' => 'youtube', 'vid' => $matches[1]];
            }

            if (preg_match('/(?:^|&)v=([0-9A-Za-z_-]{6,})/', $query, $matches)) {
                return ['platform' => 'youtube', 'vid' => $matches[1]];
            }
        }

        if ('youtu.be' === $host) {
            if (preg_match('#^/([0-9A-Za-z_-]{6,})#', $path, $matches)) {
                return ['platform' => 'youtube', 'vid' => $matches[1]];
            }
        }

        if (in_array($host, ['vimeo.com', 'player.vimeo.com'], true)) {
            if (preg_match('#/(?:video/)?(\d+)#', $path, $matches)) {
                return ['platform' => 'vimeo', 'vid' => $matches[1]];
            }
        }

        return null;
    }

    /**
     * 取视频信息：先查缓存，再走接口
     */
    private static function meta(string $platform, string $vid): ?array
    {
        $key = $platform . ':' . $vid;
        $cached = self::cacheGet($key);

        if (null !== $cached) {
            return $cached;
        }

        $data = self::fetch($platform, $vid);

        if (null === $data) {
            return null;
        }

        self::cacheSet($key, $data);

        return $data;
    }

    /**
     * 调平台接口取标题和封面
     */
    private static function fetch(string $platform, string $vid): ?array
    {
        switch ($platform) {
            case 'bilibili':
                return self::fetchBilibili($vid);

            case 'youtube':
                $body = self::request(
                    'https://www.youtube.com/oembed?url='
                    . rawurlencode('https://www.youtube.com/watch?v=' . $vid) . '&format=json'
                );

                $json = json_decode((string) $body, true);

                if (!is_array($json) || empty($json['title']) || empty($json['thumbnail_url'])) {
                    return null;
                }

                return [
                    'title'  => (string) $json['title'],
                    'cover'  => (string) $json['thumbnail_url'],
                    'url'    => 'https://www.youtube.com/watch?v=' . $vid,
                    'author' => (string) ($json['author_name'] ?? ''),
                    'site'   => 'YouTube',
                ];

            case 'vimeo':
                $body = self::request(
                    'https://vimeo.com/api/oembed.json?url=' . rawurlencode('https://vimeo.com/' . $vid)
                );

                $json = json_decode((string) $body, true);

                if (!is_array($json) || empty($json['title']) || empty($json['thumbnail_url'])) {
                    return null;
                }

                return [
                    'title'  => (string) $json['title'],
                    'cover'  => (string) $json['thumbnail_url'],
                    'url'    => 'https://vimeo.com/' . $vid,
                    'author' => (string) ($json['author_name'] ?? ''),
                    'site'   => 'Vimeo',
                ];
        }

        return null;
    }

    /**
     * 哔哩哔哩元数据
     *
     * view 接口一次就能拿到标题、封面和 UP 主，但在部分机房 IP 上会被风控；
     * 失败后退回到「分P接口取标题 + 搜索接口按 BV 号精确匹配封面」这条链路，
     * 它要发两个请求，所以每个环节都带重试。
     */
    private static function fetchBilibili(string $vid): ?array
    {
        $query = 0 === stripos($vid, 'av') ? 'aid=' . substr($vid, 2) : 'bvid=' . $vid;
        $headers = ['Referer' => 'https://www.bilibili.com/'];

        $body = self::request('https://api.bilibili.com/x/web-interface/view?' . $query, $headers, 1);
        $json = json_decode((string) $body, true);

        if (is_array($json) && 0 === ($json['code'] ?? -1) && !empty($json['data']['title'])) {
            $cover = (string) ($json['data']['pic'] ?? '');

            if ('' !== $cover) {
                return [
                    'title'  => (string) $json['data']['title'],
                    'cover'  => self::normalizeCover($cover),
                    'url'    => 'https://www.bilibili.com/video/' . (string) ($json['data']['bvid'] ?? $vid),
                    'author' => (string) ($json['data']['owner']['name'] ?? ''),
                    'site'   => '哔哩哔哩',
                ];
            }
        }

        $part = self::bilibiliPart($query, $headers);

        return null === $part ? null : self::bilibiliSearch($vid, $part, $headers);
    }

    /**
     * 取分P标题，用作搜索关键词
     */
    private static function bilibiliPart(string $query, array $headers): ?string
    {
        $body = self::request('https://api.bilibili.com/x/player/pagelist?' . $query, $headers);
        $json = json_decode((string) $body, true);

        if (!is_array($json) || 0 !== ($json['code'] ?? -1) || empty($json['data'][0]['part'])) {
            return null;
        }

        return (string) $json['data'][0]['part'];
    }

    /**
     * 搜索接口按 BV 号精确匹配封面
     */
    private static function bilibiliSearch(string $vid, string $keyword, array $headers): ?array
    {
        $isAid = 0 === stripos($vid, 'av');
        $aid = $isAid ? substr($vid, 2) : '';

        for ($page = 1; $page <= 2; $page++) {
            $body = self::request(
                'https://api.bilibili.com/x/web-interface/search/type?search_type=video&keyword='
                . rawurlencode($keyword) . '&page=' . $page,
                $headers
            );

            $json = json_decode((string) $body, true);

            foreach (($json['data']['result'] ?? []) as $item) {
                $match = $isAid
                    ? ((string) ($item['aid'] ?? '') === $aid)
                    : ((string) ($item['bvid'] ?? '') === $vid);

                if (!$match || empty($item['pic'])) {
                    continue;
                }

                $title = trim(html_entity_decode(strip_tags((string) ($item['title'] ?? $keyword)), ENT_QUOTES, 'UTF-8'));

                return [
                    'title'  => '' === $title ? $keyword : $title,
                    'cover'  => self::normalizeCover((string) $item['pic']),
                    'url'    => 'https://www.bilibili.com/video/' . (string) ($item['bvid'] ?? $vid),
                    'author' => (string) ($item['author'] ?? ''),
                    'site'   => '哔哩哔哩',
                ];
            }
        }

        return null;
    }

    /**
     * 封面地址统一成 https
     */
    private static function normalizeCover(string $cover): string
    {
        if (0 === strpos($cover, '//')) {
            $cover = 'https:' . $cover;
        }

        return (string) preg_replace('#^http://#', 'https://', $cover);
    }

    /**
     * HTTP GET。B 站部分接口对机房 IP 有概率性风控（同样的请求有时成有时败），
     * 所以失败要重试，命中缓存后就不会再走到这里。
     *
     * @param int $attempts 总尝试次数
     */
    private static function request(string $url, array $headers = [], int $attempts = self::RETRY): ?string
    {
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($attempt > 0) {
                usleep(300000 + random_int(0, 300000));
            }

            $body = self::doRequest($url, $headers);

            if (null !== $body) {
                return $body;
            }
        }

        return null;
    }

    /**
     * 单次请求
     */
    private static function doRequest(string $url, array $headers = []): ?string
    {
        $client = \Typecho\Http\Client::get();

        if (null === $client) {
            return null;
        }

        try {
            $client->setTimeout(self::TIMEOUT)->setAgent(self::AGENT);

            foreach ($headers as $key => $value) {
                $client->setHeader($key, $value);
            }

            $client->send($url);
        } catch (\Throwable $e) {
            return null;
        }

        if (200 !== $client->getResponseStatus()) {
            return null;
        }

        return $client->getResponseBody();
    }

    /**
     * 展开短链
     */
    private static function expand(string $url): ?string
    {
        $client = \Typecho\Http\Client::get();

        if (null === $client) {
            return null;
        }

        try {
            $client->setTimeout(self::TIMEOUT)->setAgent(self::AGENT);
            $client->send($url);
        } catch (\Throwable $e) {
            return null;
        }

        $location = $client->getResponseHeader('location');

        if (empty($location)) {
            return null;
        }

        if (0 === strpos($location, '/')) {
            $location = 'https://www.bilibili.com' . $location;
        }

        return $location;
    }

    /**
     * 读缓存
     */
    private static function cacheGet(string $key): ?array
    {
        try {
            $db = Db::get();
            $row = $db->fetchRow($db->select()->from('table.' . self::TABLE)->where('vid = ?', $key));
        } catch (\Throwable $e) {
            return null;
        }

        if (empty($row['data'])) {
            return null;
        }

        $data = json_decode((string) $row['data'], true);

        return is_array($data) ? $data : null;
    }

    /**
     * 写缓存
     */
    private static function cacheSet(string $key, array $data): void
    {
        try {
            $db = Db::get();
            $exists = $db->fetchRow(
                $db->select('vid')->from('table.' . self::TABLE)->where('vid = ?', $key)
            );

            $rows = ['data' => json_encode($data), 'created' => time()];

            if ($exists) {
                $db->query(
                    $db->update('table.' . self::TABLE)->rows($rows)->where('vid = ?', $key),
                    Db::WRITE
                );
            } else {
                $db->query(
                    $db->insert('table.' . self::TABLE)->rows(array_merge(['vid' => $key], $rows)),
                    Db::WRITE
                );
            }
        } catch (\Throwable $e) {
            // 缓存写失败不影响出图
        }
    }

    /**
     * 建缓存表
     */
    private static function install(): void
    {
        $db = Db::get();
        $table = $db->getPrefix() . self::TABLE;

        switch ($db->getAdapter()->getDriver()) {
            case 'pgsql':
                $db->query(
                    "CREATE TABLE IF NOT EXISTS \"{$table}\" (
                        \"vid\" VARCHAR(64) NOT NULL,
                        \"data\" TEXT NULL,
                        \"created\" INT NOT NULL DEFAULT 0,
                        PRIMARY KEY (\"vid\")
                    )",
                    Db::WRITE
                );
                break;

            case 'sqlite':
                $db->query(
                    "CREATE TABLE IF NOT EXISTS \"{$table}\" (
                        \"vid\" VARCHAR(64) NOT NULL PRIMARY KEY,
                        \"data\" TEXT,
                        \"created\" INTEGER NOT NULL DEFAULT 0
                    )",
                    Db::WRITE
                );
                break;

            default:
                $db->query(
                    "CREATE TABLE IF NOT EXISTS `{$table}` (
                        `vid` VARCHAR(64) NOT NULL,
                        `data` TEXT,
                        `created` int(10) unsigned DEFAULT '0',
                        PRIMARY KEY (`vid`)
                    ) DEFAULT CHARSET=utf8mb4",
                    Db::WRITE
                );
        }
    }

    /**
     * 渲染卡片
     */
    private static function card(array $meta, array $settings): string
    {
        $url = htmlspecialchars($meta['url'], ENT_QUOTES, 'UTF-8');
        $cover = htmlspecialchars($meta['cover'], ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars($meta['title'], ENT_QUOTES, 'UTF-8');
        $author = htmlspecialchars($meta['author'] ?? '', ENT_QUOTES, 'UTF-8');
        $site = htmlspecialchars($meta['site'] ?? '', ENT_QUOTES, 'UTF-8');
        $size = in_array($settings['size'], ['large', 'medium', 'compact'], true)
            ? $settings['size']
            : 'medium';

        $html = '<div class="vc-card vc-size-' . $size . '">';
        $html .= '<a class="vc-cover" href="' . $url . '" target="_blank" rel="noopener nofollow">';
        $html .= '<img src="' . $cover . '" alt="' . $title . '" loading="lazy" referrerpolicy="no-referrer">';
        $html .= '<span class="vc-play" aria-hidden="true"></span>';
        $html .= '</a><div class="vc-body">';

        if ($settings['showTitle']) {
            $html .= '<a class="vc-title" href="' . $url . '" target="_blank" rel="noopener nofollow">'
                . $title . '</a>';
        }

        $html .= '<span class="vc-meta">' . $site;

        if ('' !== $author) {
            $html .= ' · ' . $author;
        }

        $html .= '</span></div></div>';

        return self::style() . $html;
    }

    /**
     * 样式：每次请求只注入一次
     */
    private static function style(): string
    {
        static $injected = false;

        if ($injected) {
            return '';
        }

        $injected = true;

        return '<style>' . self::CSS . '</style>';
    }

    private const CSS = <<<'CSS'
.vc-card{margin:1.2em 0;max-width:100%;border:1px solid rgba(128,128,128,.25);border-radius:10px;overflow:hidden;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.06)}
.vc-card a{color:inherit;text-decoration:none}
.vc-cover{position:relative;display:block;background:#000;overflow:hidden}
.vc-cover img{display:block;width:100%;height:auto;aspect-ratio:16/9;object-fit:cover}
.vc-play{position:absolute;left:50%;top:50%;width:56px;height:40px;margin:-20px 0 0 -28px;border-radius:10px;background:rgba(0,0,0,.55);transition:background .2s}
.vc-play::after{content:"";position:absolute;left:50%;top:50%;margin:-10px 0 0 -6px;border:10px solid transparent;border-left-color:#fff;border-right:0}
.vc-cover:hover .vc-play{background:rgba(0,0,0,.78)}
.vc-body{padding:10px 12px}
.vc-title{display:block;font-weight:600;line-height:1.5;word-break:break-word}
.vc-title:hover{opacity:.75}
.vc-meta{display:block;margin-top:4px;font-size:12px;opacity:.6}
.vc-size-large{max-width:760px}
.vc-size-medium{max-width:520px}
.vc-size-compact{display:flex;align-items:center;max-width:440px}
.vc-size-compact .vc-cover{width:170px;flex:none}
.vc-size-compact .vc-body{flex:1;min-width:0;padding:10px 12px}
@media (prefers-color-scheme:dark){.vc-card{background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.12)}}
CSS;
}
