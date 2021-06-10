<?php

namespace Click2mice\Sheldon\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TestCommitCommand extends ProcessCommand
{
    protected function configure()
    {
        $this
            ->setName('test-commit')
            ->setDescription('Тестирует коммит в репозитории компонента')
            ->addArgument(
                'component',
                InputArgument::REQUIRED,
                'Название компонента (например: site или order-api)'
            )
            ->addArgument(
                'commit',
                InputArgument::REQUIRED,
                'Хеш коммита, ветка или тег'
            )
            ->addOption(
                'return-phpunit.php-output',
                null,
                InputOption::VALUE_NONE,
                'Вывести в output вывод запуска phpunit.php (разделитель - \n\n)'
            )
            ->addOption(
                'coverage-dir',
                null,
                InputOption::VALUE_OPTIONAL,
                'Директория, в которую записать файлы отчета по покрытию и логи запуска тестов'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $component = $input->getArgument( 'component' );
        $commit = $input->getArgument( 'commit' );

        if(!isset($this->config['components'][$component])) {
            throw new \InvalidArgumentException('Неизвестный компонент: '.$component);
        }

        $componentParams = $this->config['components'][$component];

        if(!isset($componentParams['phpunit_command'])) {
            throw new \Exception('Please define phpunit_command for component '.$component);
        }
        if (!$this->lockComponent($component)) {
            $this->waitAndLockComponent($component, $output);
        }
        $output->writeln( '<comment>Запуск тестов для '.$commit.'::'.$component.' at ' . date( 'Y-m-d H:i:s' ) . '</comment>' );

        $repoUrl = $componentParams['repo_url'];

        // подготовка временного клона репозитория
        $repo = $this->prepareRepo( $repoUrl, $output );

        $output->write( 'Checkout to ' . $commit . '... ' );
        $repo->checkout( $commit );
        $output->writeln( 'OK' );

        $lockIsVersioned = true;
        try {
            $repo->run('git ls-files composer.json --error-unmatch &> /dev/null');
        } catch (\Exception $e) {
            $lockIsVersioned = false;
        }

        if ($lockIsVersioned) {
            $this->installComposerPackages($repo, $output);
        } else {
            $this->updateComposerLock($repo, $output);
        }

        $phpunitCommand = $componentParams['phpunit_command'];

        $coverageDir = $input->getOption('coverage-dir');
        if($coverageDir) {
            if(!is_dir($coverageDir)) {
                mkdir($coverageDir);
            }

            $logFilename = $coverageDir.'/log.json';
            $coverageSerialized = $coverageDir.'/coverage.serialized';
            $htmlReportDir = $coverageDir.'/html';

            $phpunitCommand .= ' --coverage-html '.$htmlReportDir;
            $phpunitCommand .= ' --coverage-php '.$coverageSerialized;
            $phpunitCommand .= ' --log-json '.$logFilename;
        }

        $phpunitOutput = '';
        $output->writeln( 'Запуск тестов...');
        $output->writeln($phpunitCommand);
        $isTestsPassed = $this->runUnitTests($repo, $phpunitCommand, $phpunitOutput, $output);
        $output->writeln($isTestsPassed? '<info>tests passed!</info>' : '<error>tests failed</error>');

        if($input->getOption('return-phpunit.php-output')) {
            $output->writeln("\n\n".$phpunitOutput);
        }
        if (!$this->lockComponent($component, true)) {
            $output->writeln("<error>Не удалось разблокировать компонет {$component}</error>");
        }
        return intval(!$isTestsPassed);
    }
}