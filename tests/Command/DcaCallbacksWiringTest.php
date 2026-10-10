<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\CreatesRecord;

/**
 * Every path that creates a record reaches the record callbacks (v1.4.0).
 *
 * The update and delete paths have one address each (ModelWriter, covered by its own
 * tests); creates and clones are spread over two dozen commands and four cloners, so
 * these checks read the sources: a create command that does not mark its first version
 * as created, or a cloner that versions a copy without calling oncopy, would be the
 * gap of September again — nothing fails, a rule is just skipped.
 */
class DcaCallbacksWiringTest extends TestCase
{
    private const SRC = __DIR__ . '/../../src/';

    public function testEveryCreateRunsInATransaction(): void
    {
        foreach (glob(self::SRC . 'Command/*.php') as $file) {
            if (!preg_match('/createVersion\([^;]*created:\s*true/', (string) file_get_contents($file))) {
                continue;
            }

            $class = 'Webwerkwien\\ContaoAiCoreBundle\\Command\\' . basename($file, '.php');

            $this->assertTrue(is_subclass_of($class, CreatesRecord::class), $class . ' creates a record and must implement CreatesRecord');
        }
    }

    public function testACreateRunsOncreateAndOnsubmitBeforeItsFirstVersion(): void
    {
        $source = (string) file_get_contents(self::SRC . 'Command/AbstractWriteCommand.php');
        $start  = strpos($source, 'protected function createVersion(');
        $body   = substr($source, (int) $start, 1200);

        $create  = strpos($body, '->create($table, $id)');
        $submit  = strpos($body, '->submit($table, $id)');
        $version = strpos($body, 'createInitialVersion(');

        $this->assertNotFalse($create);
        $this->assertNotFalse($submit);
        $this->assertNotFalse($version);
        $this->assertTrue($create < $submit && $submit < $version, 'oncreate, then onsubmit, then the first version');
    }

    public function testTheFieldsOfEveryCreateAndUpdatePassTheirSaveCallbacksLast(): void
    {
        $source = (string) file_get_contents(self::SRC . 'Command/AbstractWriteCommand.php');
        $start  = (int) strpos($source, 'protected function convertFields(');
        $end    = (int) strpos($source, "\n    }\n", $start);
        $body   = substr($source, $start, $end - $start);

        $empty = strpos($body, 'convertEmptyValues(');
        $save  = strpos($body, '->save($table');

        $this->assertNotFalse($save, 'convertFields() must run the save_callbacks');
        $this->assertTrue($empty < $save, 'on the values as they will be stored, after every conversion');
    }

    public function testEveryClonerCallsOncopyForEveryTableItCopies(): void
    {
        foreach (glob(self::SRC . 'Service/Cloner/*.php') as $file) {
            $source = (string) file_get_contents($file);

            preg_match_all("/createInitialVersion\\('(tl_[a-z_]+)'/", $source, $versioned);
            preg_match_all("/->copy\\('(tl_[a-z_]+)'/", $source, $copied);

            $missing = array_diff(array_unique($versioned[1]), $copied[1]);

            $this->assertSame([], array_values($missing), basename($file) . ' copies these tables without calling oncopy');
        }
    }
}
