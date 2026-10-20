<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Language;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\OptionsResolver;
use Webwerkwien\ContaoAiCoreBundle\Service\Language\ChangeLanguageLinks;
use Webwerkwien\ContaoAiCoreBundle\Service\Language\LinkTargetDiagnosis;

/**
 * Field and valid targets of a translation link, as changelanguage defines them
 * (v1.4.0). The page tree check needs Contao's database and is covered on c5.
 */
class ChangeLanguageLinksTest extends TestCase
{
    private function links(?array $values, ?string $error = null): ChangeLanguageLinks
    {
        $options = $this->createMock(OptionsResolver::class);
        $options->method('values')->willReturn($values);
        $options->method('lastError')->willReturn($error);

        return new ChangeLanguageLinks($options);
    }

    public function testTheFieldFollowsTheTable(): void
    {
        $links = $this->links([]);

        $this->assertSame('languageMain', $links->field('tl_page', ['type' => 'regular']));
        $this->assertSame('languageRoot', $links->field('tl_page', ['type' => 'root']));
        $this->assertSame('languageMain', $links->field('tl_article', []));
        $this->assertSame('languageMain', $links->field('tl_news', []));
        $this->assertSame('master', $links->field('tl_news_archive', []));
        $this->assertSame('master', $links->field('tl_calendar', []));
        $this->assertSame('master', $links->field('tl_faq_category', []));
    }

    public function testATargetTheBackEndOffersIsAccepted(): void
    {
        $this->assertNull($this->links(['4', '9'])->refusal('tl_news', 12, ['pid' => 3], 9));
    }

    public function testATargetTheBackEndDoesNotOfferIsRefusedWithTheValidOnes(): void
    {
        $refusal = (string) $this->links(['4', '9'])->refusal('tl_news', 12, ['pid' => 3], 5);

        $this->assertStringContainsString('5 is not a valid languageMain for tl_news:12', $refusal);
        $this->assertStringContainsString('Valid targets: 4, 9.', $refusal);
    }

    /**
     * @dataProvider emptyTargets
     */
    public function testWithoutAnyTargetTheRefusalSaysWhatToLinkFirst(string $table, string $expected): void
    {
        $this->assertStringContainsString($expected, (string) $this->links([])->refusal($table, 12, ['fallback' => 1], 5));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function emptyTargets(): array
    {
        return [
            'article' => ['tl_article', "page has to be linked"],
            'archive' => ['tl_news_archive', "jumpTo page has to be linked"],
            'news'    => ['tl_news', 'language link --table tl_news_archive'],
            'event'   => ['tl_calendar_events', 'language link --table tl_calendar'],
            'faq'     => ['tl_faq', 'language link --table tl_faq_category'],
        ];
    }

    /**
     * v1.11.1: with the diagnosis the refusal names the page that is the reason — over the
     * real class on SQLite, the showcase case — instead of "link the page first".
     */
    public function testAnArticleRefusalNamesTheUnpublishedCounterpart(): void
    {
        $options = $this->createMock(OptionsResolver::class);
        $options->method('values')->willReturn([]);
        $links = new ChangeLanguageLinks($options, new LinkTargetDiagnosis(LinkTargetDiagnosisTest::database()));

        $refusal = (string) $links->refusal('tl_article', 98, ['pid' => 80], 30);

        $this->assertStringContainsString('30 is not a valid languageMain for tl_article:98', $refusal);
        $this->assertStringContainsString('There are none: Page 80 is linked to page 28 of the main language, but page 28 is unpublished', $refusal);
        $this->assertStringNotContainsString('has to be linked to its page in the main language first', $refusal);
    }

    /**
     * Where the diagnosis knows no reason, the general rule stays.
     */
    public function testWithoutAKnownReasonTheGeneralRuleStays(): void
    {
        $options = $this->createMock(OptionsResolver::class);
        $options->method('values')->willReturn([]);
        $links = new ChangeLanguageLinks($options, new LinkTargetDiagnosis(LinkTargetDiagnosisTest::database()));

        $this->assertStringContainsString('page has to be linked', (string) $links->refusal('tl_article', 99, ['pid' => 77], 30));
    }

    public function testOnlyAFallbackRootTakesALanguageRoot(): void
    {
        $refusal = (string) $this->links(['1'])->refusal('tl_page', 201, ['type' => 'root', 'fallback' => 0], 1);

        $this->assertStringContainsString('is not a language fallback', $refusal);
    }

    public function testUnreadableTargetsAreSaidNotGuessed(): void
    {
        $refusal = (string) $this->links(null, 'Call to a member function on null')->refusal('tl_faq', 12, [], 5);

        $this->assertStringContainsString('could not be read: Call to a member function on null', $refusal);
    }
}
