<?php
/**
 * 懒加载与加载优先级建议生成器。
 *
 * 基于审计结果，为每个图片 / iframe / 媒体资源输出可直接落地的
 * HTML 属性建议（loading、fetchpriority、decoding、width/height），
 * 并给出优化后的标签片段。
 *
 * 判定规则：
 * - 首屏之内的第一张图片 → 不加 loading，且 fetchpriority="high"（候选 LCP）；
 * - 首屏之后的图片 → loading="lazy" + decoding="async"；
 * - 无法确定首屏范围时，index === 0 视为首屏主图；
 * - iframe 一律建议 loading="lazy"；
 * - 所有图片都建议补 width/height（占位可用 CSS aspect-ratio）。
 *
 * @package MornRain\PerfSentry
 */

declare(strict_types=1);

namespace MornRain\PerfSentry;

/**
 * 加载提示建议器。
 */
class LazyLoadHint
{
    /** @var bool 是否把首屏主图也标记为 lazy（默认不标记） */
    protected $lazyAboveFold = false;

    /** @var string 生成建议时使用的站点主域名 */
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
     * 是否把首屏主图也标记为 lazy。
     *
     * 开启后所有图片都会加 loading="lazy"，适合首屏无大图的列表页。
     */
    public function lazyAboveFold(bool $enabled = true): self
    {
        $this->lazyAboveFold = $enabled;

        return $this;
    }

    /**
     * 为整页 HTML 生成建议。
     *
     * @param string $html 页面 HTML。
     * @return array<string,mixed>
     */
    public function suggest(string $html): array
    {
        $auditor    = new ResourceAuditor($this->siteHost);
        $resources  = $auditor->audit($html);
        $suggestions = [];

        $imageIndex = 0;
        foreach ($resources as $resource) {
            switch ($resource->type()) {
                case Resource::TYPE_IMAGE:
                    $suggestions[] = $this->forImage($resource, $imageIndex);
                    $imageIndex++;
                    break;
                case Resource::TYPE_IFRAME:
                    $suggestions[] = $this->forIframe($resource);
                    break;
                case Resource::TYPE_SCRIPT:
                    if ($resource->isRenderBlocking()) {
                        $suggestions[] = $this->forScript($resource);
                    }
                    break;
                default:
                    break;
            }
        }

        $applicable = array_values(array_filter($suggestions, static function (array $item): bool {
            return $item['applicable'];
        }));

        return [
            'suggestions' => $suggestions,
            'applicable'  => $applicable,
            'count'       => count($applicable),
        ];
    }

    /**
     * 为单个图片资源生成建议。
     *
     * @param Resource $resource 图片资源。
     * @param int      $index    在图片序列中的序号（0 起）。
     * @return array<string,mixed>
     */
    public function forImage(Resource $resource, int $index = 0): array
    {
        $isAboveFold = $this->isLikelyAboveFold($resource, $index);
        $addLazy     = $this->lazyAboveFold ? true : !$isAboveFold;
        $isLcp       = $isAboveFold && $index === 0;

        $attrs = [];

        if ($addLazy && !$resource->isLazyLoaded()) {
            $attrs['loading'] = 'lazy';
        }
        if (!$resource->attribute('decoding') && $addLazy) {
            $attrs['decoding'] = 'async';
        }
        if ($isLcp && !$resource->hasFetchPriority()) {
            $attrs['fetchpriority'] = 'high';
        }
        if (!$resource->hasDimensions()) {
            $hint = '补 width/height，或用 CSS aspect-ratio 预留空间。';
        } else {
            $hint = '';
        }

        $applicable = $attrs !== [] || $hint !== '';

        return [
            'kind'        => 'image',
            'url'         => $resource->url(),
            'applicable'  => $applicable,
            'above_fold'  => $isAboveFold,
            'lcp_candidate' => $isLcp,
            'attributes'  => $attrs,
            'reason'      => $this->imageReason($isAboveFold, $isLcp, $addLazy),
            'dimension_hint' => $hint,
            'snippet'     => $applicable ? $this->renderTag('img', $resource, $attrs) : '',
        ];
    }

    /**
     * 为 iframe 生成建议。
     *
     * @param Resource $resource iframe 资源。
     * @return array<string,mixed>
     */
    public function forIframe(Resource $resource): array
    {
        $attrs = [];
        if (!$resource->isLazyLoaded()) {
            $attrs['loading'] = 'lazy';
        }
        if (!$resource->hasDimensions()) {
            $attrs['width']  = '640';
            $attrs['height'] = '360';
        }

        $applicable = $attrs !== [];

        return [
            'kind'       => 'iframe',
            'url'        => $resource->url(),
            'applicable' => $applicable,
            'attributes' => $attrs,
            'reason'     => $applicable
                ? 'iframe 是典型的延迟加载对象，加载前会占用主线程与带宽。'
                : '已具备懒加载与尺寸声明。',
            'snippet'    => $applicable ? $this->renderTag('iframe', $resource, $attrs) : '',
        ];
    }

    /**
     * 为阻塞脚本生成建议。
     *
     * @param Resource $resource 脚本资源。
     * @return array<string,mixed>
     */
    public function forScript(Resource $resource): array
    {
        if ($resource->isDeferred()) {
            return [
                'kind'       => 'script',
                'url'        => $resource->url(),
                'applicable' => false,
                'attributes' => [],
                'reason'     => '已使用 defer/async，不阻塞渲染。',
                'snippet'    => '',
            ];
        }

        $attrs = ['defer' => 'defer'];

        return [
            'kind'       => 'script',
            'url'        => $resource->url(),
            'applicable' => true,
            'attributes' => $attrs,
            'reason'     => 'head 内的同步脚本会阻塞 HTML 解析，加 defer 后可并行下载。',
            'snippet'    => $this->renderTag('script', $resource, $attrs),
        ];
    }

    /**
     * 判断资源是否位于首屏。
     *
     * 判据（按可靠性排序）：
     * 1. 显式位于 head → 首屏；
     * 2. URL 含 hero / banner / cover / logo / featured 等语义 → 首屏；
     * 3. 位于文档中靠前位置 → 首屏。
     *
     * @param Resource $resource 资源。
     * @param int      $index    序号。
     */
    protected function isLikelyAboveFold(Resource $resource, int $index): bool
    {
        if ($resource->isInHead()) {
            return true;
        }

        $url = strtolower($resource->url());
        foreach (['hero', 'banner', 'cover', 'featured', 'logo', 'masthead', 'top-', 'header'] as $keyword) {
            if (strpos($url, $keyword) !== false) {
                return true;
            }
        }

        // 仅第一张图默认视为首屏主图。
        return $index === 0;
    }

    /**
     * 生成图片建议的中文原因说明。
     *
     * @param bool $isAboveFold 是否首屏。
     * @param bool $isLcp       是否 LCP 候选。
     * @param bool $addLazy     是否建议加 lazy。
     */
    protected function imageReason(bool $isAboveFold, bool $isLcp, bool $addLazy): string
    {
        if ($isLcp) {
            return '判定为首屏主图（LCP 候选）：不要加 loading="lazy"，并用 fetchpriority="high" 提升优先级。';
        }
        if ($isAboveFold && !$addLazy) {
            return '判定为首屏图片：保持 eager 以免延后 LCP，但应声明 fetchpriority。';
        }

        return '首屏之外：加 loading="lazy" + decoding="async" 可显著降低首屏带宽与主线程占用。';
    }

    /**
     * 渲染带建议属性的标签片段。
     *
     * 只输出属性建议，不改写原有属性，便于人工比对。
     *
     * @param string              $tag      标签名。
     * @param Resource            $resource 资源。
     * @param array<string,string> $attrs    建议属性。
     */
    protected function renderTag(string $tag, Resource $resource, array $attrs): string
    {
        // img / script / iframe 的地址属性都叫 src
        $url = $resource->attribute('src');
        if ($url === '') {
            $url = $resource->url();
        }

        $parts = [$tag];
        if ($url !== '') {
            $parts[] = 'src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"';
        }
        foreach ($attrs as $name => $value) {
            $parts[] = $name . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
        }

        return '<' . implode(' ', $parts) . '>';
    }
}
