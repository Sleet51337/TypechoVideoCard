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
    /** 真实 HTTP 客户端（用 curl），用来跑通真实接口 */
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

    $cases = [
        ['bilibili', 'BV1GJ411x7h7'],
        ['bilibili', 'av170001'],
        ['youtube', 'dQw4w9WgXcQ'],
    ];

    $failures = 0;

    foreach ($cases as [$platform, $vid]) {
        echo "--- $platform:$vid ---\n";
        $meta = $fetch->invoke(null, $platform, $vid);

        if (null === $meta) {
            $failures++;
            echo "  FAIL 没取到信息\n";
            continue;
        }

        foreach (['title', 'cover', 'url', 'author', 'site'] as $key) {
            printf("  %-7s %s\n", $key, $meta[$key]);
        }

        if (empty($meta['title']) || empty($meta['cover'])) {
            $failures++;
            echo "  FAIL 标题或封面为空\n";
        }

        if (0 === strpos($meta['cover'], 'http://')) {
            $failures++;
            echo "  FAIL 封面不是 https\n";
        }
    }

    echo "\n失败 $failures 项\n";
    exit($failures > 0 ? 1 : 0);
}
