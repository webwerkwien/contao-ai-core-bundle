<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Copier;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webwerkwien\ContaoAiCoreBundle\Service\Copier\CopyLabel;

/**
 * The copy mark in the operator's language, as the cloners and the copier use it.
 */
class CopyLabelTest extends TestCase
{
    /** @var list<string> usernames looked up */
    private array $lookups = [];

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    /**
     * @param array<string, string> $languages back-end language per known username
     */
    private function copyLabel(array $languages, ?TranslatorInterface $translator): CopyLabel
    {
        $users = $this->getMockBuilder(Adapter::class)->disableOriginalConstructor()->onlyMethods(['__call'])->getMock();
        $users->method('__call')->willReturnCallback(function (string $method, array $arguments) use ($languages): ?object {
            if ('findOneBy' !== $method) {
                return null;
            }

            $this->lookups[] = (string) $arguments[1];

            return isset($languages[$arguments[1]]) ? (object) ['language' => $languages[$arguments[1]]] : null;
        });

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($users);

        return new CopyLabel($framework, $translator);
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $parameters, ?string $domain, ?string $locale): string => match ([$id, $locale]) {
            ['MSC.copyOf', 'de'] => '%s (Kopie)',
            ['MSC.copyOf', 'fr'] => '%s (copie)',
            default              => $id,
        });

        return $translator;
    }

    public function testTheOperatorsLanguageWins(): void
    {
        $GLOBALS['TL_LANG']['MSC']['copyOf'] = '%s (copy)';

        self::assertSame('Team (copie)', $this->copyLabel(['k.editor' => 'fr'], $this->translator())->mark('Team', 'k.editor'));
    }

    public function testWithoutAKnownOperatorTheLoadedLanguageIsUsed(): void
    {
        $GLOBALS['TL_LANG']['MSC']['copyOf'] = '%s (Kopie)';
        $label = $this->copyLabel(['k.editor' => 'fr'], $this->translator());

        self::assertSame('Team (Kopie)', $label->mark('Team', 'deploy'), 'unknown to Contao');
        self::assertSame('Team (Kopie)', $label->mark('Team', ''), 'no operator at all');
        self::assertSame(['deploy'], $this->lookups, 'an empty operator is not looked up');
    }

    public function testALanguageWithoutTheTextFallsBackToTheLoadedOne(): void
    {
        $GLOBALS['TL_LANG']['MSC']['copyOf'] = '%s (Kopie)';

        self::assertSame('Team (Kopie)', $this->copyLabel(['k.editor' => 'xx'], $this->translator())->mark('Team', 'k.editor'));
    }

    public function testNothingLoadedMeansEnglish(): void
    {
        self::assertSame('Team (copy)', $this->copyLabel([], null)->mark('Team', 'k.editor'));
    }

    public function testAMarkedValueAndAnEmptyOneStayAsTheyAre(): void
    {
        $label = $this->copyLabel(['k.editor' => 'de'], $this->translator());

        self::assertSame('Team (Kopie)', $label->mark('Team (Kopie)', 'k.editor'));
        self::assertSame('', $label->mark('', 'k.editor'));
    }

    public function testTheLanguageIsReadAnewEachTime(): void
    {
        $languages = ['k.editor' => 'de'];
        $label     = $this->copyLabel($languages, $this->translator());

        $label->mark('A', 'k.editor');
        $label->mark('B', 'k.editor');

        self::assertSame(['k.editor', 'k.editor'], $this->lookups, 'nothing kept between calls: a worker lives longer than a user\'s language');
    }
}
