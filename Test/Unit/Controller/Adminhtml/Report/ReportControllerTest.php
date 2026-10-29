<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Controller\Adminhtml\Report;

use DmLab\PriceExplain\Api\ExplainerInterface;
use DmLab\PriceExplain\Controller\Adminhtml\Report\Index;
use DmLab\PriceExplain\Controller\Adminhtml\Report\View;
use DmLab\PriceExplain\Model\Report\Report;
use DmLab\PriceExplain\Model\Storefront\ReportTarget;
use DmLab\PriceExplain\Model\Storefront\TargetResolver;
use DmLab\PriceExplain\Model\Storefront\TokenService;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth;
use Magento\Backend\Block\Template;
use Magento\Backend\Helper\Data as BackendHelper;
use Magento\Backend\Model\View\Result\Page;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Url;
use Magento\Framework\View\LayoutInterface;
use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Title;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\User\Model\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ReportControllerTest extends TestCase
{
    /**
     * @var array{0: string, 1: array}|null
     */
    private ?array $redirectedTo = null;

    /**
     * @var string[]
     */
    private array $errors = [];

    /**
     * @var string[]
     */
    private array $titles = [];

    /**
     * @var array<string, mixed>
     */
    private array $params = [];

    /**
     * @var array{0: string, 1: array}|null
     */
    private ?array $urlBuilt = null;

    /**
     * @var array<int, array{0: string, 1: array}>
     */
    private array $adminUrls = [];

    /**
     * @var array<string, mixed>
     */
    private array $blockData = [];

    /**
     * @var int[]
     */
    private array $scopes = [];

    /**
     * @var array<int, array{0: string, 1: array}>
     */
    private array $frontendUrls = [];

    /**
     * @var ResultFactory&Stub
     */
    private ResultFactory $resultFactory;

    protected function setUp(): void
    {
        $this->resultFactory = $this->createStub(ResultFactory::class);
    }

    private function context(): Context
    {
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(
            fn (string $name, $default = null) => $this->params[$name] ?? $default
        );

        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(
            function (string $path, array $params = []) use ($redirect): Redirect {
                $this->redirectedTo = [$path, $params];
                return $redirect;
            }
        );
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $messages = $this->createStub(ManagerInterface::class);
        $messages->method('addErrorMessage')->willReturnCallback(function ($m) use ($messages) {
            $this->errors[] = (string)$m;
            return $messages;
        });

        $helper = $this->createStub(BackendHelper::class);
        $helper->method('getUrl')->willReturnCallback(function (string $route, array $params): string {
            $this->urlBuilt = [$route, $params];
            $this->adminUrls[] = [$route, $params];
            return 'https://admin/' . $route . '?' . http_build_query($params['_query'] ?? []);
        });

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getMessageManager')->willReturn($messages);
        $context->method('getResultFactory')->willReturn($this->resultFactory);
        $context->method('getHelper')->willReturn($helper);
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(3);
        $auth = $this->createStub(Auth::class);
        $auth->method('getUser')->willReturn($user);
        $context->method('getAuth')->willReturn($auth);

        return $context;
    }

    /**
     * @param ExplainerInterface|null $explainer
     * @param TargetResolver|null $resolver
     * @param TokenService|null $tokens
     * @param StoreManagerInterface|null $stores
     * @return View
     */
    private function view(
        ?ExplainerInterface $explainer = null,
        ?TargetResolver $resolver = null,
        ?TokenService $tokens = null,
        ?StoreManagerInterface $stores = null
    ): View {
        if ($stores === null) {
            $stores = $this->createStub(StoreManagerInterface::class);
            $stores->method('getStore')->willReturnCallback(fn ($id) => $this->store((int)$id));
        }
        $url = $this->createStub(Url::class);
        $url->method('setScope')->willReturnCallback(function ($store) use ($url) {
            $this->scopes[] = $store->getId();
            return $url;
        });
        $url->method('getUrl')->willReturnCallback(function (string $route, array $params): string {
            $this->frontendUrls[] = [$route, $params];
            return 'https://store/' . $route . '/';
        });

        return new View(
            $this->context(),
            $explainer ?? $this->createStub(ExplainerInterface::class),
            $resolver ?? $this->createStub(TargetResolver::class),
            $tokens ?? $this->createStub(TokenService::class),
            $stores,
            $url
        );
    }

    private function store(int $id): Store
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn($id);

        return $store;
    }

    /**
     * Shell block collecting addData() into $this->blockData.
     *
     * @return Template
     */
    private function shellBlock(): Template
    {
        $block = $this->createStub(Template::class);
        $block->method('addData')->willReturnCallback(function (array $data) use ($block) {
            $this->blockData = $data + $this->blockData;
            return $block;
        });

        return $block;
    }

    /**
     * @param string $method
     * @param mixed $argument
     * @param ReportTarget $target
     * @return TargetResolver&MockObject
     */
    private function resolver(string $method, mixed $argument, ReportTarget $target): TargetResolver
    {
        $resolver = $this->createMock(TargetResolver::class);
        $resolver->expects(self::once())->method($method)->with($argument)->willReturn($target);

        return $resolver;
    }

    private function page(?Template $block = null): Page
    {
        $title = $this->createStub(Title::class);
        $title->method('prepend')->willReturnCallback(function ($t): void {
            $this->titles[] = (string)$t;
        });
        $config = $this->createStub(Config::class);
        $config->method('getTitle')->willReturn($title);
        $block ??= $this->createStub(Template::class);
        $layout = $this->createStub(LayoutInterface::class);
        $layout->method('getBlock')->willReturnCallback(
            static fn (string $name) => $name === View::BLOCK_NAME ? $block : false
        );
        $page = $this->createMock(Page::class);
        $page->expects(self::once())->method('setActiveMenu')->with('DmLab_PriceExplain::explain')->willReturnSelf();
        $page->method('getConfig')->willReturn($config);
        $page->method('getLayout')->willReturn($layout);
        $this->resultFactory->method('create')->willReturnCallback(
            static fn (string $type): ?Page => $type === ResultFactory::TYPE_PAGE ? $page : null
        );

        return $page;
    }

    private function report(): Report
    {
        return new Report(42, 1, 1, 0, null, 'USD');
    }

    public function testAclResource(): void
    {
        self::assertSame('DmLab_PriceExplain::explain', Index::ADMIN_RESOURCE);
        self::assertSame('DmLab_PriceExplain::explain', View::ADMIN_RESOURCE);
    }

    public function testIndexRendersFormPage(): void
    {
        $page = $this->page();

        self::assertSame($page, (new Index($this->context()))->execute());
        self::assertSame(['Explain Prices'], $this->titles);
    }

    public function testViewByQuoteIdRendersStorefrontShell(): void
    {
        $this->params = ['quote_id' => '42'];
        $page = $this->page($this->shellBlock());
        $explainer = $this->createMock(ExplainerInterface::class);
        $explainer->expects(self::never())->method('explainQuote');
        $tokens = $this->createMock(TokenService::class);
        $tokens->expects(self::once())->method('issue')->with('quote', 42, false, 1, 3)->willReturn('signed.token');
        $resolver = $this->resolver('quote', 42, new ReportTarget('quote', 42, 1, 'Quote #42'));

        self::assertSame($page, $this->view($explainer, $resolver, $tokens)->execute());
        self::assertSame(['Price Explanation: Quote #42'], $this->titles);
        self::assertSame('signed.token', $this->blockData['token']);
        self::assertSame('https://store/dmlab_price_explain/report/json/', $this->blockData['endpoint_url']);
        self::assertSame(
            [['dmlab_price_explain/report/json', ['_nosid' => true, '_scope_to_url' => true]]],
            $this->frontendUrls
        );
        self::assertSame([1], $this->scopes, 'endpoint URL is built in the quote\'s store');
        self::assertSame(
            [
                'dmlab_price_explain/report/view',
                ['_query' => ['quote_id' => '42', 'all_rules' => 0, 'format' => 'json']],
            ],
            $this->urlBuilt
        );
        self::assertStringContainsString('format=json', $this->blockData['fallback_url']);
        self::assertSame('price-explain-quote-id-42.json', $this->blockData['file_name']);
        self::assertSame('', $this->blockData['order_view_url'], 'no order link for a quote');
        self::assertStringNotContainsString('signed.token', $this->blockData['endpoint_url']);
        self::assertStringNotContainsString('signed.token', $this->blockData['fallback_url']);
        self::assertSame([], $this->errors);
    }

    public function testViewByOrderIdWithAllRulesIssuesOrderTokenForOrderStore(): void
    {
        $this->params = ['order_id' => '7', 'all_rules' => '1'];
        $page = $this->page($this->shellBlock());
        $tokens = $this->createMock(TokenService::class);
        $tokens->expects(self::once())->method('issue')->with('order', 7, true, 2, 3)->willReturn('t');
        $resolver = $this->resolver('order', 7, new ReportTarget('order', 7, 2, 'Order #000000007'));

        self::assertSame($page, $this->view(null, $resolver, $tokens)->execute());
        self::assertSame(['Price Explanation: Order #000000007'], $this->titles);
        self::assertSame([2], $this->scopes);
        self::assertStringContainsString('all_rules=1', $this->blockData['fallback_url']);
        self::assertSame('https://admin/sales/order/view?', $this->blockData['order_view_url']);
        self::assertContains(['sales/order/view', ['order_id' => 7]], $this->adminUrls);
    }

    public function testViewByIncrementIdTrimsInput(): void
    {
        $this->params = ['increment_id' => ' 000000012 ', 'quote_id' => ''];
        $page = $this->page($this->shellBlock());
        $resolver = $this->resolver('orderByIncrementId', '000000012', new ReportTarget('order', 12, 1, 'Order #12'));

        self::assertSame($page, $this->view(null, $resolver)->execute());
        self::assertSame('price-explain-increment-id-000000012.json', $this->blockData['file_name']);
        self::assertContains(['sales/order/view', ['order_id' => 12]], $this->adminUrls, 'links by entity id');
    }

    public function testJsonFormatReturnsAttachment(): void
    {
        $this->params = ['quote_id' => '42', 'format' => 'json'];
        $headers = [];
        $contents = null;
        $raw = $this->createStub(Raw::class);
        $raw->method('setHeader')->willReturnCallback(function ($name, $value) use (&$headers, $raw) {
            $headers[$name] = $value;
            return $raw;
        });
        $raw->method('setContents')->willReturnCallback(function ($value) use (&$contents, $raw) {
            $contents = $value;
            return $raw;
        });
        $this->resultFactory->method('create')->willReturnCallback(
            static fn (string $type): ?Raw => $type === ResultFactory::TYPE_RAW ? $raw : null
        );
        $report = $this->report();
        $explainer = $this->createMock(ExplainerInterface::class);
        $explainer->expects(self::once())->method('explainQuote')->with(42, false)->willReturn($report);
        $tokens = $this->createMock(TokenService::class);
        $tokens->expects(self::never())->method('issue');
        $resolver = $this->resolver('quote', 42, new ReportTarget('quote', 42, 1, 'Quote #42'));

        self::assertSame($raw, $this->view($explainer, $resolver, $tokens)->execute());
        self::assertSame('application/json', $headers['Content-Type']);
        self::assertSame('attachment; filename="price-explain-quote-id-42.json"', $headers['Content-Disposition']);
        self::assertSame($report->toArray(), json_decode((string)$contents, true));
    }

    public function testJsonFileNameIsSanitised(): void
    {
        $this->params = ['increment_id' => 'A/1"x', 'format' => 'json'];
        $headers = [];
        $raw = $this->createStub(Raw::class);
        $raw->method('setHeader')->willReturnCallback(function ($name, $value) use (&$headers, $raw) {
            $headers[$name] = $value;
            return $raw;
        });
        $raw->method('setContents')->willReturnSelf();
        $this->resultFactory->method('create')->willReturn($raw);
        $explainer = $this->createStub(ExplainerInterface::class);
        $explainer->method('explainOrderById')->willReturn($this->report());
        $resolver = $this->resolver('orderByIncrementId', 'A/1"x', new ReportTarget('order', 7, 1, 'Order #A/1"x'));

        $this->view($explainer, $resolver)->execute();

        self::assertSame(
            'attachment; filename="price-explain-increment-id-A_1_x.json"',
            $headers['Content-Disposition']
        );
    }

    /**
     * JSON result stub recording status and data.
     *
     * @param array{status: int|null, data: mixed} $recorded
     * @return Json
     */
    private function jsonResult(array &$recorded): Json
    {
        $json = $this->createStub(Json::class);
        $json->method('setHttpResponseCode')->willReturnCallback(function ($code) use (&$recorded, $json) {
            $recorded['status'] = $code;
            return $json;
        });
        $json->method('setData')->willReturnCallback(function ($data) use (&$recorded, $json) {
            $recorded['data'] = $data;
            return $json;
        });
        $this->resultFactory->method('create')->willReturnCallback(
            static fn (string $type): ?Json => $type === ResultFactory::TYPE_JSON ? $json : null
        );

        return $json;
    }

    /**
     * @return array<string, array{0: LocalizedException, 1: int}>
     */
    public static function jsonErrors(): array
    {
        return [
            'not found' => [new NoSuchEntityException(__('Quote with ID "%1" does not exist.', 5)), 404],
            'localized' => [new LocalizedException(__('Only one price explanation can run per request.')), 422],
        ];
    }

    #[DataProvider('jsonErrors')]
    public function testJsonFormatExplainerErrorIsJson(LocalizedException $exception, int $status): void
    {
        $this->params = ['quote_id' => '5', 'format' => 'json'];
        $recorded = ['status' => null, 'data' => null];
        $json = $this->jsonResult($recorded);
        $explainer = $this->createStub(ExplainerInterface::class);
        $explainer->method('explainQuote')->willThrowException($exception);
        $resolver = $this->createStub(TargetResolver::class);
        $resolver->method('quote')->willReturn(new ReportTarget('quote', 5, 1, 'Quote #5'));

        self::assertSame($json, $this->view($explainer, $resolver)->execute());
        self::assertSame($status, $recorded['status']);
        self::assertSame(['error' => $exception->getMessage()], $recorded['data']);
        self::assertNull($this->redirectedTo);
    }

    #[DataProvider('jsonErrors')]
    public function testJsonFormatResolverErrorIsJson(LocalizedException $exception, int $status): void
    {
        $this->params = ['increment_id' => '000000005', 'format' => 'json'];
        $recorded = ['status' => null, 'data' => null];
        $json = $this->jsonResult($recorded);
        $explainer = $this->createMock(ExplainerInterface::class);
        $explainer->expects(self::never())->method(self::anything());
        $resolver = $this->createStub(TargetResolver::class);
        $resolver->method('orderByIncrementId')->willThrowException($exception);

        self::assertSame($json, $this->view($explainer, $resolver)->execute());
        self::assertSame($status, $recorded['status']);
        self::assertSame(['error' => $exception->getMessage()], $recorded['data']);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string, 2: mixed, 3: ReportTarget, 4: string}>
     */
    public static function jsonSources(): array
    {
        return [
            'quote' => [
                ['quote_id' => '42'], 'quote', 42, new ReportTarget('quote', 42, 1, 'Quote #42'), 'explainQuote',
            ],
            'order id' => [
                ['order_id' => '7'], 'order', 7, new ReportTarget('order', 7, 1, 'Order #1'), 'explainOrderById',
            ],
            'increment id' => [
                ['increment_id' => '000000012'], 'orderByIncrementId', '000000012',
                new ReportTarget('order', 12, 2, 'Order #000000012'), 'explainOrderById',
            ],
        ];
    }

    #[DataProvider('jsonSources')]
    public function testJsonFormatExplainsTheResolvedTarget(
        array $params,
        string $resolverMethod,
        mixed $argument,
        ReportTarget $target,
        string $explainerMethod
    ): void {
        $this->params = $params + ['all_rules' => '1', 'format' => 'json'];
        $raw = $this->createStub(Raw::class);
        $raw->method('setHeader')->willReturnSelf();
        $raw->method('setContents')->willReturnSelf();
        $this->resultFactory->method('create')->willReturn($raw);
        $explainer = $this->createMock(ExplainerInterface::class);
        $explainer->expects(self::once())->method($explainerMethod)->with($target->getId(), true)
            ->willReturn($this->report());

        $resolver = $this->resolver($resolverMethod, $argument, $target);

        self::assertSame($raw, $this->view($explainer, $resolver)->execute());
    }

    public function testJsonFormatInvalidInputIsJson400(): void
    {
        $this->params = ['quote_id' => 'abc', 'format' => 'json'];
        $recorded = ['status' => null, 'data' => null];
        $json = $this->jsonResult($recorded);

        self::assertSame($json, $this->view()->execute());
        self::assertSame(400, $recorded['status']);
        self::assertSame(['error' => 'Enter either a quote ID or an order number.'], $recorded['data']);
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function invalidInput(): array
    {
        return [
            'nothing' => [[]],
            'blank' => [['quote_id' => '  ', 'increment_id' => '']],
            'both' => [['quote_id' => '1', 'increment_id' => '000000001']],
            'quote and order id' => [['quote_id' => '1', 'order_id' => '2']],
            'non-numeric quote' => [['quote_id' => 'abc']],
            'negative order id' => [['order_id' => '-3']],
            'zero quote' => [['quote_id' => '0']],
        ];
    }

    /**
     * @param array<string, string> $params
     */
    #[DataProvider('invalidInput')]
    public function testInvalidInputRedirectsToForm(array $params): void
    {
        $this->params = $params;
        $resolver = $this->createMock(TargetResolver::class);
        $resolver->expects(self::never())->method(self::anything());
        $tokens = $this->createMock(TokenService::class);
        $tokens->expects(self::never())->method('issue');

        $result = $this->view(null, $resolver, $tokens)->execute();

        self::assertInstanceOf(Redirect::class, $result);
        self::assertSame(['dmlab_price_explain/report/index', []], $this->redirectedTo);
        self::assertSame(['Enter either a quote ID or an order number.'], $this->errors);
    }

    /**
     * @return array<string, array{0: LocalizedException}>
     */
    public static function resolverErrors(): array
    {
        return [
            'not found' => [new NoSuchEntityException(__('Quote with ID "%1" does not exist.', 5))],
            'ambiguous' => [new LocalizedException(__('Order number "%1" exists in several stores.', 5))],
        ];
    }

    #[DataProvider('resolverErrors')]
    public function testResolverErrorRedirectsWithMessage(LocalizedException $exception): void
    {
        $this->params = ['quote_id' => '5'];
        $resolver = $this->createStub(TargetResolver::class);
        $resolver->method('quote')->willThrowException($exception);
        $tokens = $this->createMock(TokenService::class);
        $tokens->expects(self::never())->method('issue');

        $result = $this->view(null, $resolver, $tokens)->execute();

        self::assertInstanceOf(Redirect::class, $result);
        self::assertSame(['dmlab_price_explain/report/index', []], $this->redirectedTo);
        self::assertSame([$exception->getMessage()], $this->errors);
    }

    public function testMissingStoreRedirectsWithMessage(): void
    {
        $this->params = ['quote_id' => '5'];
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willThrowException(new NoSuchEntityException(__('Store 9 is gone.')));
        $resolver = $this->createStub(TargetResolver::class);
        $resolver->method('quote')->willReturn(new ReportTarget('quote', 5, 9, 'Quote #5'));

        $result = $this->view(null, $resolver, null, $stores)->execute();

        self::assertInstanceOf(Redirect::class, $result);
        self::assertCount(1, $this->errors);
    }
}
