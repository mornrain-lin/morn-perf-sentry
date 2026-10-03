<?php
/**
 * 缓存策略建议器测试。
 *
 * @package MornRain\PerfSentry\Tests
 */

declare(strict_types=1);

namespace MornRain\PerfSentry\Tests;

use MornRain\PerfSentry\CachePolicySuggestor;
use MornRain\PerfSentry\Resource;

/**
 * CachePolicySuggestor 测试。
 */
class CachePolicySuggestorTest extends TestCase
{
    /**
     * 构造一份含指纹与非指纹资源的页面。
     */
    private function html(): string
    {
        return <<<'HTML'
<!DOCTYPE html><html><head>
<link rel="stylesheet" href="https://example.com/app.abc12345.css">
<link rel="stylesheet" href="https://example.com/plain.css">
<script src="https://cdn.other.com/w.js"></script>
</head><body>
<img src="https://example.com/photo.jpg" width="10" height="10">
<iframe src="https://example.com/e"></iframe>
</body></html>
HTML;
    }

    /**
     * 在建议列表中查找指定 URL。
     *
     * @return array<string,mixed>|null
     */
    private function findItem(CachePolicySuggestor $suggester, string $url): ?array
    {
        foreach ($suggester->suggest($this->html())['items'] as $item) {
            if ($item['url'] === $url) {
                return $item;
            }
        }

        return null;
    }

    public function testFingerprintDetection(): void
    {
        $suggester = new CachePolicySuggestor('example.com');

        $fingerprinted = $this->findItem($suggester, 'https://example.com/app.abc12345.css');
        self::assertNotNull($fingerprinted);
        self::assertTrue($fingerprinted['fingerprint'], '内容哈希文件名应识别为指纹资源');
        self::assertSame(CachePolicySuggestor::ONE_YEAR, $fingerprinted['max_age']);

        $plain = $this->findItem($suggester, 'https://example.com/plain.css');
        self::assertNotNull($plain);
        self::assertFalse($plain['fingerprint'], '无版本标识不应识别为指纹');
    }

    public function testVersionQueryCountsAsFingerprint(): void
    {
        // 必须给 siteHost，否则会被判为第三方而只给 1 天缓存
        $suggester = new CachePolicySuggestor('e.com');
        $items     = $suggester->suggest('<link rel="stylesheet" href="https://e.com/a.css?ver=1.0">')['items'];

        self::assertTrue($items[0]['fingerprint']);
        self::assertSame(CachePolicySuggestor::ONE_YEAR, $items[0]['max_age']);
    }

    public function testInlineResourcesAreSkipped(): void
    {
        $suggester = new CachePolicySuggestor();
        $item      = $suggester->forResource(new Resource('', Resource::TYPE_SCRIPT, 'script', 100));

        self::assertNull($item, '内联资源无需缓存建议');
    }

    public function testHtmlRuleHasNoFakeEtag(): void
    {
        $rule = (new CachePolicySuggestor())->forHtml();

        // ETag 必须由服务端实时计算，给占位值会被直接复制进配置导致缓存失效
        self::assertArrayNotHasKey('ETag', $rule['headers']);
        self::assertStringContains('no-cache', $rule['headers']['Cache-Control']);
        self::assertStringContains('ETag', implode(' ', $rule['actions']), '应在建议中说明如何计算 ETag');
    }

    public function testEtagUsesStrongHash(): void
    {
        $item = $this->findItem(new CachePolicySuggestor('example.com'), 'https://example.com/plain.css');
        self::assertNotNull($item);

        // 20 位十六进制（sha256 截断），而不是 md5 的 32 位
        $etag = trim($item['headers']['ETag'], '"');
        self::assertSame(20, strlen($etag));
        self::assertSame(1, preg_match('/^[0-9a-f]{20}$/', $etag));
    }

    public function testThirdPartyGetsShortMaxAge(): void
    {
        $suggester = new CachePolicySuggestor('example.com');
        $items     = $suggester->suggest($this->html())['items'];

        $found = false;
        foreach ($items as $item) {
            if (strpos($item['url'], 'cdn.other.com') !== false) {
                $found = true;
                self::assertTrue($item['third_party']);
                self::assertSame(CachePolicySuggestor::ONE_DAY, $item['max_age']);
            }
        }
        self::assertTrue($found, '应包含第三方脚本建议');
    }

    public function testFingerprintedPolicyIsImmutable(): void
    {
        $item = $this->findItem(new CachePolicySuggestor('example.com'), 'https://example.com/app.abc12345.css');
        self::assertNotNull($item);
        self::assertStringContains('immutable', $item['headers']['Cache-Control']);
    }

    public function testNonFingerprintedPolicyRequiresRevalidate(): void
    {
        $item = $this->findItem(new CachePolicySuggestor('example.com'), 'https://example.com/plain.css');
        self::assertNotNull($item);
        self::assertStringContains('must-revalidate', $item['headers']['Cache-Control']);
    }

    public function testRelativeUrlIsNotThirdParty(): void
    {
        // 相对路径拿不到 host，不能因此被判成站外资源
        $resource = new Resource('/wp-content/themes/a/style.css', Resource::TYPE_STYLESHEET, 'link');

        self::assertFalse($resource->isThirdParty('example.com'));
        self::assertFalse($resource->isThirdParty(''));
    }

    public function testProtocolRelativeUrlResolvesHost(): void
    {
        $resource = new Resource('//cdn.other.com/a.js', Resource::TYPE_SCRIPT, 'script');

        self::assertTrue($resource->isThirdParty('example.com'));
    }

    public function testServerConfigRequiresFingerprint(): void
    {
        $suggestor = new CachePolicySuggestor();

        $noFingerprint = $suggestor->serverConfig(Resource::TYPE_SCRIPT, false);
        self::assertStringContains('建议先改造 URL', $noFingerprint);

        $fingerprinted = $suggestor->serverConfig(Resource::TYPE_SCRIPT, true);
        self::assertStringContains('immutable', $fingerprinted);
        self::assertStringContains('js', $fingerprinted);
    }

    public function testServerConfigCoversAllTypes(): void
    {
        $suggestor = new CachePolicySuggestor();

        foreach ([
            Resource::TYPE_STYLESHEET,
            Resource::TYPE_SCRIPT,
            Resource::TYPE_FONT,
            Resource::TYPE_IMAGE,
            Resource::TYPE_MEDIA,
        ] as $type) {
            $config = $suggestor->serverConfig($type, true);
            self::assertStringContains('location', $config, "类型 {$type} 应有 location 配置");
        }
    }

    public function testNotesAreNotEmpty(): void
    {
        self::assertTrue(count((new CachePolicySuggestor())->notes()) > 0);
    }

    public function testEmptyHtmlYieldsNoItems(): void
    {
        $result = (new CachePolicySuggestor())->suggest('');

        self::assertSame(0, $result['count']);
    }
}
