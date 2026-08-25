<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Webwerkwien\ContaoAiCoreBundle\Command\OperatorOptionTrait;
use Webwerkwien\ContaoAiCoreBundle\Command\RecordCloneCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\TemplateWriteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\VersionCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\VersionRestoreCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

class OperatorProbeCommand extends Command
{
    use OperatorOptionTrait;

    protected function configure(): void
    {
        $this->setName('test:operator');
        $this->addOperatorOption();
    }

    public function resolve(array $input): string
    {
        $in = new ArrayInput($input, $this->getDefinition());

        return $this->resolveOperatorName($in);
    }
}

/**
 * contao-ai-backend-bundle only passes --operator to commands whose definition
 * declares it (AbstractCoreCommandTool::runCommand checks hasOption). A command
 * without the option therefore loses the attribution silently: no error, just
 * the shell user in tl_log.username where the back-end user belonged.
 */
class OperatorOptionTest extends TestCase
{
    public static function commands(): array
    {
        return [
            'version:create'  => [static fn () => new VersionCreateCommand(
                self::mockOf(ContaoFramework::class), self::mockOf(VersionManager::class)
            )],
            'version:restore' => [static fn () => new VersionRestoreCommand(
                self::mockOf(ContaoFramework::class),
                self::mockOf(VersionManager::class),
                self::mockOf(Connection::class)
            )],
            'template:write'  => [static fn () => new TemplateWriteCommand('/tmp')],
            'record:clone'    => [static fn () => new RecordCloneCommand()],
        ];
    }

    private static function mockOf(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    /**
     * @dataProvider commands
     */
    public function testStandaloneCommandsDeclareTheOperatorOption(callable $factory): void
    {
        $cmd = $factory();

        $this->assertTrue(
            $cmd->getDefinition()->hasOption('operator'),
            $cmd->getName() . ' lost --operator; the backend bundle would stop attributing it.'
        );
    }

    public function testAnExplicitOperatorWins(): void
    {
        $cmd = new OperatorProbeCommand();

        $this->assertSame('webwerkwien', $cmd->resolve(['--operator' => 'webwerkwien']));
    }

    public function testFallsBackToTheShellUser(): void
    {
        $cmd = new OperatorProbeCommand();
        $previous = $_SERVER['USER'] ?? null;
        $_SERVER['USER'] = 'deploy';

        try {
            $this->assertSame('deploy', $cmd->resolve([]));
        } finally {
            if (null === $previous) {
                unset($_SERVER['USER']);
            } else {
                $_SERVER['USER'] = $previous;
            }
        }
    }

    public function testNeverReturnsAnEmptyName(): void
    {
        $cmd = new OperatorProbeCommand();
        $user = $_SERVER['USER'] ?? null;
        $username = $_SERVER['USERNAME'] ?? null;
        unset($_SERVER['USER'], $_SERVER['USERNAME']);

        try {
            $this->assertSame('cli-agent', $cmd->resolve([]));
        } finally {
            if (null !== $user) {
                $_SERVER['USER'] = $user;
            }
            if (null !== $username) {
                $_SERVER['USERNAME'] = $username;
            }
        }
    }
}
