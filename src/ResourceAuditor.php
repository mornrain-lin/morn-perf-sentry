<?php
/**
 * HTML 资源审计器。
 *
 * 从 HTML 源码中静态提取全部 CSS / JS / 图片 / 字体 / iframe / 媒体资源，
 * 统计请求数与估算传输体积，并标记以下问题：
 * - 未压缩（无 br/gzip 提示的 CSS/JS 体积超阈值）；
 * - 缺省缓存（无 version/query 的静态资源，CDN 缓存命中率低）；
 * - 图片未启用 loading="lazy"；
 * - 缺 width/height 导致布局偏移（CLS）；
 * - 首屏未声明 fetchpriority；
 * - 字体未使用 font-display 或 preload。
 *
 * 体积估算不发起任何网络请求：优先使用调用方提供的实际体积表（sizeMap），
 * 未命中时按「同类型资源平均值」估算，因此可在完全离线的 CI 中运行。
 *
 * @package MornRain\PerfSentry
 */

declare(strict_types=1);

namespace MornRain\PerfSentry;

/**
 * 资源审计器。
 */
class ResourceAuditor
{
    /** 各类型资源在无体积数据时的默认估算值（字节，已按压缩后估算） */
    public const DEFAULT_SIZES = [
        Resource::TYPE_STYLESHEET => 45000,
        Resource::TYPE_SCRIPT    => 62000,
        Resource::TYPE_IMAGE     => 85000,
        Resource::TYPE_FONT      => 38000,
        Resource::TYPE_IFRAME    => 120000,
        Resource::TYPE_MEDIA     => 250000,
        Resource::TYPE_OTHER     => 15000,
    ];

    /** @var array<string,int> 体积超过该值（字节）视为过大 */
    protected $sizeWarningThreshold = 100000;

    /** @var array<string,int> 实际体积表，键为完整 URL 或路径 */
    protected $sizeMap = [];

    /** @var string 站点主机名，用于区分内外链 */
    protected $siteHost = '';

    /** @var int 估算图片资源时的宽高比（用于判断尺寸是否异常） */
    protected $defaultImageRatio = 16 / 9;

    /**
     * 构造函数。
     *
     * @param string $siteHost 站点主机名，用于识别第三方资源。
     */
    public function __construct(string $siteHost = '')
    {
        $this->siteHost = strtolower(trim($siteHost));
    }

    /**
     * 注入实际体积数据（字节）。
     *
     * 可传入 `get_all_headers()` 风格的 Content-Length 表，或任何 URL => 字节数 的映射。
     * 审计时按「完整 URL → 去掉 query 的路径 → 文件名」顺序依次查找。
     *
     * @param array<string,int> $map 体积表。
     */
    public function withSizeMap(array $map): self
    {
        $clean = [];
        foreach ($map as $url => $size) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            $clean[$url] = max(0, (int) $size);
        }
        $this->sizeMap = $clean;

        return $this;
    }

    /**
     * 设置体积告警阈值。
     */
    public function sizeWarningThreshold(int $bytes): self
    {
        $this->sizeWarningThreshold = max(1024, $bytes);

        return $this;
    }

    /**
     * 审计 HTML。
     *
     * @param string $html 页面 HTML。
     * @return array<int,Resource>
     */
    public function audit(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $resources = [];
        $order     = 0;

        // 定位 head 结束位置，用于判断资源是否位于首屏关键区域。
        $headEnd = $this->findHeadEnd($html);

        $patterns = $this->patterns();

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern['regex'], $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            foreach ($matches as $match) {
                $attrsRaw = $match['attrs'][0] ?? '';
                $absPos   = $match[0][1] ?? 0;
                $attrs    = $this->parseAttributes((string) $attrsRaw);

                $url = $this->extractUrl($pattern['urlAttr'], $attrs);
                if ($url === '' && $pattern['urlRequired']) {
                    continue;
                }

                $type = $this->resolveLinkType($pattern['tag'], $attrs);

                $inHead = $absPos < $headEnd;

                $resource = new Resource(
                    $url,
                    $type,
                    $pattern['tag'],
                    $this->estimateSize($url, $type),
                    $attrs
                );
                $resource->setInHead($inHead)->setOrder($order);

                $this->annotate($resource);

                $resources[] = $resource;
                $order++;
            }
        }

        // 补充：<style> 内联样式与内联 <script> 的体积计入总量
        foreach ($this->collectInlineBlobs($html) as $inline) {
            $resource = new Resource('', $inline['type'], $inline['tag'], $inline['size'], []);
            $resource->setInHead($inline['in_head'])->setOrder($order);
            $resource->addIssue('inline', sprintf('内联 %s（%s），建议外链以便缓存与压缩。', $inline['tag'], Resource::formatBytes($inline['size'])));
            $resources[] = $resource;
            $order++;
        }

        return $this->sortResources($resources);
    }

    /**
     * 审计并输出统计摘要。
     *
     * @param string $html 页面 HTML。
     * @return array<string,mixed>
     */
    public function summarize(string $html): array
    {
        $resources = $this->audit($html);
        $summary  = $this->summarizeResources($resources);

        $summary['resources'] = array_map(static function (Resource $resource): array {
            return $resource->toArray();
        }, $resources);

        return $summary;
    }

    /**
     * 对资源列表做统计（不重新解析 HTML）。
     *
     * @param array<int,Resource> $resources 资源列表。
     * @return array<string,mixed>
     */
    public function summarizeResources(array $resources): array
    {
        $byType = [
            Resource::TYPE_STYLESHEET => ['count' => 0, 'bytes' => 0],
            Resource::TYPE_SCRIPT    => ['count' => 0, 'bytes' => 0],
            Resource::TYPE_IMAGE     => ['count' => 0, 'bytes' => 0],
            Resource::TYPE_FONT      => ['count' => 0, 'bytes' => 0],
            Resource::TYPE_IFRAME    => ['count' => 0, 'bytes' => 0],
            Resource::TYPE_MEDIA     => ['count' => 0, 'bytes' => 0],
            Resource::TYPE_OTHER     => ['count' => 0, 'bytes' => 0],
        ];

        $totalBytes  = 0;
        $blocking    = [];
        $uncompressed = [];
        $noCache     = [];
        $notLazy     = [];
        $noDims      = [];
        $fontIssues  = [];
        $thirdParty  = [];
        $issueCount  = 0;

        foreach ($resources as $resource) {
            $type = $resource->type();
            if (!isset($byType[$type])) {
                $type = Resource::TYPE_OTHER;
            }
            $byType[$type]['count']++;
            $byType[$type]['bytes'] += $resource->size();
            $totalBytes += $resource->size();

            if ($resource->isRenderBlocking()) {
                $blocking[] = $resource;
            }
            if ($resource->hasIssue('uncompressed')) {
                $uncompressed[] = $resource;
            }
            if ($resource->hasIssue('no-cache')) {
                $noCache[] = $resource;
            }
            if ($resource->type() === Resource::TYPE_IMAGE && !$resource->isInline() && !$resource->isLazyLoaded()) {
                $notLazy[] = $resource;
            }
            if ($resource->type() === Resource::TYPE_IMAGE && !$resource->hasDimensions()) {
                $noDims[] = $resource;
            }
            if ($resource->type() === Resource::TYPE_FONT && $resource->hasIssue('font-display')) {
                $fontIssues[] = $resource;
            }
            if ($resource->isThirdParty($this->siteHost)) {
                $thirdParty[] = $resource;
            }
            $issueCount += count($resource->issues());
        }

        return [
            'total_count'          => count($resources),
            'total_bytes'          => $totalBytes,
            'total_bytes_human'    => Resource::formatBytes($totalBytes),
            'by_type'              => $byType,
            'blocking_count'       => count($blocking),
            'blocking_bytes'       => array_sum(array_map(static function (Resource $r): int {
                return $r->size();
            }, $blocking)),
            'blocking_urls'        => array_map(static function (Resource $r): string {
                return $r->url() !== '' ? $r->url() : '(内联 ' . $r->tagName() . ')';
            }, $blocking),
            'uncompressed_count'   => count($uncompressed),
            'uncompressed_urls'    => array_map(static function (Resource $r): string {
                return $r->url();
            }, $uncompressed),
            'no_cache_count'       => count($noCache),
            'no_cache_urls'        => array_map(static function (Resource $r): string {
                return $r->url();
            }, $noCache),
            'not_lazy_count'       => count($notLazy),
            'not_lazy_urls'        => array_map(static function (Resource $r): string {
                return $r->url();
            }, $notLazy),
            'no_dimensions_count'  => count($noDims),
            'font_issue_count'     => count($fontIssues),
            'third_party_count'    => count($thirdParty),
            'third_party_bytes'    => array_sum(array_map(static function (Resource $r): int {
                return $r->size();
            }, $thirdParty)),
            'issue_count'          => $issueCount,
            'site_host'            => $this->siteHost,
        ];
    }

    /**
     * 取得匹配模式表。
     *
     * @return array<int,array{tag:string,type:string,regex:string,urlAttr:string,urlRequired:bool}>
     */
    protected function patterns(): array
    {
        return [
            // link 统一由一个模式处理，再按 rel 分派类型，避免重复统计。
            [
                'tag'         => 'link',
                'type'        => Resource::TYPE_OTHER,
                'urlAttr'     => 'href',
                'urlRequired' => true,
                'regex'       => '#<link\b(?P<attrs>[^>]*)/?>#i',
            ],
            // 有 src 的外链脚本；内联脚本由 collectInlineBlobs() 单独统计。
            [
                'tag'         => 'script',
                'type'        => Resource::TYPE_SCRIPT,
                'urlAttr'     => 'src',
                'urlRequired' => true,
                'regex'       => '#<script\b(?=[^>]*\bsrc\s*=)(?P<attrs>[^>]*)/?>#i',
            ],
            [
                'tag'         => 'img',
                'type'        => Resource::TYPE_IMAGE,
                'urlAttr'     => 'src',
                'urlRequired' => true,
                'regex'       => '#<img\b(?P<attrs>[^>]*)/?>#i',
            ],
            [
                'tag'         => 'source',
                'type'        => Resource::TYPE_IMAGE,
                'urlAttr'     => 'src',
                'urlRequired' => true,
                'regex'       => '#<source\b(?P<attrs>[^>]*)/?>#i',
            ],
            [
                'tag'         => 'iframe',
                'type'        => Resource::TYPE_IFRAME,
                'urlAttr'     => 'src',
                'urlRequired' => true,
                'regex'       => '#<iframe\b(?P<attrs>[^>]*)/?>#i',
            ],
            [
                'tag'         => 'video',
                'type'        => Resource::TYPE_MEDIA,
                'urlAttr'     => 'src',
                'urlRequired' => true,
                'regex'       => '#<video\b(?P<attrs>[^>]*)/?>#i',
            ],
            [
                'tag'         => 'audio',
                'type'        => Resource::TYPE_MEDIA,
                'urlAttr'     => 'src',
                'urlRequired' => true,
                'regex'       => '#<audio\b(?P<attrs>[^>]*)/?>#i',
            ],
        ];
    }

    /**
     * 根据标签与属性判定资源类型。
     *
     * link 标签需要看 rel 才能区分样式表、字体与其他（图标 / manifest / canonical）。
     *
     * @param string               $tag   来源标签。
     * @param array<string,string> $attrs 属性表。
     */
    protected function resolveLinkType(string $tag, array $attrs): string
    {
        if ($tag !== 'link') {
            return $this->defaultTypeForTag($tag);
        }

        $rel = strtolower($attrs['rel'] ?? '');
        $as  = strtolower($attrs['as'] ?? '');

        if ($rel === 'stylesheet') {
            return Resource::TYPE_STYLESHEET;
        }
        if ($rel === 'preload' && $as === 'font') {
            return Resource::TYPE_FONT;
        }
        if ($rel === 'preload' && ($as === 'style' || $as === 'script')) {
            return $as === 'style' ? Resource::TYPE_STYLESHEET : Resource::TYPE_SCRIPT;
        }
        if ($rel === 'modulepreload') {
            return Resource::TYPE_SCRIPT;
        }
        if (in_array($rel, ['icon', 'shortcut icon', 'apple-touch-icon', 'manifest', 'canonical', 'alternate'], true)) {
            // 这些虽非性能关键路径，但计入请求数才有现实意义
            return Resource::TYPE_OTHER;
        }

        return $this->defaultTypeForTag($tag);
    }

    /**
     * 按标签名给出默认类型。
     */
    protected function defaultTypeForTag(string $tag): string
    {
        switch (strtolower($tag)) {
            case 'link':
                return Resource::TYPE_STYLESHEET;
            case 'script':
                return Resource::TYPE_SCRIPT;
            case 'img':
            case 'source':
                return Resource::TYPE_IMAGE;
            case 'iframe':
                return Resource::TYPE_IFRAME;
            case 'video':
            case 'audio':
                return Resource::TYPE_MEDIA;
            default:
                return Resource::TYPE_OTHER;
        }
    }

    /**
     * 定位 head 结束位置。
     *
     * 找不到 </head> 时回退到 body 起点；都没有时返回整个文档长度
     * （保守视为全部位于 head，评分更严格）。
     */
    protected function findHeadEnd(string $html): int
    {
        if (preg_match('#</head\s*>#i', $html, $m, PREG_OFFSET_CAPTURE) === 1) {
            return (int) $m[0][1];
        }
        if (preg_match('#<body\b[^>]*>#i', $html, $m2, PREG_OFFSET_CAPTURE) === 1) {
            return (int) $m2[0][1];
        }

        return strlen($html);
    }

    /**
     * 解析 HTML 标签属性。
     *
     * @param string $raw 标签属性串。
     * @return array<string,string>
     */
    protected function parseAttributes(string $raw): array
    {
        $attrs = [];
        if (preg_match_all(
            '#([a-zA-Z_:][a-zA-Z0-9_.:-]*)\s*(?:=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?#s',
            $raw,
            $matches,
            PREG_SET_ORDER
        ) === false) {
            return $attrs;
        }

        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            if (isset($match[2]) && $match[2] !== '') {
                $value = $match[3] ?? '';
                if ($value === '' && isset($match[4])) {
                    $value = $match[4];
                }
                if ($value === '' && isset($match[5])) {
                    $value = $match[5];
                }
            } else {
                // 无值属性（defer / async），记为属性名本身
                $value = $name;
            }
            $attrs[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $attrs;
    }

    /**
     * 从属性中取出 URL。
     *
     * @param string               $name  属性名。
     * @param array<string,string> $attrs 属性表。
     */
    protected function extractUrl(string $name, array $attrs): string
    {
        $url = trim($attrs[strtolower($name)] ?? '');
        if ($url === '') {
            return '';
        }

        // srcset 形态：取第一个候选的地址
        if (strpos($url, ',') !== false && strpos($url, ' ') !== false) {
            $first = trim(explode(',', $url)[0]);
            $parts = preg_split('/\s+/', $first) ?: [];
            $url   = trim((string) ($parts[0] ?? ''));
        }

        return $url;
    }

    /**
     * 估算资源体积。
     *
     * 查找顺序：完整 URL → 去掉 query 的路径 → 文件名 → 同类型默认值。
     *
     * @param string $url  资源 URL。
     * @param string $type 资源类型。
     */
    protected function estimateSize(string $url, string $type): int
    {
        foreach ($this->sizeLookupKeys($url) as $key) {
            if (isset($this->sizeMap[$key])) {
                return $this->sizeMap[$key];
            }
        }

        return self::DEFAULT_SIZES[$type] ?? self::DEFAULT_SIZES[Resource::TYPE_OTHER];
    }

    /**
     * 生成体积查找键。
     *
     * @return array<int,string>
     */
    protected function sizeLookupKeys(string $url): array
    {
        $url   = trim($url);
        $keys  = [];
        if ($url !== '') {
            $keys[] = $url;
            $path   = (string) parse_url($url, PHP_URL_PATH);
            if ($path !== '' && $path !== '/') {
                $keys[] = $path;
                $keys[] = basename($path);
            }
        }

        return $keys;
    }

    /**
     * 标注资源问题。
     *
     * @param Resource $resource 资源对象（原地修改）。
     */
    protected function annotate(Resource $resource): void
    {
        // 体积超阈值视为可能未压缩
        if ($resource->size() >= $this->sizeWarningThreshold
            && in_array($resource->type(), [Resource::TYPE_SCRIPT, Resource::TYPE_STYLESHEET], true)
        ) {
            $resource->addIssue(
                'uncompressed',
                sprintf('%s 估算 %s，超过 %s 阈值，可能未启用 gzip/brotli 压缩。', strtoupper($resource->extension()), $resource->humanSize(), Resource::formatBytes($this->sizeWarningThreshold))
            );
        }

        // 静态资源缺少版本标识时 CDN 缓存命中率低
        if (in_array($resource->type(), [Resource::TYPE_SCRIPT, Resource::TYPE_STYLESHEET, Resource::TYPE_FONT], true)
            && !$resource->isInline()
        ) {
            $hasVersion = $resource->url() !== ''
                && preg_match('/[?&](ver|version|v)=|_[\d]{6,}\./i', $resource->url()) === 1;
            if (!$hasVersion) {
                $resource->addIssue('no-cache', '静态资源 URL 未带版本标识（ver/version 参数或内容哈希），CDN 与浏览器缓存难以长期命中。');
            }
        }

        // 首屏图片未启用懒加载
        if ($resource->type() === Resource::TYPE_IMAGE && !$resource->isInline() && !$resource->isLazyLoaded()) {
            $resource->addIssue('not-lazy', '图片未声明 loading="lazy"。首屏主图应保留，其余图片建议懒加载。');
        }

        // 图片缺少尺寸会导致 CLS
        if ($resource->type() === Resource::TYPE_IMAGE && !$resource->hasDimensions()) {
            $resource->addIssue('no-dimensions', '图片缺少 width/height 属性，会导致布局偏移（CLS）。');
        }

        // 字体加载方式
        if ($resource->type() === Resource::TYPE_FONT && !$resource->isInline()) {
            $rel  = strtolower($resource->attribute('rel'));
            $crossorigin = $resource->attribute('crossorigin');
            if ($rel === 'preload' && $crossorigin === '') {
                $resource->addIssue('font-preload-cors', '字体 preload 缺少 crossorigin 属性，浏览器会重复下载两次。');
            }
            if ($rel !== 'preload') {
                $resource->addIssue('font-display', '字体未 preload，首屏文本可能被阻塞（FOIT）。');
            }
        }

        // 首屏主图缺少 fetchpriority
        if ($resource->type() === Resource::TYPE_IMAGE && $resource->isInHead() && !$resource->hasFetchPriority()) {
            $resource->addIssue('no-fetchpriority', 'head 内的图片未声明 fetchpriority，若为首屏 LCP 图片建议设为 high。');
        }
    }

    /**
     * 收集内联 style / script 块的体积。
     *
     * @return array<int,array{type:string,tag:string,size:int,in_head:bool}>
     */
    protected function collectInlineBlobs(string $html): array
    {
        $blobs = [];
        $headEnd = $this->findHeadEnd($html);

        if (preg_match_all('#<style\b[^>]*>(.*?)</style>#is', $html, $styles, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($styles as $match) {
                $blobs[] = [
                    'type'    => Resource::TYPE_STYLESHEET,
                    'tag'     => 'style',
                    'size'    => strlen((string) $match[1][0]),
                    'in_head' => (int) $match[0][1] < $headEnd,
                ];
            }
        }

        if (preg_match_all('#<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>#is', $html, $scripts, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($scripts as $match) {
                $blobs[] = [
                    'type'    => Resource::TYPE_SCRIPT,
                    'tag'     => 'script',
                    'size'    => strlen((string) $match[1][0]),
                    'in_head' => (int) $match[0][1] < $headEnd,
                ];
            }
        }

        return $blobs;
    }

    /**
     * 排序：类型优先级 → head 优先 → 体积降序。
     *
     * @param array<int,Resource> $resources 资源列表。
     * @return array<int,Resource>
     */
    protected function sortResources(array $resources): array
    {
        $priority = [
            Resource::TYPE_STYLESHEET => 1,
            Resource::TYPE_SCRIPT    => 2,
            Resource::TYPE_FONT      => 3,
            Resource::TYPE_IMAGE     => 4,
            Resource::TYPE_IFRAME    => 5,
            Resource::TYPE_MEDIA     => 6,
            Resource::TYPE_OTHER     => 7,
        ];

        usort($resources, static function (Resource $a, Resource $b) use ($priority): int {
            $pa = $priority[$a->type()] ?? 9;
            $pb = $priority[$b->type()] ?? 9;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            if ($a->isInHead() !== $b->isInHead()) {
                return $a->isInHead() ? -1 : 1;
            }
            if ($a->size() !== $b->size()) {
                return $b->size() <=> $a->size();
            }

            return $a->order() <=> $b->order();
        });

        return $resources;
    }

    /**
     * 估算指定 URL 的体积（供外部在得知 Content-Length 后回填）。
     *
     * @param string $url  资源 URL。
     * @param int    $size 实际字节数。
     */
    public function setActualSize(string $url, int $size): self
    {
        $url = trim($url);
        if ($url !== '') {
            $this->sizeMap[$url] = max(0, $size);
        }

        return $this;
    }

    /**
     * 取得默认图片宽高比。
     */
    public function defaultImageRatio(): float
    {
        return $this->defaultImageRatio;
    }
}
