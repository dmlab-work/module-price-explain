<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class PackagingTest extends TestCase
{
    private const HEADER = ' * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.';

    public function testComposerRequiresExactlyTheUsedPackages(): void
    {
        $composer = json_decode((string)file_get_contents($this->root() . '/composer.json'), true);
        self::assertIsArray($composer);
        self::assertSame('dmlab/module-price-explain', $composer['name']);
        self::assertSame('0.0.1', $composer['version']);
        self::assertSame('OSL-3.0', $composer['license']);

        $require = $composer['require'];
        self::assertEqualsCanonicalizing(['php', ...$this->usedPackages()], array_keys($require));
        foreach ($require as $package => $constraint) {
            $prefix = $package === 'php' ? '~' : '^';
            self::assertStringStartsWith($prefix, $constraint, $package);
        }
    }

    public function testModuleSequenceMatchesTheUsedMagentoModules(): void
    {
        $xml = simplexml_load_file($this->root() . '/etc/module.xml');
        self::assertNotFalse($xml);
        self::assertFalse(isset($xml->module['setup_version']));

        $sequence = [];
        foreach ($xml->module->sequence->module as $module) {
            $sequence[] = (string)$module['name'];
        }
        $expected = array_map(
            static fn (string $module): string => 'Magento_' . $module,
            array_values(array_diff($this->usedMagentoNamespaces(), ['Framework']))
        );
        self::assertEqualsCanonicalizing($expected, $sequence);
    }

    public function testEveryPhpAndXmlFileHasTheOslHeader(): void
    {
        $files = $this->sourceFiles();
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            self::assertStringContainsString(self::HEADER, (string)file_get_contents($file), $file);
        }
    }

    public function testTranslationFileHasTwoColumnsPerLine(): void
    {
        $handle = fopen($this->root() . '/i18n/en_US.csv', 'r');
        self::assertNotFalse($handle);
        $lines = 0;
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $lines++;
            self::assertCount(2, $row, 'line ' . $lines);
            self::assertSame($row[0], $row[1], 'line ' . $lines);
        }
        fclose($handle);
        self::assertGreaterThan(0, $lines);
    }

    public function testEveryTranslatablePhraseIsInTheTranslationFile(): void
    {
        $phrases = [...$this->xmlPhrases(), ...$this->phpPhrases(), ...$this->jsPhrases()];
        self::assertContains('Explain Prices', $phrases);
        self::assertContains('Price Explanation: %1', $phrases);
        self::assertContains('(stopped by rule #%1)', $phrases);
        self::assertContains('Quote with ID "%1" does not exist.', $phrases);

        $missing = array_values(array_unique(array_diff($phrases, $this->translationKeys())));
        sort($missing);
        self::assertSame([], $missing);
    }

    public function testGitattributesExcludesTestsAndGithubFromArchives(): void
    {
        $lines = file($this->root() . '/.gitattributes', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        self::assertContains('/Test export-ignore', $lines);
        self::assertContains('/.github export-ignore', $lines);
    }

    public function testCiRunsIntegrationAndUploadsUnitCoverage(): void
    {
        $jobs = $this->ciJobs();
        self::assertArrayHasKey('lint', $jobs);
        self::assertArrayHasKey('integration', $jobs);
        self::assertStringContainsString('Test/Integration/phpunit.xml', $jobs['integration']);
        self::assertStringContainsString('image: mysql:8.4', $jobs['integration']);
        self::assertStringContainsString('image: opensearchproject/opensearch', $jobs['integration']);
        self::assertStringNotContainsString('continue-on-error', $jobs['integration']);
        self::assertStringContainsString('-c Test/Unit/phpunit.xml', $jobs['test']);
        self::assertStringContainsString('--coverage-clover coverage.xml', $jobs['test']);
        self::assertStringContainsString('codecov/codecov-action', $jobs['test']);
    }

    public function testSourceHasNoWritePathForTheExplainedQuote(): void
    {
        foreach ($this->sourceFiles() as $file) {
            if (!preg_match('/\.(php|phtml)$/', $file) || str_contains($file, '/Test/')) {
                continue;
            }
            // The token nonce is the only allowed write (cache); entity saves are not.
            $code = str_replace('$this->cache->save(', '', (string)file_get_contents($file));
            self::assertDoesNotMatchRegularExpression('/->save\(|->commit\(|->delete\(/', $code, $file);
            self::assertStringNotContainsString('CartRepositoryInterface', $code, $file);
            self::assertStringNotContainsString('QuoteRepository', $code, $file);
        }
    }

    /**
     * Composer packages behind every `Magento\*` and `Symfony\Component\*` namespace the module references.
     *
     * @return string[]
     */
    private function usedPackages(): array
    {
        $packages = [];
        foreach ($this->usedMagentoNamespaces() as $module) {
            $packages[] = $module === 'Framework'
                ? 'magento/framework'
                : 'magento/module-' . strtolower((string)preg_replace('/(?<!^)[A-Z]/', '-$0', $module));
        }
        foreach ($this->referencedNamespaces('Symfony\\\\Component') as $component) {
            $packages[] = 'symfony/' . strtolower($component);
        }

        return $packages;
    }

    /**
     * @return string[]
     */
    private function usedMagentoNamespaces(): array
    {
        $namespaces = $this->referencedNamespaces('Magento');
        self::assertContains('Framework', $namespaces);

        return $namespaces;
    }

    /**
     * Second-level names after `$prefix\` referenced from non-test PHP, templates and XML.
     *
     * @param string $prefix regex-escaped namespace prefix
     * @return string[]
     */
    private function referencedNamespaces(string $prefix): array
    {
        $names = [];
        foreach ($this->sourceFiles() as $file) {
            if (str_contains($file, '/Test/')) {
                continue;
            }
            preg_match_all('/\b' . $prefix . '\\\\{1,2}([A-Z][A-Za-z]+)\\\\/', (string)file_get_contents($file), $m);
            array_push($names, ...$m[1]);
        }
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * @return string[]
     */
    private function translationKeys(): array
    {
        $handle = fopen($this->root() . '/i18n/en_US.csv', 'r');
        self::assertNotFalse($handle);
        $keys = [];
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $keys[] = (string)$row[0];
        }
        fclose($handle);

        return $keys;
    }

    /**
     * Texts of `translate="true"` elements, the attributes or children a `translate="a b"` list names,
     * and the module's ACL titles.
     *
     * @return string[]
     */
    private function xmlPhrases(): array
    {
        $phrases = [];
        foreach ($this->sourceFiles() as $file) {
            if (!str_ends_with($file, '.xml') || str_contains($file, '/Test/')) {
                continue;
            }
            $xml = simplexml_load_file($file);
            self::assertNotFalse($xml, $file);
            foreach ($xml->xpath('//*[@translate]') ?: [] as $node) {
                $translate = trim((string)$node['translate']);
                if ($translate === 'true') {
                    $phrases[] = trim((string)$node);
                    continue;
                }
                foreach (preg_split('/\s+/', $translate) ?: [] as $name) {
                    $phrases[] = trim(isset($node[$name]) ? (string)$node[$name] : (string)$node->{$name});
                }
            }
            foreach ($xml->xpath('//resource[starts-with(@id, "DmLab_")][@title]') ?: [] as $node) {
                $phrases[] = (string)$node['title'];
            }
        }

        return array_values(array_filter($phrases, static fn (string $phrase): bool => $phrase !== ''));
    }

    /**
     * Literal first arguments of `__()` in PHP and templates, single-quoted pieces joined by `.`.
     *
     * @return string[]
     */
    private function phpPhrases(): array
    {
        $literal = "'(?:[^'\\\\]|\\\\.)*'";
        $pattern = '/\b__\(\s*(' . $literal . '(?:\s*\.\s*' . $literal . ')*)\s*[,)]/';
        $phrases = [];
        foreach ($this->sourceFiles() as $file) {
            if (!preg_match('/\.(php|phtml)$/', $file) || str_contains($file, '/Test/')) {
                continue;
            }
            preg_match_all($pattern, (string)file_get_contents($file), $matches);
            foreach ($matches[1] as $expression) {
                preg_match_all('/' . $literal . '/', $expression, $parts);
                $phrases[] = implode('', array_map(
                    static fn (string $part): string => stripslashes(substr($part, 1, -1)),
                    $parts[0]
                ));
            }
        }

        return $phrases;
    }

    /**
     * Literal arguments of `$t('...')` in the module's JS.
     *
     * @return string[]
     */
    private function jsPhrases(): array
    {
        $phrases = [];
        foreach ($this->sourceFiles() as $file) {
            if (!str_ends_with($file, '.js') || str_contains($file, '/Test/')) {
                continue;
            }
            preg_match_all("/\\\$t\\('((?:[^'\\\\]|\\\\.)*)'\\)/", (string)file_get_contents($file), $m);
            array_push($phrases, ...array_map('stripslashes', $m[1]));
        }

        return $phrases;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Body of every top-level job in `ci.yml`, keyed by job id.
     *
     * @return array<string, string>
     */
    private function ciJobs(): array
    {
        $yaml = (string)file_get_contents($this->root() . '/.github/workflows/ci.yml');
        $jobs = substr($yaml, (int)strpos($yaml, "\njobs:\n"));
        $parts = preg_split('/^  ([\w-]+):\n/m', $jobs, -1, PREG_SPLIT_DELIM_CAPTURE);
        self::assertIsArray($parts);
        $result = [];
        $count = count($parts);
        for ($i = 1; $i + 1 < $count; $i += 2) {
            $result[$parts[$i]] = $parts[$i + 1];
        }

        return $result;
    }

    /**
     * @return string[]
     */
    private function sourceFiles(): array
    {
        $root = $this->root();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        $files = [];
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (preg_match('#^(\.[^/]+|vendor|docs)/#', $relative)
                || !preg_match('/\.(php|xml|phtml|js)$/', $relative)
            ) {
                continue;
            }
            $files[] = $file->getPathname();
        }

        return $files;
    }
}
