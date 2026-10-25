<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Cloner;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Cloner\AbstractDcaCloner;
use Webwerkwien\ContaoAiCoreBundle\Service\Cloner\FormCloner;
use Webwerkwien\ContaoAiCoreBundle\Service\Cloner\LayoutCloner;
use Webwerkwien\ContaoAiCoreBundle\Service\Cloner\ModuleCloner;
use Webwerkwien\ContaoAiCoreBundle\Service\Cloner\NewsletterChannelCloner;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\DcaCallbacks;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * The cloners of v1.8.0 against a small in-memory database.
 *
 * Contao's copy as the reference (DC_Table::copy() and copyChildren(), 5.3.51): a
 * form takes its fields along and loses `alias` and `formID`, a newsletter channel
 * takes its newsletters and, in Contao, its recipients — here only on request.
 */
class DcaClonerTest extends TestCase
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $tables = [];

    /** @var list<array{string, array<string, mixed>}> */
    private array $inserts = [];

    /** @var list<string> */
    private array $log = [];

    private int $nextId = 100;

    protected function setUp(): void
    {
        $varchar = "varchar(255) NOT NULL default ''";

        $GLOBALS['TL_DCA'] = [
            'tl_form' => ['fields' => [
                'title'  => ['sql' => $varchar],
                'alias'  => ['eval' => ['doNotCopy' => true], 'sql' => $varchar],
                'formID' => ['eval' => ['doNotCopy' => true], 'sql' => "varchar(64) NOT NULL default ''"],
                'jumpTo' => ['inputType' => 'pageTree', 'foreignKey' => 'tl_page.title', 'sql' => 'int(10) unsigned NOT NULL default 0'],
            ]],
            'tl_form_field' => ['fields' => [
                'sorting' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
                'name'    => ['sql' => $varchar],
            ]],
            'tl_newsletter_channel' => ['fields' => [
                'title'  => ['sql' => $varchar],
                'jumpTo' => ['inputType' => 'pageTree', 'foreignKey' => 'tl_page.title', 'sql' => 'int(10) unsigned NOT NULL default 0'],
            ]],
            'tl_newsletter' => ['fields' => [
                'subject' => ['sql' => $varchar],
                'alias'   => ['eval' => ['doNotCopy' => true, 'unique' => true], 'sql' => $varchar],
                'sent'    => ['eval' => ['doNotCopy' => true], 'sql' => ['type' => 'boolean', 'default' => false]],
            ]],
            'tl_newsletter_recipients' => ['fields' => [
                'email'   => ['sql' => $varchar],
                'active'  => ['sql' => ['type' => 'boolean', 'default' => false]],
                'addedOn' => ['eval' => ['doNotCopy' => true], 'sql' => ['type' => 'string', 'length' => 10, 'default' => '']],
            ]],
            'tl_module' => ['fields' => [
                'pid'    => ['foreignKey' => 'tl_theme.name', 'sql' => 'int(10) unsigned NOT NULL default 0'],
                'name'   => ['sql' => $varchar],
                'pages'  => ['inputType' => 'pageTree', 'sql' => 'blob NULL'],
                'jumpTo' => ['inputType' => 'pageTree', 'sql' => 'int(10) unsigned NOT NULL default 0'],
                // As the news bundle declares it (5.7): no relation, unlike cal_ and faq_readerModule.
                'news_readerModule' => ['inputType' => 'select', 'options_callback' => ['tl_module_news', 'getReaderModules'], 'sql' => 'int(10) unsigned NOT NULL default 0'],
                'cal_readerModule'  => ['inputType' => 'select', 'relation' => ['table' => 'tl_module', 'type' => 'hasOne', 'load' => 'lazy'], 'sql' => 'int(10) unsigned NOT NULL default 0'],
                'reg_homeDir'       => ['inputType' => 'fileTree', 'sql' => 'binary(16) NULL'],
                'rootPageDependentModules' => ['inputType' => 'rootPageDependentSelect', 'sql' => 'blob NULL'],
            ]],
            'tl_layout' => ['fields' => [
                'name'    => ['sql' => $varchar],
                'rows'    => ['sql' => "varchar(8) NOT NULL default ''"],
                'modules' => ['inputType' => 'moduleWizard', 'sql' => 'blob NULL'],
            ]],
        ];

        $this->tables = [
            'tl_form' => [4 => ['id' => 4, 'tstamp' => 1, 'title' => 'Kontakt', 'alias' => 'kontakt', 'formID' => 'contact', 'jumpTo' => '12']],
            'tl_form_field' => [
                20 => ['id' => 20, 'pid' => 4, 'tstamp' => 1, 'sorting' => 128, 'name' => 'email'],
                21 => ['id' => 21, 'pid' => 4, 'tstamp' => 1, 'sorting' => 64, 'name' => 'name'],
                22 => ['id' => 22, 'pid' => 9, 'tstamp' => 1, 'sorting' => 32, 'name' => 'other form'],
            ],
            'tl_newsletter_channel' => [3 => ['id' => 3, 'tstamp' => 1, 'title' => 'Updates', 'jumpTo' => '0']],
            'tl_newsletter' => [
                30 => ['id' => 30, 'pid' => 3, 'tstamp' => 1, 'subject' => 'Oktober', 'alias' => 'oktober', 'sent' => '1'],
            ],
            'tl_newsletter_recipients' => [
                40 => ['id' => 40, 'pid' => 3, 'tstamp' => 1, 'email' => 'a@example.org', 'active' => '1', 'addedOn' => '1700000000'],
            ],
            'tl_module' => [
                7 => ['id' => 7, 'pid' => 1, 'tstamp' => 1, 'name' => 'Navigation', 'pages' => 'a:0:{}', 'jumpTo' => '5'],
                9 => ['id' => 9, 'pid' => 1, 'tstamp' => 1, 'name' => 'Newsliste', 'pages' => 'a:0:{}', 'jumpTo' => '0',
                    'news_readerModule' => '11', 'cal_readerModule' => '0', 'reg_homeDir' => str_repeat("\0", 16),
                    'rootPageDependentModules' => 'a:2:{i:1;s:2:"12";i:76;s:2:"13";}'],
            ],
            'tl_layout' => [8 => ['id' => 8, 'pid' => 1, 'tstamp' => 1, 'name' => 'Standard', 'rows' => '3rw', 'modules' => 'a:1:{i:0;a:3:{s:3:"mod";s:1:"7";s:3:"col";s:4:"main";s:6:"enable";s:1:"1";}}']],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']);
    }

    /**
     * @param class-string<AbstractDcaCloner> $class
     */
    private function cloner(string $class): AbstractDcaCloner
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string => '`' . $name . '`');
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $work): mixed => $work());

        $connection->method('fetchAssociative')->willReturnCallback(function (string $sql, array $params): array|false {
            return $this->tables[$this->tableOf($sql)][(int) $params[0]] ?? false;
        });

        $connection->method('fetchAllAssociative')->willReturnCallback(function (string $sql, array $params): array {
            $rows = array_values(array_filter(
                $this->tables[$this->tableOf($sql)] ?? [],
                static fn (array $row): bool => (int) $row['pid'] === (int) $params[0],
            ));

            if (str_contains($sql, 'ORDER BY sorting')) {
                usort($rows, static fn (array $a, array $b): int => $a['sorting'] <=> $b['sorting']);
            }

            return $rows;
        });

        // Alias lookups: taken when any stored or inserted row of the table carries it.
        $connection->method('fetchOne')->willReturnCallback(function (string $sql, array $params): mixed {
            $table = $this->tableOf($sql);
            foreach ($this->tables[$table] ?? [] as $row) {
                if (($row['alias'] ?? null) === $params[0]) {
                    return $row['id'];
                }
            }

            return false;
        });

        $connection->method('insert')->willReturnCallback(function (string $table, array $data): int {
            $table = trim($table, '`');
            $row   = [];
            foreach ($data as $column => $value) {
                $row[trim($column, '`')] = $value;
            }

            $id = $this->nextId++;
            $this->tables[$table][$id] = ['id' => $id] + $row;
            $this->inserts[] = [$table, $row];
            $this->log[] = "insert $table:$id";

            return 1;
        });

        $connection->method('lastInsertId')->willReturnCallback(fn (): int => $this->nextId - 1);

        $versions = $this->createMock(VersionManager::class);
        $versions->method('createInitialVersion')->willReturnCallback(function (string $table, int $id): int {
            $this->log[] = "version $table:$id";

            return 0;
        });

        $callbacks = $this->createMock(DcaCallbacks::class);
        $callbacks->method('copy')->willReturnCallback(function (string $table, int $newId, int $sourceId): void {
            $this->log[] = "oncopy $table:$newId<-$sourceId";
        });

        return new $class($connection, $this->createMock(ContaoFramework::class), $versions, $callbacks);
    }

    private function tableOf(string $sql): string
    {
        preg_match('/FROM `(tl_[a-z_]+)`/', $sql, $match);

        return $match[1] ?? '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inserted(string $table): array
    {
        return array_values(array_map(
            static fn (array $insert): array => $insert[1],
            array_filter($this->inserts, static fn (array $insert): bool => $insert[0] === $table),
        ));
    }

    public function testAFormComesWithItsFieldsAndWithoutAliasAndFormId(): void
    {
        $result = $this->cloner(FormCloner::class)->clone(4, [], 'k.jones');

        [$form] = $this->inserted('tl_form');
        $this->assertSame('Kontakt (Kopie)', $form['title']);
        $this->assertSame('kontakt-kopie', $form['alias'], 'the alias is generated anew from the new title');
        $this->assertSame('', $form['formID'], 'two forms with one formID process each other\'s submissions');
        $this->assertSame('12', $form['jumpTo'], 'references stay as in the source');
        $this->assertArrayNotHasKey('id', $form);

        $fields = $this->inserted('tl_form_field');
        $this->assertSame(['name', 'email'], array_column($fields, 'name'), 'only this form\'s fields, in their order');
        $this->assertSame([100, 100], array_column($fields, 'pid'));
        $this->assertSame([64, 128], array_column($fields, 'sorting'));

        $this->assertSame(100, $result['id']);
        $this->assertSame(2, $result['count']);
        $this->assertSame(['tl_form_field' => 2], $result['children']);
        $this->assertSame(['alias', 'formID'], $result['not_copied']);
        $this->assertSame(['jumpTo'], $result['references']);
        $this->assertSame([], $result['ignored_modifications']);
    }

    public function testOncopyRunsPerChildAndForTheRootLast(): void
    {
        $this->cloner(FormCloner::class)->clone(4, [], 'k.jones');

        $this->assertSame([
            'insert tl_form:100',
            'version tl_form:100',
            'insert tl_form_field:101',
            'version tl_form_field:101',
            'oncopy tl_form_field:101<-21',
            'insert tl_form_field:102',
            'version tl_form_field:102',
            'oncopy tl_form_field:102<-20',
            'oncopy tl_form:100<-4',
        ], $this->log);
    }

    public function testOnlyTheNameIsTakenFromModifications(): void
    {
        $result = $this->cloner(FormCloner::class)->clone(4, ['title' => 'Contact', 'formID' => 'contact', 'jumpTo' => '99'], 'k.jones');

        [$form] = $this->inserted('tl_form');
        $this->assertSame('Contact', $form['title']);
        $this->assertSame('contact', $form['alias'], 'from the title given, not the source');
        $this->assertSame('', $form['formID']);
        $this->assertSame('12', $form['jumpTo']);
        $this->assertSame(['formID', 'jumpTo'], $result['ignored_modifications']);
    }

    public function testANewsletterChannelComesWithUnsentNewslettersAndWithoutRecipients(): void
    {
        $result = $this->cloner(NewsletterChannelCloner::class)->clone(3, [], 'k.jones');

        [$newsletter] = $this->inserted('tl_newsletter');
        $this->assertSame(0, $newsletter['sent'], 'written as 0, not false, which DBAL would bind as ""');
        $this->assertSame('oktober-2', $newsletter['alias']);
        $this->assertSame(100, $newsletter['pid']);

        $this->assertSame([], $this->inserted('tl_newsletter_recipients'), 'recipients consented to the source channel only');
        $this->assertSame(['tl_newsletter' => 1], $result['children']);
        $this->assertFalse($result['recipients_copied']);
        $this->assertSame([], $result['references'], 'jumpTo 0 is no reference');
    }

    public function testRecipientsOnlyOnRequestAndWithoutVersions(): void
    {
        $result = $this->cloner(NewsletterChannelCloner::class)->clone(3, [], 'k.jones', ['recipients' => true]);

        [$recipient] = $this->inserted('tl_newsletter_recipients');
        $this->assertSame('a@example.org', $recipient['email']);
        $this->assertSame('1', $recipient['active'], 'as in Contao\'s copy');
        $this->assertSame('', $recipient['addedOn'], 'shown as added manually, which it is');

        $this->assertSame(['tl_newsletter' => 1, 'tl_newsletter_recipients' => 1], $result['children']);
        $this->assertSame(1, $result['count'], 'count stays the number of newsletters');
        $this->assertTrue($result['recipients_copied']);
        $this->assertSame([], preg_grep('/^version tl_newsletter_recipients/', $this->log));
        $this->assertNotSame([], preg_grep('/^oncopy tl_newsletter_recipients/', $this->log));
    }

    public function testAModuleStaysInItsThemeAndNamesItsReferences(): void
    {
        $result = $this->cloner(ModuleCloner::class)->clone(7, [], 'k.jones');

        [$module] = $this->inserted('tl_module');
        $this->assertSame(1, (int) $module['pid']);
        $this->assertSame('Navigation (Kopie)', $module['name']);
        $this->assertSame(0, $result['count']);
        $this->assertArrayNotHasKey('children', $result, 'an empty list would arrive as JSON [] where others get {}');
        // pid is the theme, not a reference; an empty serialized list is none either.
        $this->assertSame(['jumpTo'], $result['references']);
    }

    /**
     * Before v1.9.1 the news
     * list's reader module was missing from `references`, the events list's was there,
     * and every module listed `reg_homeDir`. A reference left out is one the caller
     * never repoints, and noise on every module teaches the caller to ignore the list.
     */
    public function testReaderModulesAndRootDependentModulesAreReferencesAndANullUuidIsNot(): void
    {
        $result = $this->cloner(ModuleCloner::class)->clone(9, [], 'k.jones');

        $this->assertSame(['news_readerModule', 'rootPageDependentModules'], $result['references']);
    }

    public function testALayoutKeepsItsModulesAndItsReservedWordColumn(): void
    {
        $result = $this->cloner(LayoutCloner::class)->clone(8, ['name' => 'English'], 'k.jones');

        [$layout] = $this->inserted('tl_layout');
        $this->assertSame('English', $layout['name']);
        $this->assertSame('3rw', $layout['rows']);
        $this->assertSame($this->tables['tl_layout'][8]['modules'], $layout['modules']);
        $this->assertSame(['modules'], $result['references']);
    }

    public function testAnEmptyNameIsRefusedBeforeAnythingIsWritten(): void
    {
        try {
            $this->cloner(FormCloner::class)->clone(4, ['title' => '  '], 'k.jones');
            $this->fail('an empty title was accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('"title"', $e->getMessage());
        }

        $this->assertSame([], $this->inserts);
    }

    public function testAMissingSourceIsARefusal(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->cloner(ModuleCloner::class)->clone(999, [], 'k.jones');
    }

    public function testEachClonerSupportsExactlyItsTable(): void
    {
        $this->assertTrue($this->cloner(FormCloner::class)->supports('tl_form'));
        $this->assertFalse($this->cloner(FormCloner::class)->supports('tl_form_field'));
        $this->assertTrue($this->cloner(NewsletterChannelCloner::class)->supports('tl_newsletter_channel'));
        $this->assertTrue($this->cloner(ModuleCloner::class)->supports('tl_module'));
        $this->assertTrue($this->cloner(LayoutCloner::class)->supports('tl_layout'));
    }
}
