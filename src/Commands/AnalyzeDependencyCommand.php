<?php
declare(strict_types = 1);

namespace DependencyAnalyzer\Commands;

use DependencyAnalyzer\DependencyDumper;
use DependencyAnalyzer\DependencyGraph;
use DependencyAnalyzer\Exceptions\InvalidCommandArgumentException;
use DependencyAnalyzer\Exceptions\ShouldNotHappenException;
use DependencyAnalyzer\Exceptions\UnexpectedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @da-internal \DependencyAnalyzer\Commands\
 */
abstract class AnalyzeDependencyCommand extends Command
{
    const DEFAULT_CONFIG_FILES = [__DIR__ . '/../../conf/config.neon'];

    protected abstract function inspectDependencyGraph(DependencyGraph $graph, OutputInterface $output): int;
    protected abstract function getCommandName(): string;
    protected abstract function getCommandDescription(): string;

    protected function configure(): void
    {
        $this->setName($this->getCommandName())
            ->setDescription($this->getCommandDescription())
            ->setDefinition([
                new InputArgument('paths', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Target directory of analyze'),
                new InputOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'Memory limit for the run (ex: 500k, 500M, 5G)'),
                new InputOption('exclude', null, InputOption::VALUE_REQUIRED | InputArgument::IS_ARRAY, 'Exclude directory of analyze'),
            ]);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($memoryLimit = $input->getOption('memory-limit')) {
            $this->setMemoryLimit($memoryLimit);
        }

        $dependencyGraph = $this->createDependencyGraph(
            $output,
            $input->getArgument('paths'),
            !is_null($input->getOption('exclude')) ? [$input->getOption('exclude')] : []
        );

        return $this->inspectDependencyGraph($dependencyGraph, $output);
    }

    /**
     * @param OutputInterface $output
     * @param string[] $paths
     * @param string[] $excludePaths
     * @return DependencyGraph
     */
    protected function createDependencyGraph(OutputInterface $output, array $paths, array $excludePaths = []): DependencyGraph
    {
        $convertRealpathClosure = function ($path) {
            $realpath = realpath($path);
            if (!is_file($realpath) && !is_dir($realpath)) {
                throw new InvalidCommandArgumentException("path was not found: {$realpath}");
            }

            return $realpath;
        };
        $paths = array_map($convertRealpathClosure, $paths);
        $excludePaths = array_map($convertRealpathClosure, $excludePaths);

        return $this->createDependencyDumper($output)->dump($paths, $excludePaths);
    }

    /**
     * @param OutputInterface $output
     * @return DependencyDumper
     */
    protected function createDependencyDumper(OutputInterface $output): DependencyDumper
    {
        $currentWorkingDirectory = getcwd();
        if ($currentWorkingDirectory === false) {
            throw new ShouldNotHappenException('getting current working dir is failed.');
        }

        $tmpDir = sys_get_temp_dir() . '/phpstan';
        if (!@mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
            throw new ShouldNotHappenException('creating a temp directory is failed: ' . $tmpDir);
        }

        return DependencyDumper::createFromConfig(
            $currentWorkingDirectory,
            $tmpDir,
            self::DEFAULT_CONFIG_FILES
        )->setObserver(new DependencyDumperObserver($output));
    }

    /**
     * @param string $memoryLimit
     */
    protected function setMemoryLimit(string $memoryLimit): void
    {
        if (preg_match('#^-?\d+[kMG]?$#i', $memoryLimit) !== 1) {
            throw new InvalidCommandArgumentException(sprintf('memory-limit is invalid format "%s".', $memoryLimit));
        }
        if (ini_set('memory_limit', $memoryLimit) === false) {
            throw new UnexpectedException("setting memory_limit to {$memoryLimit} is failed.");
        }
    }
}
