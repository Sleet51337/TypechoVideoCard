<?php
/**
 * TypechoVideoCard 逻辑自测：链接解析、卡片渲染、替换范围
 *
 * 运行：php tests/run.php
 */

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

namespace Typecho {
    class Config
    {
        private $data;

        public function __construct(array $data = [])
        {
            $this->data = $data;
        }

        public function __get($name)
        {
            return $this->data[$name] ?? null;
        }

        public function __isset($name): bool
        {
            return isset($this->data[$name]);
        }

        public function toArray(): array
        {
            return $this->data;
        }
    }

    class FakeAdapter
    {
        public function getDriver(): string
        {
            return 'mysql';
        }
    }

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
            $key = $this->where[0] ?? '';
            return Db::$storage[$this->table][$key] ?? false;
        }

        public function execute()
        {
            $key = $this->rows['vid'] ?? '';
            Db::$storage[$this->table][$key] = $this->rows;
        }
    }

    class Db
    {
        public const WRITE = 2;

        public static $storage = [];

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
            $query = new FakeQuery();
            $query->table = $table;
            return $query;
        }

        public function update($table)
        {
            $query = new FakeQuery();
            $query->table = $table;
            return $query;
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
}

namespace Typecho\Http {
    class Client
    {
        public static $responses = [];
        public static $requests = [];

        public $status = 404;
        public $body = '';

        public static function get()
        {
            return new self();
        }

        public function setTimeout(int $timeout): Client
        {
            return $this;
        }

        public function setAgent(string $agent): Client
        {
            return $this;
        }

        public function setHeader(string $key, string $value): Client
        {
            return $this;
        }

        public function send(string $url)
        {
            self::$requests[] = $url;

            foreach (self::$responses as $needle => $response) {
                if (false !== strpos($url, $needle)) {
                    $this->status = $response['status'];
                    $this->body = $response['body'];
                    return;
                }
            }

            $this->status = 404;
            $this->body = '';
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

namespace Widget {
    class Options
    {
        /** null = 尚未配置（抛异常），数组 = 插件设置 */
        public static $config = null;

        public static function alloc($params = null, $request = null)
        {
            return new self();
        }

        public function plugin($name)
        {
            if (null === self::$config) {
                throw new \Typecho\Plugin\Exception('插件 TypechoVideoCard 的配置信息没有找到');
            }

            return new \Typecho\Config(self::$config);
        }
    }
}

namespace {
    require dirname(__DIR__) . '/Plugin.php';

    use TypechoPlugin\TypechoVideoCard\Plugin;

    $failures = 0;
    $checks = 0;

    function check($condition, string $message): void
    {
        global $failures, $checks;
        $checks++;

        if ($condition) {
            echo "  ok  $message\n";
        } else {
            $failures++;
            echo "  FAIL $message\n";
        }
    }

    function parse(string $url): ?array
    {
        $method = new \ReflectionMethod(Plugin::class, 'parse');
        $method->setAccessible(true);
        return $method->invoke(null, $url);
    }

    class FakeArchive
    {
        public function is($type)
        {
            return 'single' === $type;
        }
    }

    class FakeList
    {
        public function is($type)
        {
            return 'index' === $type;
        }
    }

    class FakeFeed
    {
        public function is($type)
        {
            return 'feed' === $type;
        }
    }

    echo "\n[1] 链接解析\n";

    $cases = [
        ['https://www.bilibili.com/video/BV1GJ411x7h7', 'bilibili', 'BV1GJ411x7h7'],
        ['https://m.bilibili.com/video/BV1GJ411x7h7?p=2', 'bilibili', 'BV1GJ411x7h7'],
        ['https://www.bilibili.com/video/av170001', 'bilibili', 'av170001'],
        ['https://youtu.be/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'],
        ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=30s', 'youtube', 'dQw4w9WgXcQ'],
        ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'],
        ['https://www.youtube.com/shorts/abcdefghijk', 'youtube', 'abcdefghijk'],
        ['https://vimeo.com/76979871', 'vimeo', '76979871'],
        ['https://player.vimeo.com/video/76979871', 'vimeo', '76979871'],
    ];

    foreach ($cases as [$url, $platform, $vid]) {
        $result = parse($url);
        $ok = null !== $result && $result['platform'] === $platform && $result['vid'] === $vid;
        check($ok, "$url → $platform:$vid" . ($ok ? '' : ' （实际：' . json_encode($result, JSON_UNESCAPED_UNICODE) . '）'));
    }

    check(null === parse('https://example.com/article/1'), '普通链接不识别');
    check(null === parse('https://www.bilibili.com/'), '非视频页不识别');

    echo "\n[2] 抓取失败时降级\n";

    \Widget\Options::$config = [
        'enabled' => '1',
        'scope' => 'single',
        'size' => 'medium',
        'showTitle' => '1',
        'platforms' => ['bilibili', 'youtube'],
    ];
    \Typecho\Http\Client::$responses = [];
    \Typecho\Http\Client::$requests = [];

    $html = Plugin::filterContent(
        '<p>看这个 <a href="https://www.bilibili.com/video/BV1GJ411x7h7">视频</a></p>',
        new FakeArchive()
    );

    check(false === strpos($html, 'vc-card'), '接口不可用时保留原链接');
    check(false !== strpos($html, 'BV1GJ411x7h7'), '原链接文本保留');

    echo "\n[3] 正常渲染\n";

    \Typecho\Http\Client::$responses = [
        'api.bilibili.com' => [
            'status' => 200,
            'body' => json_encode([
                'code' => 0,
                'data' => [
                    'title' => '测试标题 <带尖括号>',
                    'pic'   => '//i0.hdslb.com/bfs/archive/cover.jpg',
                    'bvid'  => 'BV1GJ411x7h7',
                    'owner' => ['name' => '某UP主'],
                ],
            ]),
        ],
    ];
    \Typecho\Http\Client::$requests = [];
    \Typecho\Db::$storage = [];

    $html = Plugin::filterContent(
        '<p>看这个 <a href="https://www.bilibili.com/video/BV1GJ411x7h7">视频</a></p>',
        new FakeArchive()
    );

    check(false !== strpos($html, 'vc-card'), '生成卡片');
    check(false !== strpos($html, 'https://i0.hdslb.com/bfs/archive/cover.jpg'), '封面地址 https 化');
    check(false !== strpos($html, '测试标题 &lt;带尖括号&gt;'), '标题转义');
    check(false !== strpos($html, '某UP主'), '作者名');
    check(false !== strpos($html, 'href="https://www.bilibili.com/video/BV1GJ411x7h7"'), '封面链接指向原视频');
    check(false !== strpos($html, 'target="_blank"'), '新窗口打开');
    check(substr_count($html, '<style>') === 1, '样式只注入一次');

    $requests = count(\Typecho\Http\Client::$requests);
    check($requests === 1, "view 接口可用时只发 1 个请求（实际 $requests 次）");

    $before = count(\Typecho\Http\Client::$requests);
    Plugin::filterContent('<a href="https://www.bilibili.com/video/BV1GJ411x7h7">再来一次</a>', new FakeArchive());
    check(
        count(\Typecho\Http\Client::$requests) === $before,
        '命中缓存后不再请求接口（实际 ' . count(\Typecho\Http\Client::$requests) . ' 次）'
    );

    echo "\n[4] 作用域\n";

    $listHtml = Plugin::filterContent(
        '<a href="https://www.bilibili.com/video/BV1GJ411x7h7">视频</a>',
        new FakeList()
    );
    check(false === strpos($listHtml, 'vc-card'), '列表页不渲染（scope=single）');

    \Widget\Options::$config['scope'] = 'all';
    $listHtml = Plugin::filterContent(
        '<a href="https://www.bilibili.com/video/BV1GJ411x7h7">视频</a>',
        new FakeList()
    );
    check(false !== strpos($listHtml, 'vc-card'), 'scope=all 时列表页也渲染');

    $feedHtml = Plugin::filterContent(
        '<a href="https://www.bilibili.com/video/BV1GJ411x7h7">视频</a>',
        new FakeFeed()
    );
    check(false === strpos($feedHtml, 'vc-card'), 'scope=all 时订阅源里不渲染');
    \Widget\Options::$config['scope'] = 'single';

    echo "\n[5] 不该动的地方\n";

    $codeHtml = Plugin::filterContent(
        '<pre><code>https://www.bilibili.com/video/BV1GJ411x7h7</code></pre>',
        new FakeArchive()
    );
    check(false === strpos($codeHtml, 'vc-card'), '代码块里的链接不处理');

    $plainHtml = Plugin::filterContent(
        '<p><a href="https://example.com/hello">普通链接</a></p>',
        new FakeArchive()
    );
    check(false === strpos($plainHtml, 'vc-card'), '普通链接不处理');
    check(false !== strpos($plainHtml, 'https://example.com/hello'), '普通链接原样保留');

    $code = Plugin::filterContent(
        '<p>直接粘贴 https://www.bilibili.com/video/BV1GJ411x7h7 也能识别</p>',
        new FakeArchive()
    );
    check(false !== strpos($code, 'vc-card'), '裸链接也能识别');

    $tagClosed = Plugin::filterContent(
        '<p>https://www.bilibili.com/video/BV1GJ411x7h7</p>',
        new FakeArchive()
    );
    check(false !== strpos($tagClosed, 'vc-card'), '标签闭合后紧跟的裸链接也能识别');

    echo "\n[6] 平台开关\n";

    \Widget\Options::$config['platforms'] = ['youtube'];
    $html = Plugin::filterContent(
        '<a href="https://www.bilibili.com/video/BV1GJ411x7h7">视频</a>',
        new FakeArchive()
    );
    check(false === strpos($html, 'vc-card'), '未开启的平台不渲染');
    \Widget\Options::$config['platforms'] = ['bilibili', 'youtube'];

    echo "\n[7] 禁用开关\n";

    \Widget\Options::$config['enabled'] = null;
    $html = Plugin::filterContent(
        '<a href="https://www.bilibili.com/video/BV1GJ411x7h7">视频</a>',
        new FakeArchive()
    );
    check(false === strpos($html, 'vc-card'), '总开关关闭时不渲染');

    \Widget\Options::$config = null;
    $html = Plugin::filterContent(
        '<a href="https://www.bilibili.com/video/BV1GJ411x7h7">视频</a>',
        new FakeArchive()
    );
    check(false === strpos($html, 'vc-card'), '未配置插件时不报错且不渲染');

    echo "\n----------------------------------------\n";
    echo "共 $checks 项，失败 $failures 项\n";

    exit($failures > 0 ? 1 : 0);
}
