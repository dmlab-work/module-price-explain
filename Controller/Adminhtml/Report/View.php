<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Controller\Adminhtml\Report;

use DmLab\PriceExplain\Api\ExplainerInterface;
use DmLab\PriceExplain\Model\Report\Report;
use DmLab\PriceExplain\Model\Storefront\ReportTarget;
use DmLab\PriceExplain\Model\Storefront\TargetResolver;
use DmLab\PriceExplain\Model\Storefront\TokenService;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Framework\Url;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Report shell for one quote (quote_id) or order (order_id / increment_id), filled in the browser from the
 * storefront endpoint; format=json returns the admin-area report (the shell's fallback).
 */
class View extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'DmLab_PriceExplain::explain';
    public const BLOCK_NAME = 'dmlab_price_explain.report';
    public const ENDPOINT_ROUTE = 'dmlab_price_explain/report/json';
    private const SOURCES = ['quote_id', 'order_id', 'increment_id'];

    /**
     * @param Context $context
     * @param ExplainerInterface $explainer
     * @param TargetResolver $targetResolver
     * @param TokenService $tokenService
     * @param StoreManagerInterface $storeManager
     * @param Url $frontendUrl storefront URL builder; Store::getUrl() builds backend URLs in the admin area
     */
    public function __construct(
        Context $context,
        private readonly ExplainerInterface $explainer,
        private readonly TargetResolver $targetResolver,
        private readonly TokenService $tokenService,
        private readonly StoreManagerInterface $storeManager,
        private readonly Url $frontendUrl
    ) {
        parent::__construct($context);
    }

    /**
     * Render the report shell, or the admin-area JSON; bad input returns to the form (JSON: an error body).
     *
     * @return Page|Json|Raw|Redirect
     */
    public function execute(): Page|Json|Raw|Redirect
    {
        $json = $this->getRequest()->getParam('format') === 'json';
        $source = $this->source();
        $allRules = (bool)$this->getRequest()->getParam('all_rules');
        if ($source === null) {
            $message = __('Enter either a quote ID or an order number.');

            return $json ? $this->error(400, $message) : $this->back($message);
        }
        try {
            $target = $this->target($source);
            if ($json) {
                return $this->json($this->explain($target, $allRules), $source);
            }
            $store = $this->storeManager->getStore($target->getStoreId());
        } catch (LocalizedException $e) {
            return $json
                ? $this->error($e instanceof NoSuchEntityException ? 404 : 422, $e->getMessage())
                : $this->back($e->getMessage());
        }
        $token = $this->tokenService->issue(
            $target->getKind(),
            $target->getId(),
            $allRules,
            (int)$store->getId(),
            (int)$this->_auth->getUser()?->getId()
        );

        $orderViewUrl = $target->getKind() === TokenService::KIND_ORDER
            ? $this->getUrl('sales/order/view', ['order_id' => $target->getId()])
            : '';

        /** @var Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu(self::ADMIN_RESOURCE);
        $page->getConfig()->getTitle()->prepend(__('Price Explanation: %1', $target->getLabel()));
        $page->getLayout()->getBlock(self::BLOCK_NAME)->addData([
            'endpoint_url' => $this->frontendUrl->setScope($store)->getUrl(
                self::ENDPOINT_ROUTE,
                ['_nosid' => true, '_scope_to_url' => true]
            ),
            'token' => $token,
            'fallback_url' => $this->getUrl(
                'dmlab_price_explain/report/view',
                ['_query' => $source + ['all_rules' => (int)$allRules, 'format' => 'json']]
            ),
            'file_name' => $this->fileName($source),
            'order_view_url' => $orderViewUrl,
        ]);

        return $page;
    }

    /**
     * The one non-empty source parameter, or null when none, several or a non-numeric ID is given.
     *
     * @return array<string,string>|null
     */
    private function source(): ?array
    {
        $given = [];
        foreach (self::SOURCES as $name) {
            $value = trim((string)$this->getRequest()->getParam($name));
            if ($value !== '') {
                $given[$name] = $value;
            }
        }
        if (count($given) !== 1) {
            return null;
        }
        $name = array_key_first($given);
        if ($name !== 'increment_id' && (!ctype_digit($given[$name]) || (int)$given[$name] === 0)) {
            return null;
        }

        return $given;
    }

    /**
     * Run the explainer for the target in the admin area.
     *
     * @param ReportTarget $target
     * @param bool $allRules
     * @return Report
     * @throws LocalizedException
     */
    private function explain(ReportTarget $target, bool $allRules): Report
    {
        return $target->getKind() === TokenService::KIND_ORDER
            ? $this->explainer->explainOrderById($target->getId(), $allRules)
            : $this->explainer->explainQuote($target->getId(), $allRules);
    }

    /**
     * Quote or order the source names, with its store.
     *
     * @param array<string,string> $source
     * @return ReportTarget
     * @throws LocalizedException
     */
    private function target(array $source): ReportTarget
    {
        $value = reset($source);

        return match (array_key_first($source)) {
            'quote_id' => $this->targetResolver->quote((int)$value),
            'order_id' => $this->targetResolver->order((int)$value),
            default => $this->targetResolver->orderByIncrementId($value),
        };
    }

    /**
     * Download file name, e.g. price-explain-quote-id-42.json.
     *
     * @param array<string,string> $source
     * @return string
     */
    private function fileName(array $source): string
    {
        return sprintf(
            'price-explain-%s-%s.json',
            str_replace('_', '-', (string)array_key_first($source)),
            preg_replace('/[^A-Za-z0-9-]/', '_', (string)reset($source))
        );
    }

    /**
     * Report as a JSON attachment.
     *
     * @param Report $report
     * @param array<string,string> $source
     * @return Raw
     */
    private function json(Report $report, array $source): Raw
    {
        /** @var Raw $raw */
        $raw = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $raw->setHeader('Content-Type', 'application/json', true);
        $raw->setHeader('Content-Disposition', 'attachment; filename="' . $this->fileName($source) . '"', true);
        $raw->setContents(json_encode(
            $report->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));

        return $raw;
    }

    /**
     * JSON error for the report shell's fallback request.
     *
     * @param int $status
     * @param Phrase|string $message
     * @return Json
     */
    private function error(int $status, Phrase|string $message): Json
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $result->setHttpResponseCode($status);

        return $result->setData(['error' => (string)$message]);
    }

    /**
     * Error message and redirect to the form.
     *
     * @param Phrase|string $message
     * @return Redirect
     */
    private function back(Phrase|string $message): Redirect
    {
        $this->messageManager->addErrorMessage((string)$message);

        return $this->resultRedirectFactory->create()->setPath('dmlab_price_explain/report/index');
    }
}
