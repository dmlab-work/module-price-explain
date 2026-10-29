<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Console;

use DmLab\PriceExplain\Model\Report\ConditionNode;
use DmLab\PriceExplain\Model\Report\FormatsText;
use DmLab\PriceExplain\Model\Report\ItemPrice;
use DmLab\PriceExplain\Model\Report\Labels;
use DmLab\PriceExplain\Model\Report\Report;
use DmLab\PriceExplain\Model\Report\RuleItemOutcome;
use DmLab\PriceExplain\Model\Report\RuleVerdict;
use DmLab\PriceExplain\Model\Report\Status;
use DmLab\PriceExplain\Model\Report\TaxBreakdown;
use DmLab\PriceExplain\Model\Report\Totals;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders a Report as console text; every value from the store is escaped.
 */
class ReportPrinter
{
    use FormatsText;

    /**
     * Print every section of the report.
     *
     * @param Report $report
     * @param OutputInterface $output
     * @param bool $allCollectors also list collectors that changed nothing
     * @return void
     */
    public function print(Report $report, OutputInterface $output, bool $allCollectors = false): void
    {
        $scope = $report->getScope();
        $output->writeln($this->e(sprintf(
            '%s · store %s · website %s · customer group %s',
            $this->subject($report),
            $this->named($scope?->getStoreName(), $report->getStoreId()),
            $this->named($scope?->getWebsiteName(), $report->getWebsiteId()),
            $this->named($scope?->getCustomerGroupName(), $report->getCustomerGroupId())
        )));
        $customer = $this->customer($scope);
        $output->writeln($this->e(sprintf(
            '%scoupon %s · currency %s',
            $customer !== null ? 'customer ' . $customer . ' · ' : '',
            $report->getCouponCode() ?? 'none',
            $report->getCurrency()
        )));
        $this->printTotals($report, $output);
        $this->printItems($report, $output);
        $this->printCollectors($report, $output, $allCollectors);
        $this->printRules($report, $output);
        $this->printFreeShipping($report, $output);
        $this->printTax($report, $output);
        $this->printWarnings($report, $output);
    }

    /**
     * "Quote 42", or "Order 000000003 (quote 3) · placed …" for an order report.
     *
     * @param Report $report
     * @return string
     */
    private function subject(Report $report): string
    {
        $order = $report->getOrder();
        if ($order === null) {
            return sprintf('Quote %d', $report->getQuoteId());
        }
        $subject = sprintf('Order %s (quote %d)', $order->getIncrementId(), $report->getQuoteId());
        if ($order->getCreatedAt() !== null) {
            $subject .= ' · placed ' . $order->getCreatedAt();
        }

        return $subject;
    }

    /**
     * Totals table: current and, for orders, ordered; base columns only when the base currency differs.
     *
     * @param Report $report
     * @param OutputInterface $output
     * @return void
     */
    private function printTotals(Report $report, OutputInterface $output): void
    {
        $totals = $report->getTotals();
        if ($totals === null) {
            return;
        }
        $this->heading($output, 'Totals');
        $precision = $report->getPrecision();
        $withBase = $totals->getBaseCurrency() !== null && $totals->getBaseCurrency() !== $report->getCurrency();
        $differences = $totals->getDifferences($precision);
        $columns = ['current' => $totals->getCurrent()];
        if ($withBase) {
            $columns['base current'] = $totals->getBaseCurrent();
        }
        if ($totals->getOrdered() !== null) {
            $columns['ordered'] = $totals->getOrdered();
            if ($withBase) {
                $columns['base ordered'] = $totals->getBaseOrdered();
            }
        }
        $rows = [];
        foreach (Totals::CODES as $code) {
            $row = [Labels::total($code)];
            foreach ($columns as $column => $amounts) {
                $cell = $this->amount($amounts[$code], $precision);
                $row[] = $column === 'ordered' && in_array($code, $differences, true)
                    ? '<comment>' . $cell . ' ≠</comment>'
                    : $cell;
            }
            $rows[] = $row;
        }
        (new Table($output))->setHeaders(['total', ...array_keys($columns)])->setRows($rows)->render();
        if ($differences !== []) {
            $output->writeln('≠ the order differs from the current recollection.');
        }
    }

    /**
     * Item price waterfall.
     *
     * @param Report $report
     * @param OutputInterface $output
     * @return void
     */
    private function printItems(Report $report, OutputInterface $output): void
    {
        $this->heading($output, 'Item prices (base currency)');
        if ($report->getItems() === []) {
            $output->writeln('No items.');
            return;
        }
        $precision = $report->getPrecision();
        foreach ($report->getItems() as $item) {
            $output->writeln($this->e(sprintf(
                '%s "%s" × %s (product %d)',
                $item->getLabel(),
                $item->getName(),
                $this->formatQty($item->getQty()),
                $item->getProductId()
            )));
            $rows = [];
            foreach ($item->getLayers() as $layer) {
                $rows[] = [
                    $this->e(Labels::layer($layer->getCode())),
                    $this->amount($layer->getValue(), $precision),
                    $this->e(Labels::layerState($layer->getState())),
                    $this->e($this->layerNote($layer)),
                ];
            }
            (new Table($output))->setHeaders(['layer', 'value', 'state', 'details'])->setRows($rows)->render();
            $output->writeln($this->itemSummary($item, $precision));
        }
    }

    /**
     * Final / item price line with override and delta.
     *
     * @param ItemPrice $item
     * @param int $precision
     * @return string
     */
    private function itemSummary(ItemPrice $item, int $precision): string
    {
        $parts = [
            'final ' . $this->amount($item->getFinal(), $precision),
            'item price ' . $this->amount($item->getItemPrice(), $precision),
        ];
        if ($item->getCustomPrice() !== null) {
            $parts[] = 'custom price ' . $this->amount($item->getCustomPrice(), $precision);
        }
        $line = implode(' · ', $parts);
        if ($item->getUnexplainedDelta() !== null) {
            $line .= sprintf(
                ' · <comment>unexplained delta %s</comment>',
                $this->signed($item->getUnexplainedDelta(), $precision)
            );
            if ($item->getSuspects() !== []) {
                $line .= $this->e(' (suspects: ' . implode(', ', $item->getSuspects()) . ')');
            }
        }

        return $line;
    }

    /**
     * Collector table; unless $all, unchanged collectors are hidden and an unchanged address is one row.
     *
     * @param Report $report
     * @param OutputInterface $output
     * @param bool $all
     * @return void
     */
    private function printCollectors(Report $report, OutputInterface $output, bool $all): void
    {
        $this->heading($output, 'Totals collectors');
        if ($report->getCollectors() === []) {
            $output->writeln('No collectors traced.');
            return;
        }
        $rows = [];
        $hidden = 0;
        foreach ($this->collectorsByAddress($report->getCollectors()) as $address => $steps) {
            $shown = $all ? $steps : array_filter($steps, static fn ($step): bool => $step->hasChanges());
            $hidden += count($steps) - count($shown);
            if ($shown === []) {
                $rows[] = [
                    $this->e((string)$address),
                    new TableCell(sprintf('no changes (%d collectors)', count($steps)), ['colspan' => 5]),
                ];
            }
            foreach ($shown as $step) {
                $rows[] = [
                    $this->e($step->getAddressType()),
                    (string)($step->getSortOrder() ?? '—'),
                    $this->e($step->getCode()),
                    $this->e($step->getClass()),
                    $step->isThirdParty() ? 'yes' : 'no',
                    $this->e($this->collectorChanges($step, $report->getPrecision())),
                ];
            }
        }
        (new Table($output))
            ->setHeaders(['address', 'sort', 'code', 'class', 'third-party', 'changes'])
            ->setRows($rows)
            ->render();
        if ($hidden > 0) {
            $output->writeln(sprintf('%d collectors without changes hidden; --all-collectors lists them.', $hidden));
        }
    }

    /**
     * Rule verdicts with failing nodes and per-item outcomes.
     *
     * @param Report $report
     * @param OutputInterface $output
     * @return void
     */
    private function printRules(Report $report, OutputInterface $output): void
    {
        $this->heading($output, 'Cart price rules');
        if ($report->getRules() === []) {
            $output->writeln('No cart price rules.');
            return;
        }
        $precision = $report->getPrecision();
        foreach ($report->getRules() as $rule) {
            $output->writeln($this->ruleLine($rule, $precision));
            foreach ($this->failingLines($rule->getConditions()) as $line) {
                $output->writeln('    ' . $line);
            }
            foreach ($rule->getItems() as $outcome) {
                $this->printOutcome($outcome, $precision, $output, '    ');
            }
        }
    }

    /**
     * Rule summary line.
     *
     * @param RuleVerdict $rule
     * @param int $precision
     * @return string
     */
    private function ruleLine(RuleVerdict $rule, int $precision): string
    {
        $tag = $rule->getStatus() === Status::APPLIED ? 'info' : 'comment';
        $line = sprintf(
            '#%d %s (sort %d) — <%s>%s</%s>',
            $rule->getRuleId(),
            $this->e($rule->getName()),
            $rule->getSortOrder(),
            $tag,
            $this->e(Labels::status($rule->getStatus())),
            $tag
        );
        $line .= $this->stageReason($rule->getStage(), $rule->getReason());
        if ($rule->getAmount() !== null) {
            $line .= ' · discount ' . $this->amount($rule->getAmount(), $precision);
        }
        if ($rule->getShippingAmount() !== null) {
            $line .= ' · shipping discount ' . $this->amount($rule->getShippingAmount(), $precision);
        }
        if ($rule->isFreeShipping()) {
            $line .= ' · ' . $this->freeShippingBadge($rule);
        }
        if ($rule->isStopRulesProcessing()) {
            $line .= ' · discards subsequent rules';
        }

        return $line;
    }

    /**
     * Item outcome with its failing actions and, nested, its per-child outcomes.
     *
     * @param RuleItemOutcome $outcome
     * @param int $precision
     * @param OutputInterface $output
     * @param string $indent
     * @return void
     */
    private function printOutcome(
        RuleItemOutcome $outcome,
        int $precision,
        OutputInterface $output,
        string $indent
    ): void {
        $output->writeln($indent . $this->outcomeLine($outcome, $precision));
        foreach ($this->failingLines($outcome->getActions()) as $line) {
            $output->writeln($indent . '    ' . $line);
        }
        foreach ($outcome->getChildren() as $child) {
            $this->printOutcome($child, $precision, $output, $indent . '    ');
        }
    }

    /**
     * Per-item outcome line.
     *
     * @param RuleItemOutcome $outcome
     * @param int $precision
     * @return string
     */
    private function outcomeLine(RuleItemOutcome $outcome, int $precision): string
    {
        $line = $this->e($outcome->getLabel()) . ': ';
        $line .= $outcome->isApplied()
            ? 'applied ' . $this->amount($outcome->getAmount(), $precision)
            : 'not applied';
        $line .= $this->stageReason($outcome->getStage(), $outcome->getReason());
        if ($outcome->getStoppedBy() !== null) {
            $line .= sprintf(' (stopped by rule #%d)', $outcome->getStoppedBy());
        }

        return $line;
    }

    /**
     * " [stage] reason" suffix.
     *
     * @param string|null $stage
     * @param string|null $reason
     * @return string
     */
    private function stageReason(?string $stage, ?string $reason): string
    {
        $suffix = $stage !== null ? ' [' . $this->e(Labels::stage($stage)) . ']' : '';
        if ($reason !== null && $reason !== '') {
            $suffix .= ' ' . $this->e($reason);
        }

        return $suffix;
    }

    /**
     * Failing leaves of a tree as escaped lines, each distinct condition and item once.
     *
     * @param ConditionNode|null $tree
     * @return string[]
     */
    private function failingLines(?ConditionNode $tree): array
    {
        $lines = [];
        foreach ($tree?->getFailingChecks() ?? [] as ['leaf' => $leaf, 'item' => $item]) {
            $lines[] = $this->failingLeaf($leaf, $item);
        }

        return array_values(array_unique($lines));
    }

    /**
     * Failing condition leaf with expected vs actual.
     *
     * @param ConditionNode $leaf
     * @param string|null $item
     * @return string
     */
    private function failingLeaf(ConditionNode $leaf, ?string $item): string
    {
        $line = sprintf(
            '✗ %s%s: expected %s, actual %s',
            $leaf->getText(),
            $item !== null ? ' — ' . $item : '',
            $leaf->getExpectedLabel() ?? $this->value($leaf->getExpected()),
            $this->actualText($leaf)
        );
        if ($leaf->getNote() !== null) {
            $line .= ' (' . $leaf->getNote() . ')';
        }

        return $this->e($line);
    }

    /**
     * Compared value; a folded leaf lists each item's value, marked when their results differ:
     * "✗ MP01: [Bottoms] · ✓ MP01-32-Black: [Pants]".
     *
     * @param ConditionNode $leaf
     * @return string
     */
    private function actualText(ConditionNode $leaf): string
    {
        $members = $leaf->getMemberActuals();
        if ($members === null) {
            return $leaf->getActualLabel() ?? $this->value($leaf->getActual());
        }
        $marked = count(array_unique(array_column($members, 'result'))) > 1;

        return implode(' · ', array_map(
            fn (array $m): string => ($marked ? ($m['result'] ? '✓ ' : '✗ ') : '')
                . $m['label'] . ': ' . ($m['actual_label'] ?? $this->value($m['actual'])),
            $members
        ));
    }

    /**
     * Free-shipping verdict and the facts behind it; omitted when nothing grants free shipping.
     *
     * @param Report $report
     * @param OutputInterface $output
     * @return void
     */
    private function printFreeShipping(Report $report, OutputInterface $output): void
    {
        $freeShipping = $report->getFreeShipping();
        if ($freeShipping === null) {
            return;
        }
        $this->heading($output, 'Free shipping');
        $tag = $freeShipping->isEffective() ? 'info' : 'comment';
        $output->writeln(sprintf(
            '<%s>%s</%s> %s',
            $tag,
            $this->e(Labels::freeShippingVerdict($freeShipping->getVerdict())),
            $tag,
            $this->e($freeShipping->getMessage())
        ));
        foreach ($this->freeShippingFacts($freeShipping, $report->getPrecision()) as $label => $value) {
            $output->writeln($this->e($label . ': ' . $value));
        }
    }

    /**
     * Tax section.
     *
     * @param Report $report
     * @param OutputInterface $output
     * @return void
     */
    private function printTax(Report $report, OutputInterface $output): void
    {
        $this->heading($output, 'Tax');
        $tax = $report->getTax();
        if ($tax === null) {
            $output->writeln('No tax information.');
            return;
        }
        $precision = $report->getPrecision();
        $output->writeln($this->e(sprintf(
            'based on %s · algorithm %s · customer tax class %s',
            Labels::taxBasedOn($tax->getBasedOn()),
            Labels::taxAlgorithm($tax->getAlgorithm()),
            $tax->getCustomerTaxClass() ?? 'none'
        )));
        if ($tax->getAddress() !== []) {
            $output->writeln($this->e('address: ' . $tax->getAddressLabel()));
        }
        if ($tax->getFlags() !== []) {
            $flags = [];
            foreach ($tax->getFlagLabels() as $code => $label) {
                $flags[] = $label . ': ' . $this->value($tax->getFlags()[$code]);
            }
            $output->writeln($this->e(implode(' · ', $flags)));
        }
        $this->printTaxLines($tax, $precision, $output);
        if ($tax->getAppliedTaxes() !== []) {
            $output->writeln($this->e('applied: ' . $this->rates($tax->getAppliedTaxes(), $precision)));
        }
    }

    /**
     * Per-line tax table.
     *
     * @param TaxBreakdown $tax
     * @param int $precision
     * @param OutputInterface $output
     * @return void
     */
    private function printTaxLines(TaxBreakdown $tax, int $precision, OutputInterface $output): void
    {
        if ($tax->getLines() === []) {
            return;
        }
        $rows = [];
        foreach ($tax->getLines() as $line) {
            $rows[] = [
                $this->e($line->getLabel()),
                $this->e($line->getTaxClass() ?? '—'),
                $this->amount($line->getAmount(), $precision),
                $this->e($this->rates($line->getRates(), $precision) ?: ($line->getReason() ?? '—')),
            ];
        }
        (new Table($output))->setHeaders(['line', 'tax class', 'tax', 'rates / reason'])->setRows($rows)->render();
    }

    /**
     * Warnings list.
     *
     * @param Report $report
     * @param OutputInterface $output
     * @return void
     */
    private function printWarnings(Report $report, OutputInterface $output): void
    {
        $this->heading($output, 'Warnings');
        if ($report->getWarnings() === []) {
            $output->writeln('None.');
            return;
        }
        foreach ($report->getWarnings() as $warning) {
            $output->writeln('<comment>!</comment> ' . $this->e($warning->getMessage()));
        }
    }

    /**
     * Section heading.
     *
     * @param OutputInterface $output
     * @param string $title
     * @return void
     */
    private function heading(OutputInterface $output, string $title): void
    {
        $output->writeln('');
        $output->writeln('<info>' . $title . '</info>');
    }

    /**
     * Escape console formatter tags.
     *
     * @param string $text
     * @return string
     */
    private function e(string $text): string
    {
        return OutputFormatter::escape($text);
    }
}
