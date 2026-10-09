<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Page;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Page\PageTreeRules;

/**
 * A page may only stand where Contao's back end lets it stand.
 *
 * Contao enforces the shape of the page tree in `PageTypeAccessVoter`
 * (`validateRootType()`, `validateFirstLevelType()`), a permission voter the
 * console write path never asks:
 *
 * - a website root (`type=root`) stands at the top level, and nothing else does;
 * - an error page (`error_401`, `error_403`, `error_404`, `error_503`) stands
 *   directly below a website root, at most one of each type per root.
 *
 * Until v1.1.0 `page update --set pid=0` put a regular page at the top level,
 * `--set type=root` made a subpage a root, and `page create` without `--pid`
 * created a regular page at the top level. Found on 2026-10-09 while checking
 * the move operations of Contao's 6.1 API (contao/contao#10400).
 */
class PageTreeRulesTest extends TestCase
{
    /**
     * @param array<int, array{type: string, pid: int}> $pages id => stored page
     */
    private function rules(array $pages): PageTreeRules
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturnCallback(
            static fn (string $sql, array $params): array|false => isset($pages[$params[0]])
                ? ['type' => $pages[$params[0]]['type'], 'pid' => (string) $pages[$params[0]]['pid']]
                : false,
        );
        $connection->method('fetchOne')->willReturnCallback(
            static function (string $sql, array $params) use ($pages): mixed {
                if (str_contains($sql, 'SELECT type FROM tl_page WHERE id')) {
                    return $pages[$params[0]]['type'] ?? false;
                }

                // other page of this type below this parent
                foreach ($pages as $id => $page) {
                    if ($page['pid'] === $params[0] && $page['type'] === $params[1] && $id !== $params[2]) {
                        return $id;
                    }
                }

                return false;
            },
        );

        return new PageTreeRules($connection);
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $stored
     */
    private function refusal(PageTreeRules $rules, array $fields, array $stored = [], ?int $id = null): ?string
    {
        try {
            $rules->assertPlacement($fields, $stored, $id);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private const TREE = [
        1 => ['type' => 'root', 'pid' => 0],
        2 => ['type' => 'regular', 'pid' => 1],
        3 => ['type' => 'error_404', 'pid' => 1],
        9 => ['type' => 'root', 'pid' => 0],
    ];

    public function testARegularPageBelowAPagePasses(): void
    {
        $this->assertNull($this->refusal($this->rules(self::TREE), ['pid' => 2], ['type' => 'regular', 'pid' => 1], 5));
    }

    public function testARegularPageCannotMoveToTheTopLevel(): void
    {
        $message = (string) $this->refusal($this->rules(self::TREE), ['pid' => 0], ['type' => 'regular', 'pid' => 1], 2);

        $this->assertStringContainsString('Only a website root', $message);
        $this->assertStringContainsString('Nothing was written', $message);
    }

    public function testAnEmptyPidIsTheTopLevel(): void
    {
        $this->assertNotNull($this->refusal($this->rules(self::TREE), ['pid' => ''], ['type' => 'regular', 'pid' => 1], 2));
    }

    public function testARootCannotMoveBelowAPage(): void
    {
        $message = (string) $this->refusal($this->rules(self::TREE), ['pid' => 2], ['type' => 'root', 'pid' => 0], 9);

        $this->assertStringContainsString('website root', $message);
        $this->assertStringContainsString('top level', $message);
    }

    public function testASubpageCannotBecomeARoot(): void
    {
        $this->assertNotNull($this->refusal($this->rules(self::TREE), ['type' => 'root'], ['type' => 'regular', 'pid' => 1], 2));
    }

    public function testARootMayBeCreatedAtTheTopLevel(): void
    {
        $this->assertNull($this->refusal($this->rules(self::TREE), ['type' => 'root', 'pid' => 0]));
    }

    public function testARegularPageCannotBeCreatedAtTheTopLevel(): void
    {
        $this->assertNotNull($this->refusal($this->rules(self::TREE), ['type' => 'regular', 'pid' => 0]));
    }

    public function testAnErrorPageMustStandDirectlyBelowARoot(): void
    {
        $message = (string) $this->refusal($this->rules(self::TREE), ['type' => 'error_403', 'pid' => 2]);

        $this->assertStringContainsString('error_403', $message);
        $this->assertStringContainsString('directly below a website root', $message);
    }

    public function testASecondErrorPageOfTheSameTypeIsRefused(): void
    {
        $message = (string) $this->refusal($this->rules(self::TREE), ['type' => 'error_404', 'pid' => 1]);

        $this->assertStringContainsString('already has', $message);
        $this->assertStringContainsString('ID 3', $message);
    }

    public function testAnErrorPageOfAnotherTypeOrBelowAnotherRootPasses(): void
    {
        $rules = $this->rules(self::TREE);

        $this->assertNull($this->refusal($rules, ['type' => 'error_403', 'pid' => 1]));
        $this->assertNull($this->refusal($rules, ['pid' => 9], ['type' => 'error_404', 'pid' => 1], 3));
    }

    public function testAnErrorPageIsNotItsOwnDuplicate(): void
    {
        $this->assertNull($this->refusal($this->rules(self::TREE), ['type' => 'error_404', 'title' => 'x'], ['type' => 'error_404', 'pid' => 1], 3));
    }

    public function testAnUpdateWithoutTypeOrPidIsNotChecked(): void
    {
        // A page already at the wrong place stays editable — fixing it is the way out.
        $this->assertNull($this->refusal($this->rules([]), ['title' => 'x'], ['type' => 'regular', 'pid' => 0], 4));
    }

    public function testCreateAndUpdateApplyIt(): void
    {
        foreach (['PageCreateCommand.php', 'PageUpdateCommand.php'] as $file) {
            $source = (string) file_get_contents(__DIR__ . '/../../../src/Command/' . $file);

            $this->assertStringContainsString('->assertPlacement(', $source, "$file must check the page's place in the tree");
        }
    }

    /**
     * Review W3 (2026-10-09): `record clone` of an error page put a second 404 below
     * the same root — the clone keeps the source's parent and type. The cloner checks
     * the stored clone inside its transaction, as it does the URL rules.
     */
    public function testAClonedSecondErrorPageIsRefused(): void
    {
        $tree = self::TREE + [40 => ['type' => 'error_404', 'pid' => 1]];

        $message = '';
        try {
            $this->rules($tree)->assertPlaced(40);
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringContainsString('already has a page of type error_404 (ID 3)', $message);
    }

    public function testAClonedRegularPageAndRootPass(): void
    {
        $rules = $this->rules(self::TREE + [41 => ['type' => 'regular', 'pid' => 1], 42 => ['type' => 'root', 'pid' => 0]]);

        $rules->assertPlaced(41);
        $rules->assertPlaced(42);
        $this->addToAssertionCount(1);
    }

    public function testTheClonerChecksTheClone(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../src/Service/Cloner/PageCloner.php');

        $this->assertStringContainsString('->assertPlaced($newRootId)', $source, 'PageCloner must check the cloned root page against the page tree rules');
    }

    /**
     * Review H1 (2026-10-09): the test above only finds the call in the source.
     * Changing `||` to `&&` in PageUpdateCommand — the rule only when type AND pid
     * are written — left every test green. This one runs the command's own
     * applyToRecord() with the rules set the way the container sets them.
     *
     * @dataProvider oneFieldMoves
     *
     * @param array<string, mixed> $fields
     */
    public function testPageUpdateChecksTypeOrPidAlone(array $fields): void
    {
        $command = new class($this->createMock(\Contao\CoreBundle\Framework\ContaoFramework::class)) extends \Webwerkwien\ContaoAiCoreBundle\Command\PageUpdateCommand {
            protected function storedRow(string $table, ?int $id): array
            {
                return ['id' => 2, 'type' => 'regular', 'pid' => 1];
            }
        };
        $command->setPageTreeRules($this->rules(self::TREE));

        $this->expectException(\InvalidArgumentException::class);

        (new \ReflectionMethod($command, 'applyToRecord'))->invoke($command, 2, $fields);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function oneFieldMoves(): array
    {
        return [
            'pid alone'  => [['pid' => 0]],
            'type alone' => [['type' => 'root']],
        ];
    }

    public function testAnUpdateThatKeepsTypeAndPidIsNotChecked(): void
    {
        $this->assertNull($this->refusal($this->rules([]), ['pid' => 0, 'type' => 'regular'], ['type' => 'regular', 'pid' => 0], 4));
    }
}
