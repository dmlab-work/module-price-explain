<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Console\Command;

use DmLab\PriceExplain\Api\ExplainerInterface;
use DmLab\PriceExplain\Console\ReportPrinter;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `dmlab:price:explain` — why a quote or an order costs what it costs.
 */
class ExplainCommand extends Command
{
    public const NAME = 'dmlab:price:explain';

    private const OPTION_QUOTE = 'quote';
    private const OPTION_ORDER = 'order';
    private const OPTION_FORMAT = 'format';
    private const OPTION_ALL_RULES = 'all-rules';
    private const OPTION_ALL_COLLECTORS = 'all-collectors';
    private const FORMAT_TEXT = 'text';
    private const FORMAT_JSON = 'json';

    /**
     * @param ExplainerInterface $explainer
     * @param ReportPrinter $printer
     * @param State $appState
     */
    public function __construct(
        private readonly ExplainerInterface $explainer,
        private readonly ReportPrinter $printer,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Explains item prices, totals collectors, cart price rules and tax of a quote or order.')
            ->addOption(self::OPTION_QUOTE, null, InputOption::VALUE_REQUIRED, 'Quote ID')
            ->addOption(self::OPTION_ORDER, null, InputOption::VALUE_REQUIRED, 'Order number (increment ID)')
            ->addOption(
                self::OPTION_FORMAT,
                null,
                InputOption::VALUE_REQUIRED,
                'Output format: text or json',
                self::FORMAT_TEXT
            )
            ->addOption(self::OPTION_ALL_RULES, null, InputOption::VALUE_NONE, 'Also list rules of other websites')
            ->addOption(
                self::OPTION_ALL_COLLECTORS,
                null,
                InputOption::VALUE_NONE,
                'Also list totals collectors that changed nothing (text format)'
            );
        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $quote = $input->getOption(self::OPTION_QUOTE);
        $order = $input->getOption(self::OPTION_ORDER);
        $format = (string)$input->getOption(self::OPTION_FORMAT);
        $error = $this->validate($quote, $order, $format);
        if ($error !== null) {
            $output->writeln('<error>' . $error . '</error>');
            return Command::FAILURE;
        }

        $allRules = (bool)$input->getOption(self::OPTION_ALL_RULES);
        try {
            $this->setAreaCode();
            $report = $quote !== null
                ? $this->explainer->explainQuote((int)$quote, $allRules)
                : $this->explainer->explainOrder(trim((string)$order), $allRules);
        } catch (LocalizedException $e) {
            $output->writeln('<error>' . OutputFormatter::escape($e->getMessage()) . '</error>');
            return Command::FAILURE;
        }

        if ($format === self::FORMAT_JSON) {
            $output->writeln(
                json_encode(
                    $report->toArray(),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                ),
                OutputInterface::OUTPUT_RAW
            );
        } else {
            $this->printer->print($report, $output, (bool)$input->getOption(self::OPTION_ALL_COLLECTORS));
        }

        return Command::SUCCESS;
    }

    /**
     * Error message for invalid options, null when valid.
     *
     * @param mixed $quote
     * @param mixed $order
     * @param string $format
     * @return string|null
     */
    private function validate(mixed $quote, mixed $order, string $format): ?string
    {
        if (($quote === null) === ($order === null)) {
            return 'Pass exactly one of --quote=ID or --order=INCREMENT_ID.';
        }
        if ($quote !== null && !preg_match('/^[1-9]\d*$/', (string)$quote)) {
            return '--quote must be a positive integer.';
        }
        if ($order !== null && trim((string)$order) === '') {
            return '--order must not be empty.';
        }
        if (!in_array($format, [self::FORMAT_TEXT, self::FORMAT_JSON], true)) {
            return '--format must be text or json.';
        }

        return null;
    }

    /**
     * Run in the frontend area: explain the cart as the storefront computes it.
     *
     * @return void
     */
    private function setAreaCode(): void
    {
        try {
            $this->appState->setAreaCode(Area::AREA_FRONTEND);
        } catch (LocalizedException) {
            return; // Area already set.
        }
    }
}
