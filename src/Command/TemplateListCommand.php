<?php

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

#[AsCommand(name: 'contao:template:list', description: 'List custom templates under templates/')]
class TemplateListCommand extends Command
{
    public function __construct(private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('prefix', null, InputOption::VALUE_OPTIONAL, 'Filter by path prefix, e.g. content_element/', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tplDir = rtrim($this->projectDir, '/') . '/templates';

        if (!is_dir($tplDir)) {
            $output->writeln(json_encode(['status' => 'ok', 'templates' => []]));
            return self::SUCCESS;
        }

        $prefix = $input->getOption('prefix') ?: '';

        $finder = new Finder();
        $finder->files()->in($tplDir)->name('*.html.twig')->sortByName();

        $templates = [];
        foreach ($finder as $file) {
            $relPath = 'templates/' . $file->getRelativePathname();
            if ($prefix && !str_starts_with($file->getRelativePathname(), ltrim($prefix, '/'))) {
                continue;
            }
            $templates[] = [
                'path'  => $relPath,
                'size'  => $file->getSize(),
                'mtime' => $file->getMTime(),
            ];
        }

        $output->writeln(json_encode([
            'status'    => 'ok',
            'templates' => $templates,
        ], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
