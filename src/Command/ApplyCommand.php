<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Webwerkwien\ContaoAiCoreBundle\Service\Apply\ApplyPlan;
use Webwerkwien\ContaoAiCoreBundle\Service\Apply\ApplyPlanException;
use Webwerkwien\ContaoAiCoreBundle\Service\Apply\ApplyReferences;
use Webwerkwien\ContaoAiCoreBundle\Service\Apply\TransactionalTables;
use Webwerkwien\ContaoAiCoreBundle\Service\Cache\CacheTagInvalidator;

/**
 * Page, article and content writes from a JSON plan on stdin, all or nothing (v1.13.0).
 *
 * Each step is one of this bundle's own commands, run in-process like
 * `contao:ai:run` runs its target — so every step gets the checks, callbacks,
 * versions and log entries it gets on its own, and nothing here writes a row.
 * What this command adds is the transaction around them and the IDs one step
 * hands to the next (`@name`, see ApplyReferences).
 *
 * ## Why the cache is invalidated a second time
 *
 * Every step invalidates its tags while the transaction is still open. A page
 * request in that moment caches the old state again, and after the commit nobody
 * would invalidate it: on a site with a 12-hour cache the page stays half a day
 * behind. So the collected tags are invalidated once more after the commit.
 *
 * ## Nested transactions
 *
 * Create commands open a transaction of their own. Under this one they nest. A
 * step that fails makes this command roll back anyway; a step that swallows an
 * inner rollback leaves the connection marked rollback-only, the commit throws,
 * and the run rolls back — the safe direction either way.
 */
#[AsCommand(name: 'contao:ai:apply', description: 'Run page, article and content writes from a JSON plan on stdin, all or nothing')]
class ApplyCommand extends AbstractReadCommand
{
    use OperatorOptionTrait;

    /** Every table a step writes to, including the audit trail; all must roll back. */
    public const TABLES = ['tl_page', 'tl_article', 'tl_content', 'tl_version', 'tl_log', 'tl_undo'];

    /** @var resource|null */
    private $stdin = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly ?CacheTagInvalidator $cacheTags = null,
    ) {
        parent::__construct();
    }

    /**
     * @param resource $stream for tests; the command reads STDIN otherwise
     */
    public function setStdin($stream): void
    {
        $this->stdin = $stream;
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Run every step, then roll back. IDs in the answer are provisional.');
        $this->addOperatorOption();
    }

    protected function doExecute(): int
    {
        $stream = $this->stdin ?? (\defined('STDIN') ? STDIN : null);
        $json   = null === $stream ? '' : (string) stream_get_contents($stream);

        try {
            $plan = ApplyPlan::fromJson($json);
        } catch (ApplyPlanException $e) {
            return $this->answer(['status' => 'error', 'code' => 1, 'message' => 'The plan was refused before anything was written.', 'problems' => $e->problems], Command::FAILURE);
        }

        $application = $this->getApplication() ?? throw new \LogicException('No console application available');

        // The language commands exist only with terminal42/contao-changelanguage
        // (services_changelanguage.yaml). Found here, not at step 30 after a rollback.
        $missing = [];
        foreach ($plan->steps as $step) {
            $command = ApplyPlan::STEPS[$step['do']]['command'];
            if (!$application->has($command)) {
                $missing[$command] = true;
            }
        }
        if ([] !== $missing) {
            return $this->outputError(\sprintf(
                'Not available on this installation: %s%s. Nothing was written.',
                implode(', ', array_keys($missing)),
                isset($missing['contao:language:link']) ? ' (language link needs terminal42/contao-changelanguage)' : '',
            ));
        }

        $refused = TransactionalTables::nonTransactional($this->connection, self::TABLES);
        if ([] !== $refused) {
            return $this->outputError(\sprintf(
                'Not all-or-nothing on this database: %s cannot roll back. Contao recommends InnoDB; '
                . 'the single commands (page create, content create, ...) still work here. Nothing was written.',
                implode(', ', $refused),
            ));
        }

        $dryRun   = (bool) $this->input->getOption('dry-run');
        $operator = (string) $this->input->getOption('operator');
        $ids      = [];
        $done     = [];
        $tags     = [];

        $this->connection->beginTransaction();

        try {
            foreach ($plan->steps as $index => $step) {
                $entry = ['index' => $index, 'do' => $step['do']] + (isset($step['ref']) ? ['ref' => $step['ref']] : []);

                [$code, $answer] = $this->runStep($application, $step, $ids, $operator);

                if (Command::SUCCESS !== $code || 'ok' !== ($answer['status'] ?? null)) {
                    $this->connection->rollBack();

                    return $this->answer([
                        'status'     => 'error',
                        'code'       => 1,
                        'dryRun'     => $dryRun,
                        'rolledBack' => true,
                        'message'    => \sprintf('Step %d (%s) failed, nothing was written: %s', $index, $step['do'], (string) ($answer['message'] ?? 'no answer')),
                        'failedStep' => $entry + array_intersect_key($answer, array_flip(['message', 'messages', 'exception'])),
                        'steps'      => $done,
                    ], Command::FAILURE);
                }

                $id = $this->idOf($step, $answer, $ids);

                if (isset($step['ref'])) {
                    $ids[$step['ref']] = $id;

                    $article = 'page create' === $step['do'] ? self::firstArticle($answer) : null;
                    if (null !== $article) {
                        $ids[$step['ref'] . '.article'] = $article;
                    }
                }

                $tags   = [...$tags, ...array_values((array) ($answer['cacheTags'] ?? []))];
                $done[] = $entry + ['status' => 'ok', 'id' => $id, 'result' => $answer];
            }

            $dryRun ? $this->connection->rollBack() : $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e; // JsonErrorBoundary answers it; nothing was written
        }

        $tags = array_values(array_unique(array_map('strval', $tags)));

        if (!$dryRun) {
            $this->cacheTags?->invalidate($tags);
        }

        return $this->answer([
            'status' => 'ok',
            'dryRun' => $dryRun,
            'count'  => \count($done),
            'refs'   => $ids,
            'steps'  => $done,
        ] + ($dryRun ? [] : ['cacheTags' => $tags]), Command::SUCCESS);
    }

    /**
     * One step through the console, as the command would run on its own.
     *
     * @param array<string, mixed> $step
     * @param array<string, int>   $ids
     *
     * @return array{int, array<string, mixed>}
     */
    private function runStep(Application $application, array $step, array $ids, string $operator): array
    {
        try {
            $input = $this->inputFor($step, $ids);
        } catch (\InvalidArgumentException $e) {
            // `@x.article` of a page create that answered without an article: the
            // plan check cannot know that in advance. Only here, not around doRun(),
            // where the same class out of a command's own code is a defect.
            return [Command::FAILURE, ['status' => 'error', 'message' => $e->getMessage()]];
        }

        $command = ApplyPlan::STEPS[$step['do']]['command'];

        if ('' !== $operator && $this->acceptsOperator($application->find($command))) {
            $input['--operator'] = $operator;
        }

        $input['--no-interaction'] = true;
        $output                    = new BufferedOutput();

        try {
            $code = $application->doRun(new ArrayInput($input), $output);
        } catch (ConsoleRuntimeException $e) {
            // A value that does not bind is the plan's mistake, answered like a refusal.
            return [Command::FAILURE, ['status' => 'error', 'message' => $e->getMessage()]];
        }

        return [$code, self::decode($output->fetch())];
    }

    /**
     * The command line of a step, with every `@name` replaced by its ID.
     *
     * @param array<string, mixed> $step
     * @param array<string, int>   $ids
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException for a name without an ID
     */
    private function inputFor(array $step, array $ids): array
    {
        $spec  = ApplyPlan::STEPS[$step['do']];
        $input = ['command' => $spec['command']];

        foreach ($spec['options'] as $option) {
            if (\array_key_exists($option, $step)) {
                $input['--' . $option] = (string) ApplyReferences::resolve($step[$option], $ids);
            }
        }

        if ('argument' === $spec['id']) {
            $input['id'] = (string) ApplyReferences::resolve($step['id'], $ids);
        } elseif ('option' === $spec['id']) {
            $input['--id'] = (string) ApplyReferences::resolve($step['id'], $ids);
        }

        foreach ((array) ($step['set'] ?? []) as $field => $value) {
            $value            = ApplyReferences::resolve($value, $ids);
            $input['--set'][] = $field . '=' . (\is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : (string) $value);
        }

        return $input;
    }

    private function acceptsOperator(Command $target): bool
    {
        $real = $target instanceof LazyCommand ? $target->getCommand() : $target;
        $real->mergeApplicationDefinition();
        $definition = $real->getDefinition();

        return $definition->hasOption('operator') && $definition->getOption('operator')->acceptValue();
    }

    /**
     * @param array<string, mixed> $step
     * @param array<string, mixed> $answer
     * @param array<string, int>   $ids
     */
    private function idOf(array $step, array $answer, array $ids): int
    {
        if (null === ApplyPlan::STEPS[$step['do']]['id']) {
            return (int) ($answer['id'] ?? 0);
        }

        return (int) ApplyReferences::resolve($step['id'], $ids);
    }

    /**
     * `articles[0]` of a page create: an object with `id`, or the ID itself.
     *
     * @param array<string, mixed> $answer
     */
    private static function firstArticle(array $answer): ?int
    {
        $first = $answer['articles'][0] ?? null;

        if (\is_array($first) && isset($first['id'])) {
            return (int) $first['id'];
        }

        return is_numeric($first) ? (int) $first : null;
    }

    /**
     * The command's JSON answer; the last line if something was printed before it.
     *
     * @return array<string, mixed>
     */
    private static function decode(string $text): array
    {
        $text   = trim($text);
        $answer = json_decode($text, true);

        if (!\is_array($answer) && str_contains($text, "\n")) {
            $answer = json_decode(trim(substr($text, (int) strrpos($text, "\n"))), true);
        }

        return \is_array($answer) ? $answer : ['status' => 'error', 'message' => 'No JSON answer: ' . mb_substr($text, 0, 300)];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function answer(array $data, int $code): int
    {
        $this->output->writeln(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

        return $code;
    }
}
