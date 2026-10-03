<?php
/**
 * 缓存策略建议器。
 *
 * 按资源类型输出 Cache-Control / Expires / ETag 建议，
 * 并区分「内容指纹资源」（可永久缓存）与「HTML 文档」（必须每次校验）。
 *
 * 输出的建议值遵循 RFC 9111 缓存语义：
 * - 指纹资源（?ver= / 内容哈希文件名）：public, max-age=31536000, immutable
 * - 非指纹静态资源：public, max-age=604800 + must-revalidate
 * - HTML：no-cache（允许缓存但每次必须回源校验，配合 ETag / Last-Modified）
 * - 媒体与字体：public, max-age=2592000
 * - 第三方脚本：public, max-age=86400
 *
 * @package MornRain\PerfSentry
 */

declare(strict_types=1);

namespace MornRain\PerfSentry;

/**
 * 缓存策略建议器。
 */
class CachePolicySuggestor
{
    /** 一年（秒） */
    public const ONE_YEAR = 31536000;

    /** 30 天（秒） */
    public const ONE_MONTH = 2592000;

    /** 7 天（秒） */
    public const ONE_WEEK = 604800;

    /** 1 天（秒） */
    public const ONE_DAY = 86400;

    /** @var bool 是否在检测到无版本标识时自动建议改用文件指纹 */
    protected $suggestFingerprint = true;

    /** @var string 站点主机名 */
    protected $siteHost = '';

    /**
     * 构造函数。
     *
     * @param string $siteHost 站点主机名。
     */
    public function __construct(string $siteHost = '')
    {
        $this->siteHost = strtolower(trim($siteHost));
    }

    /**
     * 是否建议改用文件指纹方案。
     */
    public function suggestFingerprint(bool $enabled = true): self
    {
        $this->suggestFingerprint = $enabled;

        return $this;
    }

    /**
     * 为整页 HTML 生成缓存建议。
     *
     * @param string $html 页面 HTML。
     * @return array<string,mixed>
     */
    public function suggest(string $html): array
    {
        $auditor   = new ResourceAuditor($this->siteHost);
        $resources = $auditor->audit($html);
        $items     = [];

        foreach ($resources as $resource) {
            $item = $this->forResource($resource);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return [
            'items'     => $items,
            'count'     => count($items),
            'html_rule' => $this->forHtml(),
            'notes'     => $this->notes(),
        ];
    }

    /**
     * 为单个资源生成缓存建议。
     *
     * @param Resource $resource 资源。
     * @return array<string,mixed>|null 内联资源返回 null。
     */
    public function forResource(Resource $resource): ?array
    {
        if ($resource->isInline()) {
            return null;
        }

        $url     = $resource->url();
        $type    = $resource->type();
        $fingerprint = $this->hasFingerprint($url);
        $thirdParty  = $resource->isThirdParty($this->siteHost);

        $maxAge = $this->maxAgeFor($type, $fingerprint, $thirdParty);
        $policy = $thirdParty
            ? 'public'
            : 'public, max-age=' . $maxAge . ($fingerprint ? ', immutable' : ', must-revalidate');

        $headers = [
            'Cache-Control' => $policy,
            'ETag'          => '"' . $this->weakEtag($url) . '"',
        ];
        if ($maxAge >= self::ONE_MONTH) {
            $headers['Expires'] = gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT';
        }

        $actions = [];
        if (!$fingerprint && $this->suggestFingerprint) {
            $actions[] = 'URL 无版本标识：改用内容指纹文件名（如 app.a1b2c3.js）或追加 ?ver=<filemtime>，否则无法使用 immutable。';
        }
        if ($thirdParty) {
            $actions[] = '第三方资源：优先自托管或改用 Preconnect 提前建连，避免第三方 DNS 与 TLS 成为瓶颈。';
        }
        if ($type === Resource::TYPE_IMAGE) {
            $actions[] = '建议同时输出 AVIF / WebP 协商版本（Vary: Accept），同等画质下可省 30%~50% 体积。';
        }

        return [
            'url'         => $url,
            'type'        => $type,
            'fingerprint' => $fingerprint,
            'third_party' => $thirdParty,
            'max_age'     => $maxAge,
            'max_age_human' => $this->humanDuration($maxAge),
            'headers'     => $headers,
            'server_config' => $this->serverConfig($type, $fingerprint),
            'actions'     => $actions,
        ];
    }

    /**
     * HTML 文档的缓存规则。
     *
     * @return array<string,mixed>
     */
    public function forHtml(): array
    {
        return [
            'headers' => [
                'Cache-Control' => 'no-cache, must-revalidate',
                'Pragma'        => 'no-cache',
                // 这里刻意**不给出** ETag 的具体值：ETag 必须由服务端依据
                // 正文内容实时计算（常用 正文 mtime + size 的哈希）。
                // 给出任何占位值都可能被直接复制到配置里，
                // 导致所有页面共用同一个 ETag、缓存彻底失效。
                // 需要示例写法时见 actions 中的说明。
            ],
            'reason'  => 'HTML 必须每次回源校验，配合 ETag / Last-Modified 做 304 协商；避免 CDN 缓存过期页面。',
            'actions' => [
                '启用 gzip/brotli，典型 HTML 压缩率 60%~75%。',
                '为 .html 配置 ETag：PHP 示例为 hash(\'sha256\', (string) filemtime($f) . filesize($f))，并加引号输出。',
                '同时输出 Last-Modified（filemtime）以支持不支持 ETag 的客户端。',
                '若使用整页缓存，缓存键必须包含查询参数中的分页、筛选、语言参数。',
            ],
        ];
    }

    /**
     * Nginx 站点级配置片段。
     *
     * @param string $type        资源类型。
     * @param bool   $fingerprint 是否为指纹资源。
     * @return string
     */
    public function serverConfig(string $type, bool $fingerprint = false): string
    {
        if (!$fingerprint) {
            return '# ' . $type . '：无指纹，建议先改造 URL 再套用长期缓存';
        }

        $location = '';
        switch ($type) {
            case Resource::TYPE_STYLESHEET:
                $location = '~* \.css$';
                break;
            case Resource::TYPE_SCRIPT:
                $location = '~* \.js$';
                break;
            case Resource::TYPE_FONT:
                $location = '~* \.(woff2?|ttf|otf|eot)$';
                break;
            case Resource::TYPE_IMAGE:
                $location = '~* \.(avif|webp|jpg|jpeg|png|gif|svg|ico)$';
                break;
            default:
                $location = '~* \.(mp4|webm|mp3|ogg)$';
                break;
        }

        return 'location ' . $location . ' {' . PHP_EOL
            . '    add_header Cache-Control "public, max-age=' . self::ONE_YEAR . ', immutable";' . PHP_EOL
            . '    add_header Vary "Accept-Encoding";' . PHP_EOL
            . '    expires 1y;' . PHP_EOL
            . '}';
    }

    /**
     * 通用注意事项。
     *
     * @return array<int,string>
     */
    public function notes(): array
    {
        return [
            '启用 Brotli（brotli_static）优先于 gzip，同等压缩率下体积再小 15%~20%。',
            '正确设置 Vary: Accept-Encoding，避免压缩版本与未压缩版本互相污染缓存。',
            'immutable 只能用于内容指纹资源：URL 不变而内容变会导致用户长期拿到旧文件。',
            '不要给 HTML 设置 max-age > 0，除非明确接受内容延迟更新（博客可用 5 分钟 + 命中后置失效）。',
            '发布新版本时若未用指纹，必须同步更新 ver 参数，否则各端缓存无法失效。',
            '静态资源建议开启 CORS（同源部署可省略），跨域 CDN 需 Access-Control-Allow-Origin。',
        ];
    }

    /**
     * 计算指定条件的 max-age。
     *
     * @param string $type        资源类型。
     * @param bool   $fingerprint 是否为指纹资源。
     * @param bool   $thirdParty  是否第三方。
     */
    protected function maxAgeFor(string $type, bool $fingerprint, bool $thirdParty): int
    {
        if ($thirdParty) {
            return self::ONE_DAY;
        }

        if ($fingerprint) {
            return self::ONE_YEAR;
        }

        switch ($type) {
            case Resource::TYPE_FONT:
            case Resource::TYPE_MEDIA:
                return self::ONE_MONTH;
            case Resource::TYPE_STYLESHEET:
            case Resource::TYPE_SCRIPT:
                return self::ONE_WEEK;
            case Resource::TYPE_IMAGE:
                return self::ONE_MONTH;
            default:
                return self::ONE_DAY;
        }
    }

    /**
     * 判断 URL 是否携带内容指纹。
     */
    protected function hasFingerprint(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        if (preg_match('/[?&](ver|version|v|rev)=[^&]+/i', $url) === 1) {
            return true;
        }
        // style.abc123.css / app.a1b2c3d4.js
        if (preg_match('/[.\-][0-9a-f]{8,}\.[a-z0-9]{2,5}$/i', $url) === 1) {
            return true;
        }

        return false;
    }

    /**
     * 生成弱 ETag（基于路径的稳定值）。
     *
     * 用 sha256 而非 md5：ETag 属于缓存正确性相关标识，
     * 且本方法可被外部读取，不应留下已知弱哈希的口子。
     */
    protected function weakEtag(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '') {
            $path = $url;
        }

        return substr(hash('sha256', $path), 0, 20);
    }

    /**
     * 时长格式化。
     */
    protected function humanDuration(int $seconds): string
    {
        if ($seconds >= self::ONE_YEAR) {
            return '1 年';
        }
        if ($seconds >= self::ONE_MONTH) {
            return round($seconds / self::ONE_MONTH) . ' 个月';
        }
        if ($seconds >= self::ONE_WEEK) {
            return round($seconds / self::ONE_WEEK) . ' 周';
        }
        if ($seconds >= 3600) {
            return round($seconds / 3600) . ' 小时';
        }
        if ($seconds >= 60) {
            return round($seconds / 60) . ' 分钟';
        }

        return $seconds . ' 秒';
    }
}
