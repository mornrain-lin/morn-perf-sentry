<?php
/**
 * 资源审计的数据模型。
 *
 * 描述一个被审计页面中发现的静态资源（CSS / JS / 图片 / 字体 / iframe），
 * 承载「在哪、是什么、多大、是否阻塞、是否可优化」等全部信息，
 * 供 ResourceAuditor 收集、ScoreCalculator 打分、LazyLoadHint 生成建议。
 *
 * @package MornRain\PerfSentry
 */

declare(strict_types=1);

namespace MornRain\PerfSentry;

/**
 * 资源对象。
 */
class Resource
{
    public const TYPE_STYLESHEET = 'stylesheet';
    public const TYPE_SCRIPT    = 'script';
    public const TYPE_IMAGE     = 'image';
    public const TYPE_FONT      = 'font';
    public const TYPE_IFRAME    = 'iframe';
    public const TYPE_MEDIA     = 'media';
    public const TYPE_OTHER     = 'other';

    /** @var string 资源 URL */
    protected $url;

    /** @var string 资源类型，见 TYPE_* 常量 */
    protected $type;

    /** @var string 来源（link / script / img / iframe） */
    protected $tagName;

    /** @var int 估算传输体积（字节） */
    protected $size;

    /** @var array<string,string> 相关 HTML 属性 */
    protected $attributes = [];

    /** @var bool 是否位于 <head>（首屏关键区域） */
    protected $inHead = false;

    /** @var int 在文档中的出现序号 */
    protected $order = 0;

    /** @var array<int,string> 该资源导致的问题列表 */
    protected $issues = [];

    /**
     * 构造函数。
     *
     * @param string               $url        资源 URL。
     * @param string               $type       资源类型。
     * @param string               $tagName    来源标签。
     * @param int                  $size       估算体积（字节）。
     * @param array<string,string> $attributes 相关属性。
     */
    public function __construct(string $url, string $type, string $tagName = '', int $size = 0, array $attributes = [])
    {
        $this->url        = $url;
        $this->type       = $type;
        $this->tagName    = $tagName;
        $this->size       = max(0, $size);
        $this->attributes = $attributes;
    }

    /**
     * 取得资源 URL。
     */
    public function url(): string
    {
        return $this->url;
    }

    /**
     * 取得资源类型。
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * 取得来源标签。
     */
    public function tagName(): string
    {
        return $this->tagName;
    }

    /**
     * 取得估算体积（字节）。
     */
    public function size(): int
    {
        return $this->size;
    }

    /**
     * 设置估算体积。
     */
    public function setSize(int $size): self
    {
        $this->size = max(0, $size);

        return $this;
    }

    /**
     * 取得指定属性值。
     */
    public function attribute(string $name, string $default = ''): string
    {
        return $this->attributes[strtolower($name)] ?? $default;
    }

    /**
     * 设置属性。
     */
    public function setAttribute(string $name, string $value): self
    {
        $this->attributes[strtolower($name)] = $value;

        return $this;
    }

    /**
     * 取得全部属性。
     *
     * @return array<string,string>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * 标记是否位于 head。
     */
    public function setInHead(bool $inHead): self
    {
        $this->inHead = $inHead;

        return $this;
    }

    /**
     * 是否位于 head。
     */
    public function isInHead(): bool
    {
        return $this->inHead;
    }

    /**
     * 设置出现序号。
     */
    public function setOrder(int $order): self
    {
        $this->order = $order;

        return $this;
    }

    /**
     * 取得出现序号。
     */
    public function order(): int
    {
        return $this->order;
    }

    /**
     * 记录一个问题。
     *
     * @param string $code    问题代码。
     * @param string $message 人类可读描述。
     */
    public function addIssue(string $code, string $message): self
    {
        $this->issues[] = $code . '|' . $message;

        return $this;
    }

    /**
     * 取得问题列表。
     *
     * @return array<int,array{code:string,message:string}>
     */
    public function issues(): array
    {
        $out = [];
        foreach ($this->issues as $raw) {
            $parts = explode('|', $raw, 2);
            $out[] = [
                'code'    => $parts[0],
                'message' => $parts[1] ?? '',
            ];
        }

        return $out;
    }

    /**
     * 是否已记录指定问题。
     */
    public function hasIssue(string $code): bool
    {
        foreach ($this->issues as $raw) {
            if (strpos($raw, $code . '|') === 0) {
                return true;
            }
        }

        return false;
    }

    /* ---------- 常用派生判断 ---------- */

    /**
     * 是否为渲染阻塞资源（无 async / defer 的 head 内 script，或 rel=stylesheet）。
     */
    public function isRenderBlocking(): bool
    {
        if ($this->type === self::TYPE_STYLESHEET) {
            // 带 preload 的样式表不算阻塞
            return $this->attribute('rel') !== 'preload';
        }

        if ($this->type === self::TYPE_SCRIPT) {
            if ($this->hasAttribute('defer') || $this->hasAttribute('async')) {
                return false;
            }
            // body 末尾的同步脚本不阻塞首屏渲染
            return $this->inHead;
        }

        return false;
    }

    /**
     * 是否已声明 defer。
     */
    public function isDeferred(): bool
    {
        return $this->hasAttribute('defer') || $this->hasAttribute('async');
    }

    /**
     * 是否已声明 loading="lazy"。
     */
    public function isLazyLoaded(): bool
    {
        return strtolower($this->attribute('loading')) === 'lazy';
    }

    /**
     * 是否声明了 fetchpriority。
     */
    public function hasFetchPriority(): bool
    {
        return $this->attribute('fetchpriority') !== '';
    }

    /**
 * 是否声明了 width 与 height（用于预留空间避免 CLS）。
     *
     * 只对图片与 iframe 生效：这两类元素默认尺寸为 300x150，
     * 缺尺寸一定会引起布局偏移。其余类型（script/link 等）本就不占布局，
     * 返回 true 表示「无需关心」，避免调用方误加无用属性。
     */
    public function hasDimensions(): bool
    {
        if ($this->type !== self::TYPE_IMAGE && $this->type !== self::TYPE_IFRAME) {
            return true;
        }

        return $this->attribute('width') !== '' && $this->attribute('height') !== '';
    }

    /**
     * 是否为内联资源（无 src/href，属 data URI 或内联内容）。
     */
    public function isInline(): bool
    {
        $url = trim($this->url);

        return $url === '' || strpos($url, 'data:') === 0 || strpos($url, '#') === 0;
    }

    /**
     * 是否为第三方（站外）资源。
     *
     * @param string $siteHost 本站主机名；留空表示「凡是绝对地址都算站外」。
     */
    public function isThirdParty(string $siteHost = ''): bool
    {
        $url = trim($this->url);
        if ($url === '' || $this->isInline()) {
            return false;
        }

        // 协议相对地址（//cdn.example.com/a.js）没有 scheme 但有 host，
        // 直接 parse_url 会拿不到 host，需先补全。
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            // 相对路径（/wp-content/a.css）与 data: 都拿不到 host。
            // 它们是站内资源，不能因为「解析不出主机名」就算成第三方。
            return false;
        }

        $siteHost = strtolower(trim($siteHost));
        if ($siteHost === '') {
            return true;
        }

        return $host !== $siteHost && substr($host, -strlen('.' . $siteHost)) !== '.' . $siteHost;
    }

    /**
     * 是否为字体资源。
     */
    public function isFont(): bool
    {
        return $this->type === self::TYPE_FONT;
    }

    /**
     * 取得文件扩展名（小写，不含点）。
     */
    public function extension(): string
    {
        $path = (string) parse_url($this->url, PHP_URL_PATH);
        $ext  = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return $ext;
    }

    /**
     * 取得体积的人类可读形式。
     */
    public function humanSize(): string
    {
        return self::formatBytes($this->size);
    }

    /**
     * 字节数格式化。
     */
    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 2) . ' MB';
    }

    /**
     * 是否存在某个属性（无值属性如 defer 视为存在）。
     */
    protected function hasAttribute(string $name): bool
    {
        $name = strtolower($name);

        return array_key_exists($name, $this->attributes) && $this->attributes[$name] !== '';
    }

    /**
     * 转为数组（便于 json_encode）。
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'url'        => $this->url,
            'type'       => $this->type,
            'tag'        => $this->tagName,
            'size'       => $this->size,
            'size_human' => $this->humanSize(),
            'in_head'    => $this->inHead,
            'blocking'   => $this->isRenderBlocking(),
            'lazy'       => $this->isLazyLoaded(),
            'external'   => $this->url !== '' && (string) parse_url($this->url, PHP_URL_HOST) !== '',
            'attributes' => $this->attributes,
            'issues'     => $this->issues(),
        ];
    }
}
