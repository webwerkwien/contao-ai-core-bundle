<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * Every table this bundle writes versions for can have them restored.
 *
 * 🟡 **Measured on 2026-09-16 on c5:** `version restore --table tl_image_size` answered
 * *"Table not allowed"*, although `image-size create` and `update` write versions for
 * that table. The allow-list dates from the security audit of 2026-04-23 — ten tables,
 * the ones versioned then. It never grew: the bundle versions 22 tables now, and for 12
 * of them (modules, forms, themes, image sizes, archives, …) a version was written that
 * could never be restored.
 *
 * The list stays explicit — it guards a table name that goes into SQL. This test keeps
 * it in step with what the code versions.
 */
class VersionAllowListTest extends TestCase
{
    public function testEveryVersionedTableIsRestorable(): void
    {
        $sources = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../src')) as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $sources .= file_get_contents($file->getPathname());
            }
        }

        preg_match_all("/(?:createVersion|createInitialVersion)\\('(tl_[a-z_]+)'/", $sources, $matches);
        $versioned = array_values(array_unique($matches[1]));
        sort($versioned);

        $this->assertNotEmpty($versioned);

        $manager    = new VersionManager($this->createMock(Connection::class));
        $notAllowed = array_values(array_filter($versioned, static fn (string $t): bool => !$manager->isAllowedTable($t)));

        $this->assertSame([], $notAllowed, 'versions are written for these tables but cannot be restored');
    }
}
