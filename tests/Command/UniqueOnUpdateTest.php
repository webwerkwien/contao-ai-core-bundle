<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * `eval.unique` is now checked on update too, not only on create.
 *
 * Thirteen fields across the bundled DCAs carry it — `tl_user.username`,
 * `tl_member.email`, `tl_theme.name`, the four aliases — and for most of them
 * there is **no unique index behind it**. The rule lives in the DCA alone, so a
 * write path that goes around `DC_Table` drops it. Two back end users called
 * the same name is the kind of thing nobody notices until the day it matters
 * which one someone logged in as.
 *
 * 🎯 **The id parameter is the whole story.** `Database::isUniqueValue()` takes
 * the record to exclude, and `DC_Table::save()` passes the one being edited —
 * that is what makes "save without changing the name" work. Without it every
 * update of a unique field would find itself and refuse, which is exactly why
 * this stayed create-only.
 *
 * ⚠️ **The note that held it back was wrong.** It said a DCA-wide check "would
 * also reject renames that pass today (`tl_page.alias`)". `tl_page.alias` has
 * no `eval.unique` — page aliases may repeat across roots and are checked by a
 * `save_callback` instead. Measuring the DCAs settled it; the objection had
 * nothing behind it.
 */
class UniqueOnUpdateTest extends TestCase
{
    /**
     * @param array<string, list<int|null>> $taken value => ids that hold it
     */
    private function subject(array $taken): ImageSizeUpdateCommand
    {
        return new class ($this->createMock(ContaoFramework::class), $taken) extends ImageSizeUpdateCommand {
            /**
             * @param array<string, list<int>> $taken
             */
            public function __construct(ContaoFramework $framework, private readonly array $taken)
            {
                parent::__construct($framework);
            }

            /** Mirrors Database::isUniqueValue(): free when no *other* row holds it. */
            protected function uniqueValueIsFree(string $table, string $field, mixed $value, ?int $excludeId): bool
            {
                foreach ($this->taken[(string) $value] ?? [] as $id) {
                    if ($id !== $excludeId) {
                        return false;
                    }
                }

                return true;
            }
        };
    }

    private function refusalFor(array $taken, array $fields, ?int $excludeId): ?string
    {
        $subject = $this->subject($taken);
        $method  = new \ReflectionMethod($subject, 'refuseTakenUniqueValues');

        try {
            $method->invoke($subject, 'tl_test', $fields, $excludeId);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    protected function setUp(): void
    {
        $GLOBALS['TL_DCA']['tl_test']['fields'] = [
            'name'  => ['eval' => ['unique' => true]],
            'alias' => ['eval' => ['unique' => true]],
            'title' => ['eval' => []],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_test']);
    }

    public function testAFreeValuePasses(): void
    {
        $this->assertNull($this->refusalFor(['Redakteure' => [7]], ['name' => 'Autoren'], 3));
    }

    public function testAValueHeldByAnotherRecordIsRefused(): void
    {
        $message = $this->refusalFor(['Redakteure' => [7]], ['name' => 'Redakteure'], 3);

        $this->assertNotNull($message);
        $this->assertStringContainsString('name=Redakteure', $message);
    }

    /**
     * The regression this whole change hinges on: saving a unique field without
     * changing it must not find itself.
     */
    public function testARecordDoesNotCollideWithItself(): void
    {
        $this->assertNull($this->refusalFor(['Redakteure' => [3]], ['name' => 'Redakteure'], 3));
    }

    /**
     * On create there is no record to exclude, so the same value is taken.
     */
    public function testOnCreateThereIsNothingToExclude(): void
    {
        $this->assertNotNull($this->refusalFor(['Redakteure' => [3]], ['name' => 'Redakteure'], null));
    }

    public function testAFieldWithoutUniqueIsIgnored(): void
    {
        $this->assertNull($this->refusalFor(['Doppelt' => [7]], ['title' => 'Doppelt'], 3));
    }

    /**
     * `DC_Table::save()` runs the check only for a non-empty value. Several
     * unique fields are optional, and refusing the second empty alias would be
     * absurd.
     */
    public function testAnEmptyValueIsNotADuplicate(): void
    {
        $this->assertNull($this->refusalFor(['' => [7, 9]], ['alias' => ''], 3));
    }

    public function testEveryTakenValueIsNamedAtOnce(): void
    {
        $message = (string) $this->refusalFor(
            ['Redakteure' => [7], 'mein-alias' => [8]],
            ['name' => 'Redakteure', 'alias' => 'mein-alias', 'title' => 'egal'],
            3,
        );

        $this->assertStringContainsString('name=Redakteure', $message);
        $this->assertStringContainsString('alias=mein-alias', $message);
        $this->assertStringNotContainsString('title', $message);
    }

    /**
     * A database that cannot answer must not read as "taken" — the write then
     * fails on its own terms and says what actually went wrong.
     */
    public function testAnUnanswerableLookupCountsAsFree(): void
    {
        $subject = new class ($this->createMock(ContaoFramework::class)) extends ImageSizeUpdateCommand {
            protected function uniqueValueIsFree(string $table, string $field, mixed $value, ?int $excludeId): bool
            {
                return true; // what the real one answers when Database throws
            }
        };

        $method = new \ReflectionMethod($subject, 'refuseTakenUniqueValues');
        $method->invoke($subject, 'tl_test', ['name' => 'Redakteure'], 3);

        $this->expectNotToPerformAssertions();
    }

    /**
     * The update path has to pass the id, or the exclusion never happens and
     * every unique field becomes unsavable.
     */
    public function testTheUpdatePathPassesTheRecordId(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Command/AbstractModelUpdateCommand.php');

        $this->assertStringContainsString(
            'convertFields($class::getTable(), $fields, $id)',
            $source,
            'AbstractModelUpdateCommand must pass the record id into convertFields(), or the '
            . 'unique check compares the record against itself and refuses every save.',
        );
    }
}
