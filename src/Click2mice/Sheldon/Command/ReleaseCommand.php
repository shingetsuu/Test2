<?php

namespace Click2mice\Sheldon\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ReleaseCommand extends ProcessCommand
{
    protected function configure()
    {
        $this
            ->setName('release')
            ->setDescription('Помечает версию и тикеты в Jira как released')
            ->setHelp('Проверяет, что версия существует и что все тикеты в этой версии находятся в статусе "Integrated to RC". Если со всеми тикетами все ок, то переводит их в статус Released, после чего отмечает саму версию как released.')
            ->addArgument(
                'component',
                InputArgument::REQUIRED,
                'Название компонента (например: site или order-api)'
            )
            ->addArgument(
                'tag',
                InputArgument::REQUIRED,
                'Тег RC (например: 14073-rc1)'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $tag = $input->getArgument( 'tag' );
        $component = $input->getArgument( 'component' );

        $matches = array();
        if (preg_match('|^([0-9\.]+)\-rc[0-9]+$|', $tag, $matches)) {
            $version = $matches[1];
        } else {
            $version = $tag;
            $output->writeln('<comment>Формат тега неверен (без -rcX)</comment>');
        }

        $versionName = $this->getComponentVersionName( $component, $version );
        $repoUrl = $this->getComponentRepoUrl( $component );
        if ( ! $versionName || ! $repoUrl ) {
            $output->writeln( sprintf( '<error>неизвестный компонент: %s</error>', $component ) );
            return;
        }

        $project = $this->getProject($component);

        $output->writeln( "Компонент: <comment>$component</comment>" );
        $output->writeln( "Версия: <comment>$versionName</comment>" );
        $output->writeln( "Тег: <comment>$tag</comment>" );
        $output->writeln( "URL репозитория: <comment>$repoUrl</comment>" );
        if (!$this->lockComponent($component)) {
            $this->waitAndLockComponent($component, $output);
        }
        $output->writeln( 'Получение версии... ' );

        $versionData = $this->getVersion($project, $versionName);

        if ( is_null( $versionData) ) {
            $output->writeln( '<error>Версия не найдена</error>' );
            return;
        }

        if ( $versionData['released'] ) {
            $output->writeln( '<error>Версия уже  выпущена</error>' );
            return;
        }

        // подготовка временного клона репозитория
        $repo = $this->prepareRepo( $repoUrl, $output );

        if ($component == 'db-wm2') {
            if (1 != $repo->run("ls-tree -d --name-only master 'wm2/{$tag}' | wc -l")) {
                $output->writeln('<error>Тег не найден в репозитории</error>');
                return;
            }
        } else {
            $tags = explode("\n", $repo->run('tag'));
            if (array_search($tag, $tags) === false) {
                $output->writeln('<error>Tag not found in repository</error>');
                return;
            }
        }

        $output->write( 'Получение тикетов версии...' );
            // получаем все тикеты по версии
            $issues = $this->getJiraClient()->getIssuesByJql(
                "fixVersion = '$versionName' AND project = '$project'",
                'status'
            );
        if ( $issues ) {
            $output->writeln( '<info>найдено ' . $issues['total'] . '</info>' );
            if ( $issues['total'] )
            {
                $checkResult = true;
                foreach ($issues['issues'] as $issue) {
                    $output->write('Проверка статуса тикета <comment>' . $issue['key'] . '</comment>... ');
                    if (!in_array(
                        $issue['fields']['status']['name'],
                        $this->config['commands.options']['Release'][$project . ".filter_statuses"]
                    )
                    ) {
                        $checkResult = false;
                        $output->writeln(
                            '<comment>' . $issue['fields']['status']['name'] . '</comment> - <error>некорректный</error>'
                        );
                    } else {
                        $output->writeln(
                            '<comment>' . $issue['fields']['status']['name'] . '</comment> - <info>OK</info>'
                        );
                    }
                }

                if ( $checkResult )
                {
                    foreach ($issues['issues'] as $issue) {
                        $output->write('Обработка <comment>' . $issue['key'] . '</comment>... ');

                        if ($transitions = $this->getJiraClient()->getIssueTransitions($issue['key'])) {
                            foreach ($transitions['transitions'] as $transition) {
                                if ($transition['name'] == $this->config['commands.options']['Release'][$project . ".trans_name"]) {
                                    if ($this->getJiraClient()->addIssueTransition($issue['key'], $transition['id'])) {
                                        $output->writeln('<info>released</info>');
                                    } else {
                                        $output->writeln('<error>ошибка</error>');
                                    }
                                    break;
                                }
                            }
                        } else {
                            $output->writeln('skip');
                        }
                    }
                }
                else {
                    $output->writeln( '<error>Найдены некорректные тикеты</error>' );
                    return;
                }
            }
        }
        else {
            $output->writeln( '<error>ОШИБКА</error>' );
            return;
        }
        if ($component != 'db-wm2') {
            $output->write( 'Вмерживание тега <comment>' . $tag . '</comment> в master...' );
            try {
                $this->carefulMerge($repo, $tag, $output);
            } catch (\Exception $e) {
                $output->writeln("<error>Не удалось вмержить тег <comment>$tag</comment> в мастер</error>");
                return;
            }
            $output->writeln( 'OK' );
        }

        if ($version != $tag) {
            // если тег имеет вид 14073-rc1, например
            $output->write( 'Добавление тега <comment>' . $version . '</comment>... ' );
            $repo->add_tag($version);
            $output->writeln( 'OK' );
        }

        $output->write( 'Push master to origin... ' );
        $repo->push( 'origin', 'master' );
        $output->writeln( 'OK' );

        $output->write( 'Выпуск версии в JIRA... ' );
        if ( $this->getJiraClient()->editVersion( $versionData['id'], true, null, !empty( $versionData['releaseDate']) ? $versionData['releaseDate'] : date( 'Y-m-d' ) ) ) {
            $output->writeln( '<info>OK</info>' );
        }
        else {
            $output->writeln( '<error>ошибка</error>' );
        }
        if (!$this->lockComponent($component, true)) {
            $output->writeln("<error>Не удалось разблокировать компонет {$component}</error>");
        }
    }
}