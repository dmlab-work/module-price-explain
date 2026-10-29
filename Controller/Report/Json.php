<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Controller\Report;

use DmLab\PriceExplain\Api\ExplainerInterface;
use DmLab\PriceExplain\Model\Report\Report;
use DmLab\PriceExplain\Model\Storefront\InvalidTokenException;
use DmLab\PriceExplain\Model\Storefront\Origins;
use DmLab\PriceExplain\Model\Storefront\TokenPayload;
use DmLab\PriceExplain\Model\Storefront\TokenService;
use Magento\Framework\App\Action\HttpOptionsActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\AbstractResult;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Storefront-area explanation for the admin report; the signed one-time token in the POST body is the credential.
 */
class Json implements HttpPostActionInterface, HttpOptionsActionInterface, CsrfAwareActionInterface
{
    /**
     * @param Http $request
     * @param ResultFactory $resultFactory
     * @param TokenService $tokenService
     * @param ExplainerInterface $explainer
     * @param StoreManagerInterface $storeManager
     * @param Origins $origins
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Http $request,
        private readonly ResultFactory $resultFactory,
        private readonly TokenService $tokenService,
        private readonly ExplainerInterface $explainer,
        private readonly StoreManagerInterface $storeManager,
        private readonly Origins $origins,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Answer a CORS preflight or run the explanation the token grants.
     *
     * @return AbstractResult
     */
    public function execute(): AbstractResult
    {
        if ($this->request->isOptions()) {
            $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
            $result->setHttpResponseCode(204);
            $result->setContents('');
            if ($this->cors($result)) {
                $result->setHeader('Access-Control-Allow-Methods', 'POST', true);
                $result->setHeader('Access-Control-Allow-Headers', 'Content-Type', true);
            }

            return $this->noStore($result);
        }

        /** @var JsonResult $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $this->cors($result);
        $this->noStore($result);
        $storeId = (int)$this->storeManager->getStore()->getId();
        try {
            $payload = $this->tokenService->consume($this->token(), $storeId);

            return $result->setData($this->explain($payload)->toArray());
        } catch (InvalidTokenException $e) {
            return $this->error($result, 403, 'forbidden');
        } catch (NoSuchEntityException $e) {
            return $this->error($result, 404, 'not_found');
        } catch (LocalizedException $e) {
            return $this->error($result, 422, 'unprocessable', $e->getMessage());
        } catch (Throwable $e) {
            $this->logger->error('Storefront price explanation failed.', ['exception' => $e]);

            return $this->error($result, 500, 'internal');
        }
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Run the explainer for the token's quote or order.
     *
     * @param TokenPayload $payload
     * @return Report
     * @throws LocalizedException
     */
    private function explain(TokenPayload $payload): Report
    {
        return $payload->getKind() === TokenService::KIND_ORDER
            ? $this->explainer->explainOrderById($payload->getId(), $payload->isAllRules())
            : $this->explainer->explainQuote($payload->getId(), $payload->isAllRules());
    }

    /**
     * Token from a form field or a `text/plain` body `token=...` (keeps cross-origin POSTs preflight-free).
     *
     * Token characters (base64url, `.`, hex) never need URL-encoding.
     *
     * @return string
     */
    private function token(): string
    {
        $token = (string)$this->request->getPost('token');
        if ($token === '' && preg_match('/^token=([A-Za-z0-9_.-]+)\s*$/', (string)$this->request->getContent(), $m)) {
            $token = $m[1];
        }

        return $token;
    }

    /**
     * Allow the admin origin when the admin runs on another origin than this store.
     *
     * @param AbstractResult $result
     * @return bool whether CORS headers were added
     */
    private function cors(AbstractResult $result): bool
    {
        $admin = $this->origins->admin();
        $store = $this->origins->of(
            (string)$this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB, $this->request->isSecure())
        );
        if ($admin === null || $admin === $store) {
            return false;
        }
        $result->setHeader('Access-Control-Allow-Origin', $admin, true);
        $result->setHeader('Vary', 'Origin', true);

        return true;
    }

    /**
     * Forbid caching the response anywhere.
     *
     * @param AbstractResult $result
     * @return AbstractResult
     */
    private function noStore(AbstractResult $result): AbstractResult
    {
        $result->setHeader('Cache-Control', 'no-store', true);

        return $result;
    }

    /**
     * JSON error without internals; only explainer messages (shown to the admin anyway) pass through.
     *
     * @param JsonResult $result
     * @param int $status
     * @param string $code
     * @param string|null $message
     * @return JsonResult
     */
    private function error(JsonResult $result, int $status, string $code, ?string $message = null): JsonResult
    {
        $result->setHttpResponseCode($status);

        return $result->setData(['error' => $code] + ($message === null ? [] : ['message' => $message]));
    }
}
