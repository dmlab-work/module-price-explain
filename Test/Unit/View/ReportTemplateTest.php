<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\View;

use Magento\Backend\Block\Template;
use Magento\Framework\Escaper;
use PHPUnit\Framework\TestCase;

/**
 * Renders the admin templates with stub blocks to check the report shell and escaping.
 */
class ReportTemplateTest extends TestCase
{
    private const TEMPLATES = __DIR__ . '/../../../view/adminhtml/templates/';
    private const WEB = __DIR__ . '/../../../view/adminhtml/web/';

    /**
     * @param string $file
     * @param array<string, mixed> $data
     * @return string
     */
    private function render(string $file, array $data): string
    {
        $block = $this->createStub(Template::class);
        $block->method('getData')->willReturnCallback(static fn (string $key) => $data[$key] ?? null);
        $block->method('getUrl')->willReturnCallback(static fn (string $route): string => 'https://admin/' . $route);
        $escaper = $this->escaper();

        return (static function (string $path, Template $block, Escaper $escaper): string {
            ob_start();
            include $path;
            return (string)ob_get_clean();
        })(self::TEMPLATES . $file, $block, $escaper);
    }

    private function escaper(): Escaper
    {
        $escape = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback($escape);
        $escaper->method('escapeHtmlAttr')->willReturnCallback($escape);
        $escaper->method('escapeUrl')->willReturnCallback($escape);

        return $escaper;
    }

    public function testShellPassesEndpointTokenAndFallbackToTheComponent(): void
    {
        $html = $this->render('report.phtml', [
            'endpoint_url' => 'https://store/dmlab_price_explain/report/json/?a="><script>',
            'token' => 'payload.sig',
            'fallback_url' => 'https://admin/view?quote_id=42&format=json',
            'file_name' => 'price-explain-quote-id-42.json',
            'order_view_url' => 'https://admin/sales/order/view/order_id/7/',
        ]);

        self::assertMatchesRegularExpression('/data-mage-init="([^"]+)"/', $html);
        preg_match('/data-mage-init="([^"]+)"/', $html, $m);
        self::assertSame(
            [
                'DmLab_PriceExplain/js/report' => [
                    'endpointUrl' => 'https://store/dmlab_price_explain/report/json/?a="><script>',
                    'token' => 'payload.sig',
                    'fallbackUrl' => 'https://admin/view?quote_id=42&format=json',
                    'fileName' => 'price-explain-quote-id-42.json',
                    'orderViewUrl' => 'https://admin/sales/order/view/order_id/7/',
                ],
            ],
            json_decode(html_entity_decode($m[1], ENT_QUOTES), true)
        );
        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('href="https://admin/dmlab_price_explain/report/index"', $html);
        self::assertStringContainsString('data-role="download" disabled', $html);
        self::assertStringContainsString('data-role="sections"', $html);
        self::assertStringContainsString('Running the explanation on the storefront…', $html);
        self::assertStringNotContainsString('payload.sig', strip_tags($html), 'token only inside the attribute');
    }

    public function testShellWithoutTokenRendersNothing(): void
    {
        self::assertSame('', trim($this->render('report.phtml', ['endpoint_url' => 'https://store/'])));
    }

    public function testTemplatesEscapeEveryOutput(): void
    {
        foreach (glob(self::TEMPLATES . '*.phtml') ?: [] as $file) {
            preg_match_all('/<\?=\s*(.*?)\?>/s', (string)file_get_contents($file), $m);
            foreach ($m[1] as $expression) {
                self::assertMatchesRegularExpression('/^\$escaper->escape(Html|HtmlAttr|Url)\(/', $expression, $file);
            }
        }
    }

    public function testFormPostsToViewWithAllInputs(): void
    {
        $html = $this->render('form.phtml', []);

        self::assertStringContainsString('method="get"', $html);
        self::assertStringContainsString('action="https://admin/dmlab_price_explain/report/view"', $html);
        foreach (['quote_id', 'increment_id', 'all_rules'] as $name) {
            self::assertStringContainsString('name="' . $name . '"', $html);
        }
    }

    public function testTreeTogglesHaveAnExplicitMarkerOverTheAdminReset(): void
    {
        $css = (string)file_get_contents(self::WEB . 'css/report.css');

        self::assertMatchesRegularExpression('/details > summary \{[^}]*list-style: none;[^}]*cursor: pointer;/', $css);
        self::assertStringContainsString('details > summary::-webkit-details-marker { display: none; }', $css);
        self::assertMatchesRegularExpression('/\.dmlab-pe-summary \{[^}]*color: #007bdb;/', $css);
        self::assertStringContainsString(".dmlab-pe-summary::before { content: '\\25B8\\00a0'; }", $css);
        self::assertStringContainsString(
            "details[open] > summary .dmlab-pe-summary::before { content: '\\25BE\\00a0'; }",
            $css
        );
    }

    public function testTreesOpenAtTheFailingStageAndSkipEmptyTrees(): void
    {
        $js = (string)file_get_contents(self::WEB . 'js/report.js');

        self::assertStringContainsString("rule.status === 'rejected' && rule.stage === 'conditions'", $js);
        self::assertStringContainsString("!data.applied && data.stage === 'actions'", $js);
        self::assertStringContainsString('return !!node && node.children.length > 0;', $js);
        self::assertStringContainsString('if (!hasTree(data.actions)) {', $js);
        self::assertStringContainsString('hasTree(rule.conditions) ? tree(', $js);
        self::assertStringNotContainsString("el('summary', null, [title])", $js, 'toggle text is the marked span');
    }
}
