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

    public function testAnUpdateThatKeepsTypeAndPidIsNotChecked(): void
    {
        $this->assertNull($this->refusal($this->rules([]), ['pid' => 0, 'type' => 'regular'], ['type' => 'regular', 'pid' => 0], 4));
    }
}
