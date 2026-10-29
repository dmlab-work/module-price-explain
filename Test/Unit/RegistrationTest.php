<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit;

use Magento\Framework\Component\ComponentRegistrar;
use PHPUnit\Framework\TestCase;

class RegistrationTest extends TestCase
{
    public function testModuleIsRegisteredAtThisDirectory(): void
    {
        $paths = (new ComponentRegistrar())->getPaths(ComponentRegistrar::MODULE);

        self::assertArrayHasKey('DmLab_PriceExplain', $paths);
        self::assertSame(realpath(dirname(__DIR__, 2)), realpath($paths['DmLab_PriceExplain']));
    }

    public function testModuleXmlHasNoSetupVersionAndDeclaresSequence(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/etc/module.xml');
        self::assertNotFalse($xml);

        $module = $xml->module;
        self::assertSame('DmLab_PriceExplain', (string)$module['name']);
        self::assertFalse(isset($module['setup_version']));

        $sequence = [];
        foreach ($module->sequence->module as $dependency) {
            $sequence[] = (string)$dependency['name'];
        }
        self::assertSame(
            [
                'Magento_Backend',
                'Magento_Catalog',
                'Magento_CatalogRule',
                'Magento_Customer',
                'Magento_Quote',
                'Magento_Rule',
                'Magento_Sales',
                'Magento_SalesRule',
                'Magento_Store',
                'Magento_Tax',
            ],
            $sequence
        );
    }

    public function testAclDeclaresTheResourceNextToCartPriceRules(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/etc/acl.xml');
        self::assertNotFalse($xml);

        $promo = '/config/acl/resources/resource[@id="Magento_Backend::admin"]'
            . '/resource[@id="Magento_Backend::marketing"]/resource[@id="Magento_CatalogRule::promo"]';
        self::assertCount(1, $xml->xpath($promo . '/resource[@id="DmLab_PriceExplain::explain"]'));
    }

    public function testAdminRouteAndMenu(): void
    {
        $routes = simplexml_load_file(dirname(__DIR__, 2) . '/etc/adminhtml/routes.xml');
        self::assertNotFalse($routes);
        $route = $routes->xpath('/config/router[@id="admin"]/route[@id="dmlab_price_explain"]');
        self::assertCount(1, $route);
        self::assertSame('dmlab_price_explain', (string)$route[0]['frontName']);
        self::assertSame('DmLab_PriceExplain', (string)$route[0]->module['name']);

        $menu = simplexml_load_file(dirname(__DIR__, 2) . '/etc/adminhtml/menu.xml');
        self::assertNotFalse($menu);
        $item = $menu->xpath('/config/menu/add[@id="DmLab_PriceExplain::explain"]');
        self::assertCount(1, $item);
        self::assertSame('Magento_CatalogRule::promo', (string)$item[0]['parent']);
        self::assertSame('dmlab_price_explain/report/index', (string)$item[0]['action']);
        self::assertSame('DmLab_PriceExplain::explain', (string)$item[0]['resource']);
    }

    public function testGlobalDiWiresTracingPluginsAndLazyCliExplainer(): void
    {
        $di = simplexml_load_file(dirname(__DIR__, 2) . '/etc/di.xml');
        self::assertNotFalse($di);

        $plugins = [];
        foreach ($di->type as $type) {
            if (isset($type->plugin)) {
                $plugins[(string)$type['name']] = (string)$type->plugin['type'];
            }
        }
        self::assertSame(
            [
                'Magento\\Quote\\Model\\Quote\\Address\\Total\\AbstractTotal'
                    => 'DmLab\\PriceExplain\\Plugin\\Quote\\TraceCollectorPlugin',
                'Magento\\Rule\\Model\\Condition\\AbstractCondition'
                    => 'DmLab\\PriceExplain\\Plugin\\Rule\\CaptureValidatedValuePlugin',
            ],
            $plugins
        );
        self::assertSame(
            'DmLab\\PriceExplain\\Model\\Explainer',
            (string)$di->xpath('//preference[@for="DmLab\\PriceExplain\\Api\\ExplainerInterface"]')[0]['type']
        );
        self::assertSame(
            'DmLab\\PriceExplain\\Api\\ExplainerInterface\\Proxy',
            trim((string)$di->xpath(
                '//type[@name="DmLab\\PriceExplain\\Console\\Command\\ExplainCommand"]//argument[@name="explainer"]'
            )[0])
        );
    }

    public function testAdminEntryPointPluginsAreAdminhtmlScoped(): void
    {
        $di = simplexml_load_file(dirname(__DIR__, 2) . '/etc/adminhtml/di.xml');
        self::assertNotFalse($di);

        $plugins = [];
        foreach ($di->type as $type) {
            $plugins[(string)$type['name']] = (string)$type->plugin['type'];
        }
        self::assertSame(
            [
                'Magento\\Sales\\Block\\Adminhtml\\Order\\View'
                    => 'DmLab\\PriceExplain\\Plugin\\Sales\\OrderViewExplainButtonPlugin',
                'Magento\\Customer\\Block\\Adminhtml\\Edit\\Tab\\Cart'
                    => 'DmLab\\PriceExplain\\Plugin\\Customer\\CartExplainLinkPlugin',
            ],
            $plugins
        );
    }
}
