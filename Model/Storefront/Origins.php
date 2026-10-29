<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Storefront;

use Magento\Backend\App\Area\FrontNameResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\Store;

/**
 * Origins (scheme://host[:port]) of the admin and of store base URLs, for CORS.
 */
class Origins
{
    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Origin the admin panel is served from: the custom admin URL or the default base URL.
     *
     * @return string|null
     */
    public function admin(): ?string
    {
        if ($this->scopeConfig->isSetFlag(FrontNameResolver::XML_PATH_USE_CUSTOM_ADMIN_URL)) {
            return $this->of((string)$this->scopeConfig->getValue(FrontNameResolver::XML_PATH_CUSTOM_ADMIN_URL));
        }
        $path = $this->scopeConfig->isSetFlag(Store::XML_PATH_SECURE_IN_ADMINHTML)
            ? Store::XML_PATH_SECURE_BASE_URL
            : Store::XML_PATH_UNSECURE_BASE_URL;

        return $this->of((string)$this->scopeConfig->getValue($path));
    }

    /**
     * Lower-cased origin of an absolute http(s) URL, or null.
     *
     * @param string $url
     * @return string|null
     */
    public function of(string $url): ?string
    {
        return preg_match('#^(https?://[^/?\#]+)#i', trim($url), $m) ? strtolower($m[1]) : null;
    }
}
