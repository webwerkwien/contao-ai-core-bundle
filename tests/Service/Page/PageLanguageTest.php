<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Page;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Page\PageLanguage;

/**
 * `tl_page.language` belongs to a root and nowhere else.
 *
 * 🟡 **Measured live on web.werk.wien on 2026-09-17** (ConpAI 1.0 acceptance test, Nr. 62):
 * pages 2 and 3, created in the back end, store no language — the field is only in the
 * root palette. Pages 11–13, created through `contao:page:create`, stored `de` (the
 * option's default applied to every type), and `record:clone` carried it along: the
 * English subpages 15–17 read `de`. The front end was right, because Contao takes the
 * language from the root at runtime; a caller reading or filtering `tl_page.language`
 * was not.
 *
 * Contao's own copy goes further: `language` carries `doNotCopy`, so a back-end copy
 * empties it even on the root (checked on c5 the same day). The cloner keeps a root's
 * language, because a clone into another language sets it in the same call.
 */
class PageLanguageTest extends TestCase
{
    public function testARootKeepsItsLanguage(): void
    {
        $this->assertSame('en', PageLanguage::forType('root', 'en'));
    }

    /**
     * @dataProvider notRoots
     */
    public function testEveryOtherTypeStoresNone(string $type): void
    {
        $this->assertSame('', PageLanguage::forType($type, 'de'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notRoots(): array
    {
        return [
            'regular'   => ['regular'],
            'forward'   => ['forward'],
            'error_404' => ['error_404'],
            'bundle'    => ['consho_product'],
        ];
    }

    /**
     * The wiring, since both writers go through models that need a database. Comments are
     * not stripped here; the assertions look for calls, which a comment would not make.
     *
     * @dataProvider writers
     */
    public function testBothPageWritersUseTheRule(string $file): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../src/' . $file);

        $this->assertStringContainsString('PageLanguage::forType(', $source, "{$file} writes tl_page.language without the root rule");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function writers(): array
    {
        return [
            'page create' => ['Command/PageCreateCommand.php'],
            'page clone'  => ['Service/Cloner/PageCloner.php'],
        ];
    }
}
