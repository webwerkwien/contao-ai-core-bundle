# File Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend contao-cli-bridge with three console commands that handle folder creation, image validation/resize, and file metadata updates — enabling agents to manage the Contao file system fully via CLI.

**Architecture:** The agent transfers files to the server via SCP (outside bridge scope), then delegates all Contao-specific processing to bridge commands. Each command uses `FilesModel` / `Folder` / `File` from the Contao framework and outputs JSON like all other bridge commands. DBAFS sync is triggered by the agent with the existing `contao:filesync` command.

**Tech Stack:** PHP 8.1+, Contao 5, Symfony Console, Contao Framework (`ContaoFramework`), `FilesModel`, `Folder`, `File`, GD extension (bundled with PHP)

---

## File Map

| Action | File |
|---|---|
| Create | `src/Command/FolderCreateCommand.php` |
| Create | `src/Command/FileProcessCommand.php` |
| Create | `src/Command/FileMetaUpdateCommand.php` |
| Modify | `tests/Command/AbstractWriteCommandTest.php` (reference only, no changes needed) |
| Create | `tests/Command/FolderCreateCommandTest.php` |
| Create | `tests/Command/FileProcessCommandTest.php` |
| Create | `tests/Command/FileMetaUpdateCommandTest.php` |

Services are auto-wired via `config/services.yaml` — no changes needed there.

---

## Task 1: `contao:folder:create`

Creates a subfolder inside the Contao `files/` directory and optionally marks it as public.

Contao represents `public` vs. `protected` on folders via the `tl_files.public` column (`1` = public, `0` = protected/private). After creating the physical directory the command creates (or updates) the `tl_files` record for the folder.

**Files:**
- Create: `src/Command/FolderCreateCommand.php`
- Create: `tests/Command/FolderCreateCommandTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Command/FolderCreateCommandTest.php
namespace Webwerkwien\ContaoCliBridgeBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoCliBridgeBundle\Command\FolderCreateCommand;
use Contao\CoreBundle\Framework\ContaoFramework;

class FolderCreateCommandTest extends TestCase
{
    public function testMissingPathReturnsError(): void
    {
        $framework = $this->createMock(ContaoFramework::class);
        $framework->expects($this->never())->method('initialize');

        $tester = new CommandTester(new FolderCreateCommand($framework, sys_get_temp_dir()));
        $tester->execute([]);

        $output = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $output['status']);
        $this->assertStringContainsString('--path', $output['message']);
    }

    public function testPathOutsideFilesRootReturnsError(): void
    {
        $framework = $this->createMock(ContaoFramework::class);
        $framework->expects($this->never())->method('initialize');

        $tester = new CommandTester(new FolderCreateCommand($framework, sys_get_temp_dir()));
        $tester->execute(['--path' => '../escape/attempt']);

        $output = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $output['status']);
        $this->assertStringContainsString('outside', $output['message']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /path/to/contao-cli-bridge
vendor/bin/phpunit tests/Command/FolderCreateCommandTest.php --testdox
```

Expected: FAIL — `FolderCreateCommand` class not found.

- [ ] **Step 3: Implement `FolderCreateCommand`**

```php
<?php
// src/Command/FolderCreateCommand.php
namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FilesModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'contao:folder:create', description: 'Create a folder in the Contao file system')]
class FolderCreateCommand extends AbstractWriteCommand
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('path',   null, InputOption::VALUE_REQUIRED, 'Folder path relative to Contao root, e.g. files/images/gallery');
        $this->addOption('public', null, InputOption::VALUE_NONE,     'Mark folder as publicly accessible');
    }

    protected function doExecute(array $fields): int
    {
        $path = $this->input->getOption('path');
        if (!$path) {
            return $this->outputError('--path is required');
        }

        // Normalize and guard against path traversal
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (str_contains($path, '..') || !str_starts_with($path, 'files/')) {
            return $this->outputError('Path must start with files/ and must not contain ".."  (outside-files-root)');
        }

        $absPath = rtrim($this->projectDir, '/') . '/' . $path;
        if (!is_dir($absPath) && !mkdir($absPath, 0775, true)) {
            return $this->outputError("Could not create directory: {$path}");
        }

        $this->framework->initialize();

        $file = FilesModel::findByPath($path);
        if ($file === null) {
            $file           = new FilesModel();
            $file->pid      = $this->resolveParentUuid(dirname($path));
            $file->tstamp   = time();
            $file->type     = 'folder';
            $file->path     = $path;
            $file->name     = basename($path);
            $file->hash     = '';
        }
        $file->public = $this->input->getOption('public') ? '1' : '0';
        $file->save();

        $this->outputSuccess(['path' => $path, 'public' => (bool) $file->public]);
        return Command::SUCCESS;
    }

    private function resolveParentUuid(string $parentPath): string
    {
        if ($parentPath === '.' || $parentPath === '') {
            return '';
        }
        $parent = FilesModel::findByPath($parentPath);
        return $parent?->uuid ?? '';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
vendor/bin/phpunit tests/Command/FolderCreateCommandTest.php --testdox
```

Expected: PASS (both test cases pass).

- [ ] **Step 5: Commit**

```bash
git add src/Command/FolderCreateCommand.php tests/Command/FolderCreateCommandTest.php
git commit -m "feat: add contao:folder:create command"
```

---

## Task 2: `contao:file:process`

Validates a file that is already on the server (placed there by SCP). Checks the extension against Contao's upload whitelist. If the file is an image exceeding the configured max dimensions, it is resized in-place using GD. Returns JSON with the result.

Contao stores the upload type whitelist in `$GLOBALS['TL_CONFIG']['uploadTypes']` (comma-separated extensions, e.g. `"jpg,jpeg,png,gif,svg,pdf"`). Max image dimensions are in `$GLOBALS['TL_CONFIG']['imageWidth']` and `$GLOBALS['TL_CONFIG']['imageHeight']` (0 = no limit).

**Files:**
- Create: `src/Command/FileProcessCommand.php`
- Create: `tests/Command/FileProcessCommandTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Command/FileProcessCommandTest.php
namespace Webwerkwien\ContaoCliBridgeBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoCliBridgeBundle\Command\FileProcessCommand;
use Contao\CoreBundle\Framework\ContaoFramework;

class FileProcessCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/fp_test_' . uniqid();
        mkdir($this->tmpDir . '/files', 0775, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/files/*'));
        rmdir($this->tmpDir . '/files');
        rmdir($this->tmpDir);
    }

    private function makeCommand(): FileProcessCommand
    {
        $framework = $this->createMock(ContaoFramework::class);
        return new FileProcessCommand($framework, $this->tmpDir);
    }

    public function testMissingPathReturnsError(): void
    {
        $tester = new CommandTester($this->makeCommand());
        $tester->execute([]);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('--path', $out['message']);
    }

    public function testDisallowedExtensionReturnsError(): void
    {
        $file = $this->tmpDir . '/files/virus.exe';
        file_put_contents($file, 'fake');

        $tester = new CommandTester($this->makeCommand());
        $tester->execute([
            '--path'          => 'files/virus.exe',
            '--allowed-types' => 'jpg,png,gif',
        ]);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('not allowed', $out['message']);
    }

    public function testValidJpegPassesWithoutResize(): void
    {
        // Create a tiny valid JPEG (1x1 pixel)
        $img  = imagecreatetruecolor(1, 1);
        $file = $this->tmpDir . '/files/tiny.jpg';
        imagejpeg($img, $file, 90);
        imagedestroy($img);

        $tester = new CommandTester($this->makeCommand());
        $tester->execute([
            '--path'          => 'files/tiny.jpg',
            '--allowed-types' => 'jpg,jpeg,png',
            '--max-width'     => '800',
            '--max-height'    => '600',
        ]);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('ok', $out['status']);
        $this->assertFalse($out['resized']);
    }

    public function testOversizedImageGetsResized(): void
    {
        $img  = imagecreatetruecolor(2000, 1500);
        $file = $this->tmpDir . '/files/big.jpg';
        imagejpeg($img, $file, 85);
        imagedestroy($img);

        $tester = new CommandTester($this->makeCommand());
        $tester->execute([
            '--path'          => 'files/big.jpg',
            '--allowed-types' => 'jpg,jpeg',
            '--max-width'     => '800',
            '--max-height'    => '600',
        ]);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('ok', $out['status']);
        $this->assertTrue($out['resized']);

        [$w, $h] = getimagesize($file);
        $this->assertLessThanOrEqual(800, $w);
        $this->assertLessThanOrEqual(600, $h);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/phpunit tests/Command/FileProcessCommandTest.php --testdox
```

Expected: FAIL — `FileProcessCommand` class not found.

- [ ] **Step 3: Implement `FileProcessCommand`**

```php
<?php
// src/Command/FileProcessCommand.php
namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'contao:file:process', description: 'Validate and optionally resize a file already on the server')]
class FileProcessCommand extends AbstractWriteCommand
{
    // Extensions recognized as images that GD can handle
    private const GD_IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('path',          null, InputOption::VALUE_REQUIRED, 'File path relative to Contao root, e.g. files/images/photo.jpg');
        $this->addOption('allowed-types', null, InputOption::VALUE_OPTIONAL, 'Comma-separated allowed extensions (overrides Contao config)', '');
        $this->addOption('max-width',     null, InputOption::VALUE_OPTIONAL, 'Max image width in pixels (0 = no limit, overrides Contao config)', '0');
        $this->addOption('max-height',    null, InputOption::VALUE_OPTIONAL, 'Max image height in pixels (0 = no limit, overrides Contao config)', '0');
    }

    protected function doExecute(array $fields): int
    {
        $path = $this->input->getOption('path');
        if (!$path) {
            return $this->outputError('--path is required');
        }

        $path    = ltrim(str_replace('\\', '/', $path), '/');
        $absPath = rtrim($this->projectDir, '/') . '/' . $path;

        if (!file_exists($absPath)) {
            return $this->outputError("File not found: {$path}");
        }

        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));

        // Resolve allowed types: CLI option > Contao config > fallback
        $allowedTypesOpt = $this->input->getOption('allowed-types');
        if ($allowedTypesOpt !== '') {
            $allowed = array_map('trim', explode(',', $allowedTypesOpt));
        } else {
            $this->framework->initialize();
            $contaoTypes = $GLOBALS['TL_CONFIG']['uploadTypes'] ?? 'jpg,jpeg,png,gif,pdf,svg';
            $allowed     = array_map('trim', explode(',', $contaoTypes));
        }

        if (!in_array($ext, $allowed, true)) {
            return $this->outputError("Extension '{$ext}' is not allowed. Allowed: " . implode(', ', $allowed));
        }

        // Resize if it's a GD-supported image
        $resized = false;
        if (in_array($ext, self::GD_IMAGE_TYPES, true)) {
            $maxW = (int) $this->input->getOption('max-width');
            $maxH = (int) $this->input->getOption('max-height');

            if ($maxW === 0 && $maxH === 0 && !isset($this->framework)) {
                // no limits
            } elseif ($maxW === 0 && $maxH === 0) {
                // Try Contao config if CLI options were both 0 and framework not yet init'd
                if (!isset($GLOBALS['TL_CONFIG'])) {
                    $this->framework->initialize();
                }
                $maxW = (int) ($GLOBALS['TL_CONFIG']['imageWidth']  ?? 0);
                $maxH = (int) ($GLOBALS['TL_CONFIG']['imageHeight'] ?? 0);
            }

            if ($maxW > 0 || $maxH > 0) {
                $resized = $this->resizeIfNeeded($absPath, $ext, $maxW, $maxH);
                if ($resized === null) {
                    return $this->outputError("Could not read image: {$path}");
                }
            }
        }

        $size = filesize($absPath);
        $this->outputSuccess([
            'path'    => $path,
            'ext'     => $ext,
            'resized' => $resized,
            'bytes'   => $size,
        ]);
        return Command::SUCCESS;
    }

    /**
     * Resize image in-place if it exceeds maxW/maxH (maintaining aspect ratio).
     * Returns true if resized, false if within limits, null on failure.
     */
    private function resizeIfNeeded(string $absPath, string $ext, int $maxW, int $maxH): ?bool
    {
        [$srcW, $srcH] = @getimagesize($absPath);
        if (!$srcW || !$srcH) {
            return null;
        }

        $needsResize = ($maxW > 0 && $srcW > $maxW) || ($maxH > 0 && $srcH > $maxH);
        if (!$needsResize) {
            return false;
        }

        // Calculate new dimensions preserving aspect ratio
        $ratio  = $srcW / $srcH;
        $newW   = $srcW;
        $newH   = $srcH;
        if ($maxW > 0 && $newW > $maxW) {
            $newW = $maxW;
            $newH = (int) round($newW / $ratio);
        }
        if ($maxH > 0 && $newH > $maxH) {
            $newH = $maxH;
            $newW = (int) round($newH * $ratio);
        }

        $src = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($absPath),
            'png'         => @imagecreatefrompng($absPath),
            'gif'         => @imagecreatefromgif($absPath),
            'webp'        => @imagecreatefromwebp($absPath),
            default       => null,
        };
        if (!$src) {
            return null;
        }

        $dst = imagecreatetruecolor($newW, $newH);

        // Preserve transparency for PNG/GIF
        if (in_array($ext, ['png', 'gif'], true)) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

        $saved = match ($ext) {
            'jpg', 'jpeg' => imagejpeg($dst, $absPath, 85),
            'png'         => imagepng($dst, $absPath, 7),
            'gif'         => imagegif($dst, $absPath),
            'webp'        => imagewebp($dst, $absPath, 85),
            default       => false,
        };

        imagedestroy($src);
        imagedestroy($dst);

        return $saved;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
vendor/bin/phpunit tests/Command/FileProcessCommandTest.php --testdox
```

Expected: all 4 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Command/FileProcessCommand.php tests/Command/FileProcessCommandTest.php
git commit -m "feat: add contao:file:process command with format validation and resize"
```

---

## Task 3: `contao:file:meta`

Updates metadata fields on an existing `tl_files` record: `alt`, `title`, `caption`, and any other field passed via `--set`. Looks up the record by path.

**Files:**
- Create: `src/Command/FileMetaUpdateCommand.php`
- Create: `tests/Command/FileMetaUpdateCommandTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Command/FileMetaUpdateCommandTest.php
namespace Webwerkwien\ContaoCliBridgeBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoCliBridgeBundle\Command\FileMetaUpdateCommand;
use Contao\CoreBundle\Framework\ContaoFramework;

class FileMetaUpdateCommandTest extends TestCase
{
    public function testMissingPathReturnsError(): void
    {
        $framework = $this->createMock(ContaoFramework::class);
        $tester    = new CommandTester(new FileMetaUpdateCommand($framework));
        $tester->execute([]);

        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('--path', $out['message']);
    }

    public function testNoFieldsReturnsError(): void
    {
        $framework = $this->createMock(ContaoFramework::class);
        $tester    = new CommandTester(new FileMetaUpdateCommand($framework));
        $tester->execute(['--path' => 'files/photo.jpg']);

        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('--set', $out['message']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/phpunit tests/Command/FileMetaUpdateCommandTest.php --testdox
```

Expected: FAIL — `FileMetaUpdateCommand` class not found.

- [ ] **Step 3: Implement `FileMetaUpdateCommand`**

```php
<?php
// src/Command/FileMetaUpdateCommand.php
namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FilesModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'contao:file:meta', description: 'Update metadata (alt, title, caption, …) on a tl_files record')]
class FileMetaUpdateCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'File or folder path relative to Contao root, e.g. files/images/photo.jpg');
        // --set is inherited from AbstractWriteCommand
    }

    protected function doExecute(array $fields): int
    {
        $path = $this->input->getOption('path');
        if (!$path) {
            return $this->outputError('--path is required');
        }

        if (empty($fields)) {
            return $this->outputError('At least one --set FIELD=VALUE is required');
        }

        $this->framework->initialize();

        $file = FilesModel::findByPath(ltrim($path, '/'));
        if ($file === null) {
            return $this->outputError("No tl_files record found for path: {$path}. Run contao:filesync first.");
        }

        // Allowed metadata fields — guards against overwriting structural columns
        $allowedFields = ['alt', 'title', 'caption', 'name', 'importantPartX', 'importantPartY', 'importantPartWidth', 'importantPartHeight'];
        $updated = [];

        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowedFields, true)) {
                return $this->outputError("Field '{$key}' is not an editable metadata field. Allowed: " . implode(', ', $allowedFields));
            }
            $file->$key = $value;
            $updated[]  = $key;
        }

        $file->tstamp = time();
        $file->save();

        $this->outputSuccess(['path' => $path, 'updated' => $updated]);
        return Command::SUCCESS;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
vendor/bin/phpunit tests/Command/FileMetaUpdateCommandTest.php --testdox
```

Expected: both tests PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Command/FileMetaUpdateCommand.php tests/Command/FileMetaUpdateCommandTest.php
git commit -m "feat: add contao:file:meta command for metadata updates"
```

---

## Task 4: Agent-side wiring (contao-cli-agent)

Adds three Python functions in `file.py` and three CLI commands in `contao_cli.py` that call the new bridge commands.

**Files:**
- Modify: `contao/agent-harness/cli_anything/contao/core/file.py`
- Modify: `contao/agent-harness/cli_anything/contao/contao_cli.py`

- [ ] **Step 1: Add functions to `file.py`**

Append to the end of `contao/agent-harness/cli_anything/contao/core/file.py`:

```python
def folder_create(backend: ContaoBackend, path: str, public: bool = False) -> dict:
    """Create a folder in the Contao file system via contao-cli-bridge."""
    cmd = f'contao:folder:create --path "{path}"'
    if public:
        cmd += ' --public'
    result = backend.run(cmd)
    import json
    try:
        return json.loads(result["stdout"])
    except Exception:
        return {"raw": result["stdout"]}


def file_process(
    backend: ContaoBackend,
    path: str,
    allowed_types: str = "",
    max_width: int = 0,
    max_height: int = 0,
) -> dict:
    """Validate and optionally resize a file already on the server."""
    import json
    cmd = f'contao:file:process --path "{path}"'
    if allowed_types:
        cmd += f' --allowed-types "{allowed_types}"'
    if max_width:
        cmd += f' --max-width {max_width}'
    if max_height:
        cmd += f' --max-height {max_height}'
    result = backend.run(cmd)
    try:
        return json.loads(result["stdout"])
    except Exception:
        return {"raw": result["stdout"]}


def file_meta_update(backend: ContaoBackend, path: str, meta: dict) -> dict:
    """Update tl_files metadata fields for a file or folder."""
    import json
    set_args = " ".join(f'--set "{k}={v}"' for k, v in meta.items())
    cmd = f'contao:file:meta --path "{path}" {set_args}'
    result = backend.run(cmd)
    try:
        return json.loads(result["stdout"])
    except Exception:
        return {"raw": result["stdout"]}
```

- [ ] **Step 2: Add CLI commands to `contao_cli.py`**

Add the following three commands inside the existing `file` group (after the `file sync` command, around line 885):

```python
@file.command("folder-create")
@click.option("--path", required=True, help="Folder path relative to Contao root, e.g. files/images/gallery")
@click.option("--public", "is_public", is_flag=True, help="Mark folder as publicly accessible")
@click.option("--json", "as_json", is_flag=True)
@click.pass_context
def file_folder_create_cmd(ctx, path, is_public, as_json):
    """Create a folder in the Contao file system via contao-cli-bridge."""
    _require_bridge(ctx, "file folder-create")
    b = _get_backend(ctx.obj.get("session"))
    _output(file_mod.folder_create(b, path, is_public), as_json or ctx.obj.get("as_json"))


@file.command("process")
@click.option("--path", required=True, help="File path relative to Contao root, e.g. files/images/photo.jpg")
@click.option("--allowed-types", default="", help="Comma-separated allowed extensions (overrides Contao config)")
@click.option("--max-width",  type=int, default=0, help="Max image width in pixels (0 = use Contao config)")
@click.option("--max-height", type=int, default=0, help="Max image height in pixels (0 = use Contao config)")
@click.option("--json", "as_json", is_flag=True)
@click.pass_context
def file_process_cmd(ctx, path, allowed_types, max_width, max_height, as_json):
    """Validate and optionally resize a file already on the server via contao-cli-bridge."""
    _require_bridge(ctx, "file process")
    b = _get_backend(ctx.obj.get("session"))
    _output(file_mod.file_process(b, path, allowed_types, max_width, max_height),
            as_json or ctx.obj.get("as_json"))


@file.command("meta")
@click.option("--path", required=True, help="File or folder path relative to Contao root")
@click.option("--set", "fields", multiple=True, metavar="FIELD=VALUE",
              help="Metadata field to update, e.g. --set alt=Landschaft --set title=Bergblick")
@click.option("--json", "as_json", is_flag=True)
@click.pass_context
def file_meta_cmd(ctx, path, fields, as_json):
    """Update metadata fields on a tl_files record via contao-cli-bridge."""
    _require_bridge(ctx, "file meta")
    parsed = dict(f.split("=", 1) for f in fields if "=" in f)
    b = _get_backend(ctx.obj.get("session"))
    _output(file_mod.file_meta_update(b, path, parsed), as_json or ctx.obj.get("as_json"))
```

- [ ] **Step 3: Smoke-test the CLI wiring**

```bash
cd contao/agent-harness
python -m cli_anything.contao file --help
```

Expected output includes: `folder-create`, `process`, `meta`, `list`, `sync`.

- [ ] **Step 4: Commit**

```bash
git add cli_anything/contao/core/file.py cli_anything/contao/contao_cli.py
git commit -m "feat: wire folder-create, file-process, file-meta to contao-cli-bridge"
```

---

## Typical Agent Workflow After This Feature

```
# 1. Create target folder (bridge)
contao file folder-create --path files/images/projekte --public

# 2. Transfer file via SCP (agent SSH layer — outside bridge scope)
#    scp /local/bild.jpg user@server:/var/www/contao/files/images/projekte/bild.jpg

# 3. Validate + resize (bridge)
contao file process --path files/images/projekte/bild.jpg --max-width 1920 --max-height 1200

# 4. Register in DBAFS (existing command)
contao contao filesync

# 5. Set metadata (bridge)
contao file meta --path files/images/projekte/bild.jpg --set alt="Projektfoto" --set title="Büroprojekt Wien"
```

---

## Self-Review

| Requirement | Covered by |
|---|---|
| Upload images (bridge-side validation + processing) | Task 2 `contao:file:process` |
| Resize if larger than allowed | Task 2 resize logic, `resizeIfNeeded()` |
| Respect allowed file formats | Task 2 extension whitelist check |
| Set file metadata (alt, title, caption) | Task 3 `contao:file:meta` |
| Create folders in files/ | Task 1 `contao:folder:create` |
| Mark files/folders as public or not | Task 1 `--public` flag; Task 3 can also set via `--set public=1` |
| Agent CLI wiring | Task 4 |
| DBAFS sync after upload | Uses existing `contao:filesync` (already in agent) |
