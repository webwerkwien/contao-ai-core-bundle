<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Monolog\ContaoContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Webwerkwien\ContaoAiCoreBundle\Service\SystemLog;

/**
 * Write a Twig template to the correct path under templates/.
 *
 * Path is calculated from --mode, --base, and optional --name so that agents
 * can never place templates in the wrong location:
 *
 *  override: templates/<base>.html.twig
 *  partial:  templates/<dir>/_<basename>.html.twig  (underscore auto-prepended)
 *  variant:  templates/<base>/<name>.html.twig
 */
/**
 * 🔴 H-4 (Audit 2026-09-02): Dieser Befehl überschreibt Dateiinhalte, ohne die
 * vorherigen Bytes zu sichern — und deklarierte das nirgends.
 *
 * ⚠️ Nachgesehen, wie Contao es macht: `DC_Folder::source()` versioniert um den
 * Schreibvorgang herum (`Versions::initialize()` … `create()`), aber
 * `Versions::create()` ist ein `SELECT * FROM <table> WHERE id=?` — es sichert
 * die **Datenbankzeile**, nicht den Dateiinhalt. Contaos eigener Datei-Editor
 * überschreibt also genauso unwiederbringlich; der gespeicherte `hash` lässt
 * eine Änderung erkennen, nicht zurücknehmen.
 *
 * 🎯 Deshalb kein eigenes Backup-Regime, das von Contao abwiche und Fragen nach
 * Ablageort und Aufbewahrung aufwürfe — sondern die Deklaration. `irreversible`
 * existiert in `AiContract` genau dafür, und ein aufrufender Agent liest sie,
 * bevor er zugreift. **Eine unumkehrbare Wirkung, die niemand ankündigt, ist
 * der eigentliche Mangel.**
 */
#[AiContract(
    writes: true,
    tables: [],
    trace: ['tl_log'],
    traceWhen: 'on-success',
    irreversible: 'overwrites the template file on disk — the previous content is not kept',
    repeatable: true,
    answerShape: ['status', 'template'],
)]
#[AsCommand(name: 'contao:template:write', description: 'Write a Twig template to the correct path under templates/')]
class TemplateWriteCommand extends Command
{
    use JsonErrorBoundary;

    use OperatorOptionTrait;

    private LoggerInterface $logger;
    private ?SystemLog $systemLog = null;

    public function __construct(private readonly string $projectDir)
    {
        parent::__construct();
    }

    #[Required]
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    #[Required]
    public function setSystemLog(SystemLog $systemLog): void
    {
        $this->systemLog = $systemLog;
    }

    protected function configure(): void
    {
        $this
            ->addOption('mode',   null, InputOption::VALUE_REQUIRED, 'override, partial, or variant')
            ->addOption('base',   null, InputOption::VALUE_REQUIRED, 'Base template path without extension, e.g. content_element/text')
            ->addOption('name',   null, InputOption::VALUE_OPTIONAL, 'Variant name (required for mode=variant)', '')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Absolute path of temp file on the server to read content from');

        $this->addOperatorOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->guarded($output, fn (): int => $this->doExecute($input, $output));
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $mode   = $input->getOption('mode');
        $base   = $input->getOption('base');
        $name   = $input->getOption('name') ?: '';
        $source = $input->getOption('source');

        if (!$mode || !$base || !$source) {
            $output->writeln(json_encode(['status' => 'error', 'message' => '--mode, --base and --source are required']));
            return self::FAILURE;
        }

        if (!in_array($mode, ['override', 'partial', 'variant'], true)) {
            $output->writeln(json_encode(['status' => 'error', 'message' => "--mode must be one of: override, partial, variant"]));
            return self::FAILURE;
        }

        if ($mode === 'variant' && $name === '') {
            $output->writeln(json_encode(['status' => 'error', 'message' => '--name is required for mode=variant']));
            return self::FAILURE;
        }

        // Guard against path traversal and absolute paths in --base and --name
        if (
            str_contains($base, '..')
            || str_contains($name, '..')
            || ($base !== '' && $base[0] === '/')
            || $name !== ltrim($name, '/')
        ) {
            $output->writeln(json_encode(['status' => 'error', 'message' => 'Invalid --base or --name value']));
            return self::FAILURE;
        }

        $relPath = $this->buildRelPath($mode, $base, $name);
        $absPath = rtrim($this->projectDir, '/') . '/templates/' . $relPath;

        // Realpath jail: walk up to deepest existing ancestor to catch symlinks
        $jailRoot  = realpath($this->projectDir) . DIRECTORY_SEPARATOR;
        $jailCheck = $absPath;
        while (!file_exists($jailCheck) && $jailCheck !== dirname($jailCheck)) {
            $jailCheck = dirname($jailCheck);
        }
        $realAncestor = realpath($jailCheck);
        if ($realAncestor === false || !str_starts_with($realAncestor . DIRECTORY_SEPARATOR, $jailRoot)) {
            $output->writeln(json_encode(['status' => 'error', 'message' => 'Access denied: path resolves outside allowed directory']));
            return self::FAILURE;
        }

        if (!is_file($source)) {
            $output->writeln(json_encode(['status' => 'error', 'message' => 'Source file not found']));
            return self::FAILURE;
        }

        $content = file_get_contents($source);
        if ($content === false) {
            $output->writeln(json_encode(['status' => 'error', 'message' => 'Cannot read source file']));
            return self::FAILURE;
        }

        $dir = dirname($absPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        if (file_put_contents($absPath, $content) === false) {
            $output->writeln(json_encode(['status' => 'error', 'message' => 'Cannot write template']));
            return self::FAILURE;
        }

        $payload = ['path' => 'templates/' . $relPath, 'mode' => $mode, 'bytes' => strlen($content)];
        $user    = $this->resolveOperatorName($input);

        $this->logger->info('contao-ai-core-bundle audit', [
            'command' => $this->getName(),
            'user'    => $user,
            'payload' => $payload,
        ]);

        $this->systemLog?->write(
            sprintf('%s %s', $this->getName(), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            (string) $this->getName(),
            (string) $user,
            ContaoContext::FILES,
        );

        $output->writeln(json_encode([
            'status' => 'ok',
            'path'   => 'templates/' . $relPath,
            'mode'   => $mode,
            'bytes'  => strlen($content),
        ], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function buildRelPath(string $mode, string $base, string $name): string
    {
        return match ($mode) {
            'override' => $base . '.html.twig',
            'partial'  => dirname($base) . '/_' . basename($base) . '.html.twig',
            'variant'  => $base . '/' . $name . '.html.twig',
            // Unerreichbar: doExecute() prueft $mode oben mit in_array().
            // Der Zweig haelt die Annahme fest, statt sie einem
            // UnhandledMatchError zu ueberlassen, falls die Pruefung je faellt.
            default    => throw new \LogicException(\sprintf('Unvalidated mode "%s" reached buildRelPath().', $mode)),
        };
    }
}
