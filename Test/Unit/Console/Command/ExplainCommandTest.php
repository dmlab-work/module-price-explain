<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Console\Command;

use DmLab\PriceExplain\Api\ExplainerInterface;
use DmLab\PriceExplain\Console\Command\ExplainCommand;
use DmLab\PriceExplain\Console\ReportPrinter;
use DmLab\PriceExplain\Model\Report\CollectorStep;
use DmLab\PriceExplain\Model\Report\ConditionNode;
use DmLab\PriceExplain\Model\Report\FreeShipping;
use DmLab\PriceExplain\Model\Report\ItemPrice;
use DmLab\PriceExplain\Model\Report\OrderContext;
use DmLab\PriceExplain\Model\Report\PriceLayer;
use DmLab\PriceExplain\Model\Report\QuoteScope;
use DmLab\PriceExplain\Model\Report\Report;
use DmLab\PriceExplain\Model\Report\RuleItemOutcome;
use DmLab\PriceExplain\Model\Report\RuleVerdict;
use DmLab\PriceExplain\Model\Report\Stage;
use DmLab\PriceExplain\Model\Report\Status;
use DmLab\PriceExplain\Model\Report\TaxBreakdown;
use DmLab\PriceExplain\Model\Report\TaxLine;
use DmLab\PriceExplain\Model\Report\Totals;
use DmLab\PriceExplain\Model\Report\Warning;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ExplainCommandTest extends TestCase
{
    private function tester(ExplainerInterface $explainer, ?State $appState = null): CommandTester
    {
        return new CommandTester(
            new ExplainCommand($explainer, new ReportPrinter(), $appState ?? $this->createStub(State::class))
        );
    }

    private function report(): Report
    {
        $subtotal = new ConditionNode('1--1', 'Subtotal equals or greater than 100', false, true, null, '100', 93.6);
        $sku = new ConditionNode('1--1', 'SKU is MJ01', false, true, null, 'MJ01', 'MH01-M-Red');

        return new Report(
            42,
            1,
            1,
            1,
            'SPRING',
            'USD',
            2,
            [new ItemPrice(
                10,
                'MH01-M-Red',
                'Chaz <Kangeroo> Hoodie',
                2.0,
                55,
                [
                    new PriceLayer('base', 52.0),
                    new PriceLayer('special', 45.0, false, false, ['from' => '2026-01-01', 'to' => '2026-02-01']),
                    new PriceLayer('catalog_rule', 46.8, true, true, [], ['Spring 10%']),
                ],
                46.8,
                47.8,
                null,
                1.0,
                ['Vendor\\Pricing\\Observer'],
                'MH01 → MH01-M-Red'
            )],
            [new CollectorStep(
                'discount',
                'Magento\\SalesRule\\Model\\Quote\\Discount',
                false,
                'shipping',
                ['discount' => -9.36, 'grand' => -9.36],
                [],
                300
            ),
                new CollectorStep('vendor_fee', 'Vendor\\Fee\\Total', true, 'shipping', []),
                new CollectorStep('subtotal', 'Magento\\Quote\\Total\\Subtotal', false, 'billing', [], [], 100),
                new CollectorStep('grand_total', 'Magento\\Quote\\Total\\Grand', false, 'billing', [], [], 550),
            ],
            [
                new RuleVerdict(
                    7,
                    'Free over 100',
                    0,
                    Status::REJECTED,
                    Stage::CONDITIONS,
                    'Conditions are not met.',
                    [],
                    null,
                    new ConditionNode('1', 'If ALL of these conditions are TRUE', false, true, 'all', null, null, [
                        $subtotal,
                    ])
                ),
                new RuleVerdict(
                    3,
                    'Spring',
                    1,
                    Status::APPLIED,
                    null,
                    null,
                    [
                        new RuleItemOutcome(10, 'MH01-M-Red', true, null, null, 9.36, null, null, 'MH01 → MH01-M-Red'),
                        new RuleItemOutcome(20, 'BNDL-A-B', true, null, null, 1.0, null, null, 'BNDL', [
                            new RuleItemOutcome(21, 'A', true, null, null, 1.0, null, null, 'BNDL → A'),
                            new RuleItemOutcome(
                                22,
                                'B',
                                false,
                                Stage::ACTIONS,
                                'Item does not match the actions.',
                                null,
                                null,
                                new ConditionNode('1', 'If ALL', false, true, 'all', null, null, [$sku]),
                                'BNDL → B'
                            ),
                        ]),
                        new RuleItemOutcome(
                            11,
                            'MJ01',
                            false,
                            Stage::ACTIONS,
                            'Item does not match the actions.',
                            null,
                            null,
                            new ConditionNode('1', 'If ALL', false, true, 'all', null, null, [$sku])
                        ),
                    ],
                    9.36,
                    null,
                    true,
                    FreeShipping::MODE_ADDRESS,
                    5.0,
                    FreeShipping::NOT_HONOURED
                ),
                new RuleVerdict(9, 'Other site', 0, Status::NOT_CANDIDATE, Stage::WEBSITE, 'Rule is for website 2.'),
            ],
            new TaxBreakdown(
                'shipping',
                'TOTAL_BASE_CALCULATION',
                'Retail Customer',
                ['source' => 'shipping', 'country_id' => 'US', 'region_id' => 57, 'postcode' => '78701'],
                ['price_includes_tax' => false, 'cross_border_trade_enabled' => true],
                [
                    new TaxLine(TaxLine::TYPE_ITEM, 10, 'MH01-M-Red', 'Taxable Goods', 6.18, [
                        ['code' => 'US-TX', 'title' => 'TX', 'percent' => 8.25, 'amount' => 6.18],
                    ]),
                    new TaxLine(TaxLine::TYPE_SHIPPING, null, 'shipping', 'None', 0.0, [], 'No tax class.'),
                ],
                [['code' => 'US-TX', 'title' => 'TX', 'percent' => 8.25, 'amount' => 6.18]]
            ),
            [new Warning('index_mismatch', 'Indexed final price 50.00 differs from computed 46.80.')],
            new FreeShipping(
                FreeShipping::NOT_HONOURED,
                'Flat Rate ignores shipment-level free shipping and charges 10.00; enable Free Shipping <b>now</b>.',
                [['id' => 3, 'name' => 'Spring', 'mode' => FreeShipping::MODE_ADDRESS]],
                true,
                [],
                0.0,
                2.0,
                'flatrate_flatrate',
                10.0,
                FreeShipping::SEMANTICS_ITEMS,
                false,
                null,
                '2026-10-28 10:00:00',
                '2026-10-28 11:00:00'
            ),
            new QuoteScope('Default <Store> View', 'Main Website', 'General', 5, 'roni@example.com', 'Veronica C')
        );
    }

    public function testNameAndOptions(): void
    {
        $command = new ExplainCommand(
            $this->createStub(ExplainerInterface::class),
            new ReportPrinter(),
            $this->createStub(State::class)
        );

        self::assertSame('dmlab:price:explain', $command->getName());
        $definition = $command->getDefinition();
        foreach (['quote', 'order', 'format', 'all-rules', 'all-collectors'] as $option) {
            self::assertTrue($definition->hasOption($option), $option);
        }
        self::assertSame('text', $definition->getOption('format')->getDefault());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidOptions(): array
    {
        return [
            'none' => [[], 'exactly one'],
            'both' => [['--quote' => '1', '--order' => '000000001'], 'exactly one'],
            'quote not numeric' => [['--quote' => 'abc'], 'positive integer'],
            'quote zero' => [['--quote' => '0'], 'positive integer'],
            'quote negative' => [['--quote' => '-5'], 'positive integer'],
            'order blank' => [['--order' => '  '], 'must not be empty'],
            'bad format' => [['--quote' => '1', '--format' => 'xml'], 'text or json'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsFail(array $input, string $message): void
    {
        $explainer = $this->createMock(ExplainerInterface::class);
        $explainer->expects(self::never())->method('explainQuote');
        $explainer->expects(self::never())->method('explainOrder');
        $tester = $this->tester($explainer);

        self::assertSame(Command::FAILURE, $tester->execute($input));
        self::assertStringContainsString($message, $tester->getDisplay());
    }

    public function testQuoteJsonOutputIsReportArray(): void
    {
        $report = $this->report();
        $explainer = $this->createMock(ExplainerInterface::class);
        $explainer->expects(self::once())->method('explainQuote')->with(42, true)->willReturn($report);
        $appState = $this->createMock(State::class);
        $appState->expects(self::once())->method('setAreaCode')->with('frontend');
        $tester = $this->tester($explainer, $appState);

        self::assertSame(
            Command::SUCCESS,
            $tester->execute(['--quote' => '42', '--format' => 'json', '--all-rules' => true])
        );
        self::assertSame(
            $report->toArray(),
            json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR)
        );
        self::assertStringContainsString('Chaz <Kangeroo> Hoodie', $tester->getDisplay());
    }

    public function testOrderModeUsesIncrementId(): void
    {
        $explainer = $this->createMock(ExplainerInterface::class);
        $explainer->expects(self::once())->method('explainOrder')->with('000000007', false)
            ->willReturn(new Report(5, 1, 1, 0, null, 'EUR'));
        $tester = $this->tester($explainer);

        self::assertSame(Command::SUCCESS, $tester->execute(['--order' => ' 000000007 ']));
        $display = $tester->getDisplay(true);
        self::assertStringContainsString('Quote 5 · store #1 · website #1 · customer group #0', $display);
        self::assertStringContainsString("\ncoupon none · currency EUR", $display);
        self::assertStringContainsString('No items.', $display);
        self::assertStringContainsString('No collectors traced.', $display);
        self::assertStringContainsString('No cart price rules.', $display);
        self::assertStringContainsString('No tax information.', $display);
        self::assertStringContainsString("Warnings\nNone.", $display);
    }

    public function testOrderReportHeaderNamesTheOrder(): void
    {
        $report = (new Report(3, 1, 1, 0, null, 'USD', 2, [], [], [], null, [], null, new QuoteScope(
            'Default Store View',
            'Main Website',
            'General'
        )))->withOrder(new OrderContext('000000003', 3, '2026-10-04 00:33:26', 'pending'));

        $display = $this->renderText($report);

        self::assertStringContainsString(
            'Order 000000003 (quote 3) · placed 2026-10-04 00:33:26 · store Default Store View (#1) · website',
            $display
        );
        self::assertStringNotContainsString('Quote 3 ·', $display);
    }

    public function testOrderReportHeaderWithoutPlacementDate(): void
    {
        $report = (new Report(3, 1, 1, 0, null, 'USD'))->withOrder(new OrderContext('000000003', 3));

        self::assertStringContainsString('Order 000000003 (quote 3) · store #1 ·', $this->renderText($report));
    }

    public function testAreaAlreadySetIsTolerated(): void
    {
        $explainer = $this->createStub(ExplainerInterface::class);
        $explainer->method('explainQuote')->willReturn(new Report(1, 1, 1, 0, null, 'USD'));
        $appState = $this->createStub(State::class);
        $appState->method('setAreaCode')->willThrowException(new LocalizedException(new Phrase('Area set')));

        self::assertSame(Command::SUCCESS, $this->tester($explainer, $appState)->execute(['--quote' => '1']));
    }

    public function testNotFoundFails(): void
    {
        $explainer = $this->createStub(ExplainerInterface::class);
        $explainer->method('explainOrder')
            ->willThrowException(new NoSuchEntityException(new Phrase('Order "<x>" does not exist.')));
        $tester = $this->tester($explainer);

        self::assertSame(Command::FAILURE, $tester->execute(['--order' => 'x']));
        self::assertStringContainsString('Order "<x>" does not exist.', $tester->getDisplay());
    }

    public function testTextRendersItemWaterfall(): void
    {
        $display = $this->renderText();

        self::assertStringContainsString('Item prices (base currency)', $display);
        self::assertStringContainsString('MH01 → MH01-M-Red "Chaz <Kangeroo> Hoodie" × 2 (product 55)', $display);
        self::assertMatchesRegularExpression('/Base price\s*\|\s*52\.00\s*\|\s*Active\s*\|/', $display);
        self::assertMatchesRegularExpression(
            '/Special price\s*\|\s*45\.00\s*\|\s*Inactive\s*\|\s*from=2026-01-01, to=2026-02-01/',
            $display
        );
        self::assertMatchesRegularExpression(
            '/Catalog price rule\s*\|\s*46\.80\s*\|\s*Won\s*\|\s*rules: Spring 10%/',
            $display
        );
        self::assertStringNotContainsString('catalog_rule', $display);
        self::assertStringContainsString(
            'final 46.80 · item price 47.80 · unexplained delta +1.00 (suspects: Vendor\\Pricing\\Observer)',
            $display
        );
    }

    public function testTextSummaryShowsNamesAndCustomer(): void
    {
        $display = $this->renderText();

        self::assertStringContainsString(
            'Quote 42 · store Default <Store> View (#1) · website Main Website (#1) · customer group General (#1)',
            $display
        );
        self::assertStringContainsString(
            'customer Veronica C <roni@example.com> (#5) · coupon SPRING · currency USD',
            $display
        );
    }

    public function testTextSummaryShowsGuest(): void
    {
        $report = new Report(1, 1, 1, 0, null, 'USD', 2, [], [], [], null, [], null, new QuoteScope(
            'Default Store View',
            'Main Website',
            'NOT LOGGED IN',
            null,
            'guest@example.com'
        ));

        $display = $this->renderText($report);

        self::assertStringContainsString('customer group NOT LOGGED IN (#0)', $display);
        self::assertStringContainsString('customer Guest <guest@example.com> · coupon none', $display);
    }

    public function testTextHidesUnchangedCollectorsAndCollapsesUnchangedAddress(): void
    {
        $display = $this->renderText();

        self::assertStringContainsString('Totals collectors', $display);
        self::assertMatchesRegularExpression(
            '/address\s*\|\s*sort\s*\|\s*code\s*\|\s*class\s*\|\s*third-party\s*\|\s*changes/',
            $display
        );
        self::assertMatchesRegularExpression(
            '/shipping\s*\|\s*300\s*\|\s*discount\s*\|\s*Magento\\\\SalesRule\\\\Model\\\\Quote\\\\Discount\s*\|'
            . '\s*no\s*\|\s*discount -9\.36, grand -9\.36/',
            $display
        );
        self::assertStringNotContainsString('vendor_fee', $display);
        self::assertStringNotContainsString('grand_total', $display);
        self::assertMatchesRegularExpression('/billing\s*\|\s*no changes \(2 collectors\)/', $display);
        self::assertStringContainsString('3 collectors without changes hidden; --all-collectors lists them.', $display);
    }

    public function testAllCollectorsOptionListsEveryCollector(): void
    {
        $explainer = $this->createStub(ExplainerInterface::class);
        $explainer->method('explainQuote')->willReturn($this->report());
        $tester = $this->tester($explainer);

        self::assertSame(Command::SUCCESS, $tester->execute(['--quote' => '42', '--all-collectors' => true]));
        $display = $tester->getDisplay(true);

        self::assertMatchesRegularExpression(
            '/shipping\s*\|\s*—\s*\|\s*vendor_fee\s*\|\s*Vendor\\\\Fee\\\\Total\s*\|\s*yes\s*\|\s*—/',
            $display
        );
        self::assertMatchesRegularExpression('/billing\s*\|\s*100\s*\|\s*subtotal/', $display);
        self::assertMatchesRegularExpression('/billing\s*\|\s*550\s*\|\s*grand_total/', $display);
        self::assertStringNotContainsString('no changes', $display);
        self::assertStringNotContainsString('hidden', $display);
    }

    public function testJsonKeepsEveryCollector(): void
    {
        $explainer = $this->createStub(ExplainerInterface::class);
        $explainer->method('explainQuote')->willReturn($this->report());
        $tester = $this->tester($explainer);

        $tester->execute(['--quote' => '42', '--format' => 'json']);
        $json = json_decode($tester->getDisplay(), true);

        self::assertSame(
            ['discount', 'vendor_fee', 'subtotal', 'grand_total'],
            array_column($json['collectors'], 'code')
        );
        self::assertSame([300, null, 100, 550], array_column($json['collectors'], 'sort_order'));
    }

    public function testTextRendersRules(): void
    {
        $display = $this->renderText();

        self::assertStringContainsString(
            "#7 Free over 100 (sort 0) — Rejected [Conditions] Conditions are not met.\n"
            . '    ✗ Subtotal equals or greater than 100: expected 100, actual 93.6',
            $display
        );
        self::assertStringContainsString(
            '#3 Spring (sort 1) — Applied · discount 9.36 · shipping discount 5.00'
            . ' · free shipping (For shipment with matching items): Not honoured by the carrier'
            . ' · discards subsequent rules',
            $display
        );
        self::assertStringContainsString('    MH01 → MH01-M-Red: applied 9.36', $display);
        self::assertStringContainsString(
            "    BNDL: applied 1.00\n"
            . "        BNDL → A: applied 1.00\n"
            . "        BNDL → B: not applied [Actions] Item does not match the actions.\n"
            . '            ✗ SKU is MJ01: expected MJ01, actual MH01-M-Red',
            $display
        );
        self::assertStringContainsString(
            "    MJ01: not applied [Actions] Item does not match the actions.\n"
            . '        ✗ SKU is MJ01: expected MJ01, actual MH01-M-Red',
            $display
        );
        self::assertStringContainsString(
            '#9 Other site (sort 0) — Not a candidate [Website] Rule is for website 2.',
            $display
        );
        self::assertStringNotContainsString('not_honoured_by_carrier', $display);
        self::assertStringNotContainsString('not_candidate', $display);
    }

    public function testTextRendersFreeShipping(): void
    {
        $display = $this->renderText();

        self::assertStringContainsString(
            "Free shipping\n"
            . 'Not honoured by the carrier Flat Rate ignores shipment-level free shipping and charges 10.00;'
            . " enable Free Shipping <b>now</b>.\n"
            . "Granted by: #3 Spring (For shipment with matching items)\n"
            . "Address flag: yes\n"
            . "Free items: —\n"
            . "Free boxes: 0 of 2\n"
            . "Method: flatrate_flatrate\n"
            . "Stored rate (base currency): 10.00 (saved 2026-10-28 10:00:00), older than the quote\n"
            . "Carrier honours: Item flags only\n"
            . 'Free Shipping carrier: Disabled',
            $display
        );
        self::assertLessThan(strpos($display, "\nTax\n"), strpos($display, "\nFree shipping\n"));
    }

    public function testTextOmitsFreeShippingWhenNothingGrantsIt(): void
    {
        $report = new Report(1, 1, 1, 0, null, 'USD');

        self::assertStringNotContainsString('Free shipping', $this->renderText($report));
    }

    public function testTextRendersStoppedByOutcome(): void
    {
        $report = new Report(1, 1, 1, 0, null, 'USD', 2, [], [], [new RuleVerdict(
            4,
            'Later',
            5,
            Status::REJECTED,
            Stage::STOPPED_BY,
            'Discarded by an earlier rule.',
            [new RuleItemOutcome(10, 'MH01', false, Stage::STOPPED_BY, null, null, 3)]
        )]);

        self::assertStringContainsString(
            '    MH01: not applied [Discarded by an earlier rule] (stopped by rule #3)',
            $this->renderText($report)
        );
    }

    public function testTextRendersLabelledFailingConditionsOncePerItem(): void
    {
        $leaf = static fn (array $actual, ?array $members = null): ConditionNode => new ConditionNode(
            '1--1--1',
            'Category is one of 25',
            false,
            true,
            null,
            ['25'],
            [],
            [],
            null,
            null,
            'Category is one of',
            ['Tees'],
            $actual,
            'category',
            null,
            $members
        );
        $check = static fn (int $id, string $label, ConditionNode $leaf): ConditionNode => new ConditionNode(
            '1--1@' . $id,
            $label,
            false,
            true,
            'all',
            true,
            null,
            [$leaf],
            null,
            $label
        );
        $tree = new ConditionNode('1', 'If ALL', false, true, 'all', true, null, [
            new ConditionNode('1--1', 'FOUND', false, true, 'all', null, [], [
                $check(1, 'MP01 → MP01-32-Black', $leaf(['Men', 'Pants'], [
                    ['label' => 'MP01', 'actual' => ['18', '1'], 'actual_label' => '[Men, Pants]', 'result' => false],
                    ['label' => 'MP01-32-Black', 'actual' => ['18'], 'actual_label' => '[Pants]', 'result' => false],
                ])),
                $check(4, '24-UG06', $leaf(['Gear'])),
                $check(4, '24-UG06', $leaf(['Gear'])),
                $check(5, '24-UG06', $leaf(['Bags'])),
            ]),
        ]);
        $report = new Report(1, 1, 1, 0, null, 'USD', 2, [], [], [
            new RuleVerdict(1, 'Tees', 0, Status::REJECTED, Stage::CONDITIONS, 'Not met.', [], null, $tree),
        ]);

        $display = $this->renderText($report);

        self::assertStringContainsString(
            "    ✗ Category is one of Tees — MP01 → MP01-32-Black: expected [Tees], actual "
            . "MP01: [Men, Pants] · MP01-32-Black: [Pants]\n"
            . "    ✗ Category is one of Tees — 24-UG06: expected [Tees], actual [Gear]\n"
            . "    ✗ Category is one of Tees — 24-UG06: expected [Tees], actual [Bags]\n",
            $display
        );
        self::assertSame(2, substr_count($display, '24-UG06: expected'), 'same label, other value: own line');
    }

    public function testTextMarksFoldedValuesWhenItemResultsDiffer(): void
    {
        $member = static fn (string $label, string $sku, bool $result): array =>
            ['label' => $label, 'actual' => $sku, 'actual_label' => null, 'result' => $result];
        $sku = new ConditionNode(
            path: '1--1--1',
            description: 'SKU is MP01-32-Black',
            result: true,
            expected: 'MP01-32-Black',
            actual: 'MP01',
            memberActuals: [$member('MP01', 'MP01', false), $member('MP01-32-Black', 'MP01-32-Black', true)]
        );
        $check = new ConditionNode(
            path: '1--1@8',
            description: 'MP01 → MP01-32-Black',
            result: false,
            aggregator: 'all',
            expected: false,
            children: [$sku],
            item: 'MP01 → MP01-32-Black',
            itemIds: [8, 9]
        );
        $tree = new ConditionNode('1', 'If ALL', false, true, 'all', true, null, [
            new ConditionNode('1--1', 'NOT FOUND', false, true, 'all', null, [9], [$check]),
        ]);
        $report = new Report(1, 1, 1, 0, null, 'USD', 2, [], [], [
            new RuleVerdict(1, 'No black', 0, Status::REJECTED, Stage::CONDITIONS, 'Not met.', [], null, $tree),
        ]);

        self::assertStringContainsString(
            "✗ SKU is MP01-32-Black — MP01 → MP01-32-Black: expected MP01-32-Black, actual "
            . "✗ MP01: MP01 · ✓ MP01-32-Black: MP01-32-Black\n",
            $this->renderText($report)
        );
    }

    public function testTextRendersTax(): void
    {
        $display = $this->renderText();

        self::assertStringContainsString(
            'based on Shipping address · algorithm Total · customer tax class Retail Customer',
            $display
        );
        self::assertStringContainsString('address: US, region 57, 78701 (Shipping address)', $display);
        self::assertStringContainsString("\nCatalog prices include tax: no · Cross border trade: yes\n", $display);
        self::assertStringNotContainsString('price_includes_tax', $display);
        self::assertStringNotContainsString('TOTAL_BASE_CALCULATION', $display);
        self::assertMatchesRegularExpression(
            '/MH01-M-Red\s*\|\s*Taxable Goods\s*\|\s*6\.18\s*\|\s*US-TX 8\.25% 6\.18/',
            $display
        );
        self::assertMatchesRegularExpression('/shipping\s*\|\s*None\s*\|\s*0\.00\s*\|\s*No tax class\./', $display);
        self::assertStringContainsString('applied: US-TX 8.25% 6.18', $display);
    }

    public function testTextRendersQuoteTotals(): void
    {
        $report = new Report(42, 1, 1, 0, null, 'USD', 2, [], [], [], null, [], null, null, null, new Totals(
            ['subtotal' => 100.0, 'discount' => -10.0, 'shipping' => 5.0, 'tax' => 8.0, 'grand_total' => 103.0],
            ['subtotal' => 100.0],
            'USD'
        ));

        $display = $this->renderText($report);

        self::assertMatchesRegularExpression('/Totals\n\+-+\+-+\+\n\| total\s+\| current\s+\|/', $display);
        self::assertMatchesRegularExpression('/\| Discount\s+\| -10\.00\s+\|/', $display);
        self::assertMatchesRegularExpression('/\| Grand Total\s+\| 103\.00\s+\|/', $display);
        self::assertStringNotContainsString('ordered', $display);
        self::assertStringNotContainsString('base current', $display);
        self::assertLessThan(strpos($display, 'Item prices'), strpos($display, 'Totals'));
    }

    public function testTextRendersOrderedTotalsAndMarksDifferences(): void
    {
        $report = (new Report(3, 1, 1, 0, null, 'EUR'))->withOrder(
            new OrderContext('000000003', 3),
            new Totals(
                ['subtotal' => 100.0, 'grand_total' => 100.0],
                ['subtotal' => 50.0, 'grand_total' => 50.0],
                'USD',
                ['subtotal' => 100.0, 'grand_total' => 90.0],
                ['subtotal' => 50.0, 'grand_total' => 45.0]
            )
        );

        $display = $this->renderText($report);

        self::assertMatchesRegularExpression(
            '/\| total\s+\| current\s+\| base current\s+\| ordered\s+\| base ordered\s+\|/',
            $display
        );
        self::assertMatchesRegularExpression('/\| Subtotal\s+\| 100\.00\s+\| 50\.00\s+\| 100\.00\s+\|/', $display);
        self::assertMatchesRegularExpression(
            '/\| Grand Total\s+\| 100\.00\s+\| 50\.00\s+\| 90\.00 ≠\s+\| 45\.00\s+\|/',
            $display
        );
        self::assertStringContainsString('≠ the order differs from the current recollection.', $display);
    }

    public function testTextOmitsTotalsWhenAbsent(): void
    {
        self::assertStringNotContainsString("\nTotals\n", $this->renderText(new Report(1, 1, 1, 0, null, 'USD')));
    }

    public function testTextRendersWarningsWithoutCodes(): void
    {
        $display = $this->renderText();

        self::assertStringContainsString(
            "Warnings\n! Indexed final price 50.00 differs from computed 46.80.",
            $display
        );
        self::assertStringNotContainsString('index_mismatch', $display);
    }

    public function testEveryLabelKeyTheAdminScriptReadsIsInTheJson(): void
    {
        $report = $this->report()->withOrder(
            new OrderContext('000000003', 3),
            new Totals(['grand_total' => 1.0], [], null, ['grand_total' => 1.0], [])
        );
        $keys = [];
        $collect = static function (array $data) use (&$collect, &$keys): void {
            foreach ($data as $key => $value) {
                $keys[$key] = true;
                if (is_array($value)) {
                    $collect($value);
                }
            }
        };
        $collect($report->toArray());

        preg_match_all('/\.(\w+_label|labels)\b/', (string)file_get_contents(
            __DIR__ . '/../../../../view/adminhtml/web/js/report.js'
        ), $matches);
        $read = array_values(array_unique($matches[1]));

        self::assertContains('address_label', $read);
        self::assertSame([], array_values(array_diff($read, array_keys($keys))));
    }

    private function renderText(?Report $report = null): string
    {
        $explainer = $this->createStub(ExplainerInterface::class);
        $explainer->method('explainQuote')->willReturn($report ?? $this->report());
        $tester = $this->tester($explainer);

        self::assertSame(Command::SUCCESS, $tester->execute(['--quote' => '42']));

        return $tester->getDisplay(true);
    }
}
