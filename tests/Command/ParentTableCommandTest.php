<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CalendarModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FaqCategoryModel;
use Contao\NewsArchiveModel;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\CalendarCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\CalendarDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\CalendarReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\CalendarUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\FaqCategoryCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\FaqCategoryDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\FaqCategoryReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\FaqCategoryUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\NewsArchiveCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\NewsArchiveDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\NewsArchiveReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\NewsArchiveUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;
use Webwerkwien\ContaoAiCoreBundle\Tests\NeedsContaoContainerTrait;

/**
 * The three parent tables — the same gap three times over.
 *
 * `news:create`, `event:create` and `faq:create` all took a `pid`, and the
 * record that `pid` pointed at could not be created. **The child worked, the
 * parent did not**, so the first news item on a fresh install still meant
 * opening the back end.
 *
 * What is worth testing is not the create itself — it is thirty lines that
 * every other create command in this bundle shares — but the requirement rule,
 * because it is conditional in two different ways and each table exercises a
 * different corner of it:
 *
 *  - `tl_news_archive` and `tl_calendar`: `jumpTo` always, `groups` only for a
 *    protected record (subpalette)
 *  - `tl_faq_category`: `headline` always, no `protected` subpalette at all,
 *    and `jumpTo` in the palette *without* being mandatory
 *
 * All three read it from the DCA through the shared `missingMandatoryFields()`,
 * which is why the last case works without anything special: nothing here
 * knows that FAQ categories are different.
 */
class ParentTableCommandTest extends TestCase
{
    use NeedsContaoContainerTrait;

    private function fw(): ContaoFramework
    {
        return $this->createMock(ContaoFramework::class);
    }

    private function wire(object $command): object
    {
        $command->setLogger($this->createMock(LoggerInterface::class));
        $command->setVersionManager($this->createMock(VersionManager::class));

        return $command;
    }

    /**
     * @return array<string, mixed>
     */
    private function runCommand(object $command, array $input): array
    {
        $tester = new CommandTester($command);
        $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($decoded, 'The command must answer with JSON.');

        return $decoded;
    }

    protected function setUp(): void
    {
        // Shaped like Contao's own: title + jumpTo mandatory in the palette,
        // groups mandatory only inside the `protected` subpalette.
        $parent = [
            'palettes'    => ['default' => '{title_legend},title,jumpTo;{protected_legend:hide},protected'],
            'subpalettes' => ['protected' => 'groups'],
            'fields'      => [
                'title'     => ['eval' => ['mandatory' => true]],
                'jumpTo'    => ['inputType' => 'pageTree', 'eval' => ['mandatory' => true]],
                'protected' => ['inputType' => 'checkbox'],
                'groups'    => ['inputType' => 'checkbox', 'eval' => ['mandatory' => true, 'multiple' => true]],
            ],
        ];

        $GLOBALS['TL_DCA']['tl_news_archive'] = $parent;
        $GLOBALS['TL_DCA']['tl_calendar']     = $parent;

        // No `protected` subpalette; headline mandatory; jumpTo shown but optional.
        $GLOBALS['TL_DCA']['tl_faq_category'] = [
            'palettes' => ['default' => '{title_legend},title,headline,jumpTo'],
            'fields'   => [
                'title'    => ['eval' => ['mandatory' => true]],
                'headline' => ['eval' => ['mandatory' => true]],
                'jumpTo'   => ['inputType' => 'pageTree'],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['TL_DCA']['tl_news_archive'],
            $GLOBALS['TL_DCA']['tl_calendar'],
            $GLOBALS['TL_DCA']['tl_faq_category'],
        );
        parent::tearDown();
    }

    // --- every one of them insists on a title ---

    /**
     * @dataProvider creators
     */
    public function testCreateRefusesAMissingTitle(string $commandClass): void
    {
        $out = $this->runCommand($this->wire(new $commandClass($this->fw())), []);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('--title', $out['message']);
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function creators(): array
    {
        return [
            'news archive' => [NewsArchiveCreateCommand::class],
            'calendar'     => [CalendarCreateCommand::class],
            'faq category' => [FaqCategoryCreateCommand::class],
        ];
    }

    // --- the palette rule: jumpTo is not optional for an archive ---

    /**
     * An archive with no `jumpTo` renders links to nowhere. Contao marks it
     * mandatory in the default palette, so it is always required.
     *
     * @dataProvider jumpToCreators
     */
    public function testAnArchiveOrCalendarNeedsAJumpToPage(string $commandClass): void
    {
        $out = $this->runCommand($this->wire(new $commandClass($this->fw())), ['--title' => 'Blog']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('jumpTo', $out['message']);
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function jumpToCreators(): array
    {
        return [
            'news archive' => [NewsArchiveCreateCommand::class],
            'calendar'     => [CalendarCreateCommand::class],
        ];
    }

    // --- the subpalette rule: groups only for a protected record ---

    /**
     * The point of reading subpalettes rather than the flags alone: `groups` is
     * mandatory, and demanding it always would refuse every public archive.
     */
    public function testAPublicArchiveDoesNotNeedGroups(): void
    {
        $command = $this->wire(new NewsArchiveCreateCommand($this->fw()));
        $missing = $command->missingMandatoryFields(
            'tl_news_archive',
            'default',
            ['jumpTo' => '7'],
            ['title'],
        );

        $this->assertSame([], $missing);
    }

    public function testAProtectedArchiveNeedsGroups(): void
    {
        $command = $this->wire(new NewsArchiveCreateCommand($this->fw()));
        $missing = $command->missingMandatoryFields(
            'tl_news_archive',
            'default',
            ['jumpTo' => '7', 'protected' => '1'],
            ['title'],
        );

        $this->assertSame(['groups'], $missing);
    }

    public function testSupplyingGroupsSatisfiesTheProtectedCase(): void
    {
        $command = $this->wire(new NewsArchiveCreateCommand($this->fw()));
        $missing = $command->missingMandatoryFields(
            'tl_news_archive',
            'default',
            ['jumpTo' => '7', 'protected' => '1', 'groups' => '2'],
            ['title'],
        );

        $this->assertSame([], $missing);
    }

    // --- the FAQ category is the odd one, and nothing knows that ---

    public function testAnFaqCategoryNeedsAHeadlineRatherThanAJumpTo(): void
    {
        $out = $this->runCommand(
            $this->wire(new FaqCategoryCreateCommand($this->fw())),
            ['--title' => 'Support'],
        );

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('headline', $out['message']);
        $this->assertStringNotContainsString('jumpTo', $out['message'] ?? '');
    }

    /**
     * `jumpTo` is in this palette but not mandatory — a category without one is
     * a legitimate record, and the rule has to tell the two cases apart.
     */
    public function testAnFaqCategoryIsCompleteWithoutAJumpTo(): void
    {
        $command = $this->wire(new FaqCategoryCreateCommand($this->fw()));
        $missing = $command->missingMandatoryFields(
            'tl_faq_category',
            'default',
            ['headline' => 'Frequently asked'],
            ['title'],
        );

        $this->assertSame([], $missing);
    }

    // --- each command is bound to the right model ---

    /**
     * @dataProvider modelBindings
     */
    public function testTheCommandPointsAtTheRightModel(string $commandClass, string $modelClass): void
    {
        $command = new $commandClass($this->fw());
        $method  = new \ReflectionMethod($command, 'modelClass');

        $this->assertSame($modelClass, $method->invoke($command));
    }

    /**
     * @return array<string, array{class-string, class-string}>
     */
    public static function modelBindings(): array
    {
        return [
            'archive read'    => [NewsArchiveReadCommand::class, NewsArchiveModel::class],
            'archive update'  => [NewsArchiveUpdateCommand::class, NewsArchiveModel::class],
            'archive delete'  => [NewsArchiveDeleteCommand::class, NewsArchiveModel::class],
            'calendar read'   => [CalendarReadCommand::class, CalendarModel::class],
            'calendar update' => [CalendarUpdateCommand::class, CalendarModel::class],
            'calendar delete' => [CalendarDeleteCommand::class, CalendarModel::class],
            'category read'   => [FaqCategoryReadCommand::class, FaqCategoryModel::class],
            'category update' => [FaqCategoryUpdateCommand::class, FaqCategoryModel::class],
            'category delete' => [FaqCategoryDeleteCommand::class, FaqCategoryModel::class],
        ];
    }
}
