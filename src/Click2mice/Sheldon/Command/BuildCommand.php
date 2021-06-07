<?php


namespace Click2mice\Sheldon\Command;
use GuzzleHttp\Client as Guzzle;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

    class BuildCommand extends ProcessCommand
    {
        protected function configure()
        {
            $this
            ->setName('build')
            ->setDescription('Фиксирует ветку RC для указанной версии указанного компонента')
            ->setHelp('Фиксирует ветку RC для указанной версии указанного компонента: обновляет composer.lock, запускает тесты, и после этого ставит тег.')
            ->addArgument(
                    'component',
                    InputArgument::REQUIRED,
                    'Название компонента (например: site или order-api)'
                )
            ->addArgument(
                    'version',
                    InputArgument::REQUIRED,
                    'Числовой номер версии (например: 13083 или 1.15.2) или значение next (возьмет следующую по порядку версию, если для нее задана дата релиза)'
                )
            ->addOption(
                    'branch-name',
                    'b',
                    InputOption::VALUE_OPTIONAL,
                    'Название бранча'
                )
            ;
        }

        protected function execute(InputInterface $input, OutputInterface $output)
        {
            $version = $input->getArgument( 'version' );
            $component = $input->getArgument( 'component' );
            $project = $this->getProject();

            $versionName = $this->getComponentVersionName( $component, $version );

            $repoUrl = $this->getComponentRepoUrl( $component );
            if ( ! $versionName || ! $repoUrl ) {
                $output->writeln( sprintf( '<error>неизвестный компонент: %s</error>', $component ) );
                return;
            }

            $output->writeln( "Компонент: <comment>$component</comment>" );
            $output->writeln( "Версия: <comment>$versionName</comment>" );
            $output->writeln( "URL репозитория: <comment>$repoUrl</comment>" );

            // формируем название бранча
            if ( ! ( $integrateBranch = $input->getOption('branch-name' ) ) ) {
                $integrateBranch = $this->getRCBranchName($version);
            }
            $output->writeln( "Ветка: <comment>$integrateBranch</comment>" );

            $versionData = $this->getVersion($project, $versionName);
            if (empty($versionData)) {
                $output->writeln('<error>Версия ' . $versionName . ' не найдена в проекте</error>');
                return;
            }

            if ($versionData['released'] || $versionData['archived']) {
                $output->writeln('<error>Версия ' . $versionName . ' выпущена или отправлена в архив</error>');
                return;
            }
            if (!$this->lockComponent($component)) {
                $this->waitAndLockComponent($component, $output);
            }
            // подготовка временного клона репозитория
            $repo = $this->prepareRepo( $repoUrl, $output );

            // подготовка бранча для интеграции
            $this->prepareIntegrateBranch($repo, $integrateBranch, $output);


            $repo->run('fetch --tags');
            try {
                $lastTag = $repo->run('describe --tags');
                $lastTag = str_replace("\n", '', $lastTag);
            }
            catch (\Exception $e) {
                $lastTag = '';
            }

            $matches = array();
            if (preg_match('|^' . $version . '$|', $lastTag)) {
                $output->writeln('<error>Версия уже в ветке master</error>');
                return;
            }
            elseif (preg_match('|^' . $version . '-rc(\d+)$|', $lastTag, $matches)) {
                $output->writeln('<info>Билд ' . $matches[0] . ' уже существует</info>');
                return;
            }
            elseif (preg_match('|^' . $version . '-rc(\d+)-(\d+)-g([0-9a-f]+)$|', $lastTag, $matches)) {
                $tag = $version . '-rc' . ($matches[1] + 1);
            }
            else {
                $tag = $version . '-rc1';
            }

            $output->writeln('Подготовка версии <comment>' . $tag . '</comment>');

            $output->write( 'Проверка composer-зависимостей... ' );
            $resultComposerDependency = \Helper::checkComposerDependencies( $repo->get_repo_path() . '/composer.json' );
            if ( $resultComposerDependency !== true ) {
                $output->writeln( '<error>некорректная зависимость для пакета ' . $resultComposerDependency . '</error>' );
                return;
            }
            else {
                $output->writeln( 'OK' );
            }

            $allowBuild = true;

            $output->write( 'Получение тикетов... ' );

            $jql = "fixVersion = '$versionName' AND project = '$project' AND component = '$component' ORDER BY key ASC";

            $issues = $this->getJiraClient()->getIssuesByJql($jql, 'status');

            if ( $issues )
            {
                $output->writeln( '<info> найдено ' . $issues['total'] . '</info>');
                if ( $issues['total'] )
                {
                    foreach ( $issues['issues'] as $issue ) {
                        $issueKey = $issue['key'];
                        $output->write( 'Check ' . $issueKey . ' status... ' );
                        $issueStatus = $issue['fields']['status']['name'];

                        // все тикеты версии должны быть в статусе Integrated to RC
                        if (in_array(
                            $issueStatus,
                            $this->config['commands.options']['Build'][$project . ".statuses"]
                        )) {
                            $output->writeln('<info>OK</info>');
                        }
                        else {
                            $output->writeln('<error>ошибка</error>');
                            $allowBuild = false;
                        }
                    }
                }
            }
            else {
                $output->writeln( '<error>ошибка</error>' );
            }

            if ($allowBuild)
            {
                $this->updateComposerPhar($repo, $output);
                $this->updateComposerLock($repo, $output);

                $phpunitResult = true;

                if ( isset( $this->config['components'][$component]['phpunit_command'] ) ) {
                    $phpunitOutput = '';
                    $output->write( 'Запуск unit-тестов... ' );
                    $phpunitResult = $this->runUnitTests($repo, $this->config['components'][$component]['phpunit_command'], $phpunitOutput, $output);
                    if ($phpunitResult) {
                        $output->writeln('<info>OK</info>');
                    }
                    else {
                        $output->writeln('<error>ошибка</error>');
                        $output->writeln($phpunitOutput);

                        $output->writeln('Reset repo to HEAD');
                        $repo->run('reset --hard HEAD');
                    }
                }

                if ($phpunitResult)
                {
                    $output->write('Commit composer.lock... ');
                    try {
                        $repo->commit('Updated composer.lock');
                        $output->writeln('OK');
                    }
                    catch (\Exception $e) {
                        $output->writeln('коммитить нечего');
                    }

                    $output->write( 'Добавление тега <comment>' . $tag . '</comment>... ' );
                    $repo->add_tag( $tag );
                    $output->writeln( 'OK' );

                    $output->write( 'Push ' . $integrateBranch . ' to origin... ' );
                    $repo->push( 'origin', $integrateBranch );
                    $output->writeln( 'OK' );

                    $output->writeln('<info>Билд ' . $tag . ' собран</info>');
                    $this->sendSlackMessage('*Билд ' . $component . ' версии ' . $tag . ' собран*');

                if (isset($matches[1]) && $matches[1] + 1 > 1) {
                    sleep(5);
                    $jql = "project = QA AND component = '$component' AND version ~ '$version*' ORDER BY key ASC";
                    $issues = $this->getJiraClient()->getIssuesByJql($jql);

                        if (empty($issues['issues']) || count($issues['total']) > 1) {
                            $this->sendSlackMessage("*Не забудьте поменять версию в QA*");
                        } else {
                            $qaKey = reset($issues['issues'])['key'];
                            $this->sendSlackMessage("Не забудьте поменять версию в $qaKey http://jira.lan/browse/$qaKey");
                        }
                    }
                }
                if (isset($this->config['components'][$component]['jenkins.build'])) {
                    $guzzle = new Guzzle();
                    $guzzle->post($this->config['components'][$component]['jenkins.build'] . $tag);
                }
            }
            if (!$this->lockComponent($component, true)) {
                $output->writeln("<error>Не удалось разблокировать компонет {$component}</error>");
            }

       }

    }
