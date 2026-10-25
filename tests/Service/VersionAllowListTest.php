<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * Creating and restoring a version ask the same question about a table.
 *
 * 🟡 **Measured before the fix:** `version restore --table tl_image_size` answered
 * *"Table not allowed"*, although `image-size create` and `update` write versions for
 * that table. The original allow-list for restores never grew with what
 * the bundle versioned; this test used to keep the two in step.
 *
 * Since v1.4.0 there is no list: a table qualifies when its DCA keeps versions
 * (`VersionManager::refusal()`), which is also when Contao's `Versions::create()` writes
 * one. Both commands have to ask that one method — a second, private answer would be
 * the drift of September again.
 */
class VersionAllowListTest extends TestCase
{
    public function testCreateAndRestoreAskTheSameQuestion(): void
    {
        $src = __DIR__ . '/../../src/';

        foreach (['VersionCreateCommand', 'VersionRestoreCommand'] as $command) {
            $this->assertStringContainsString('->refusal($table)', (string) file_get_contents($src . 'Command/' . $command . '.php'), $command);
        }

        $this->assertStringNotContainsString('ALLOWED_TABLES', (string) file_get_contents($src . 'Service/VersionManager.php'));
    }
}
