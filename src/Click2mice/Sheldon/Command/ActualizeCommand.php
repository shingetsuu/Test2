<?php

namespace Click2mice\Sheldon\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ActualizeCommand extends ProcessCommand
{
    protected function configure()
    {
        $this
            ->setName('actualize')
            ->setDescription('Актуализирует ветки тикетов, готовых к тестированию или прошедших его, вливая в них свежий master')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln( '<comment>Актуализация тикетов на ' . date( 'Y-m-d H:i:s' ) . '</comment>' );

        $project = $this->getProject();
        $components = $this->config['components'];

        foreach ( $components as $component => $componentParams )
        {
            if (!$this->lockComponent($component)) {
                $this->waitAndLockComponent($component, $output);
            }

            $output->write( 'Получение тикетов компонента <comment>' . $component . '</comment> в статусе "' . join('", "', $this->config['commands.options']['Actualize'][$project . ".statuses"]) . '"... ' );

            $jql = "issuetype IN ('"
                . implode("', '", $this->config['commands.options']['Actualize'][$project . ".issuetypes"]) .
                "') AND project = '$project' AND status IN ('"
                . implode("', '", $this->config['commands.options']['Actualize'][$project . ".statuses"]) .
                "') ORDER BY key ASC";
            $output->writeln("\n<info>[JQL] " . $jql . "</info>");

            $issues = $this->getJiraClient()->getIssuesByJql($jql);
            if ( $issues ) {
                $output->writeln( '<comment> найдено ' . $issues['total'] . '</comment>');
                if ( $issues['total'] )
                {
                    $repoUrl = $componentParams['repo_url'];

                    // подготовка временного клона репозитория
                    $repo = $this->prepareRepo( $repoUrl, $output );

                    foreach ( $issues['issues'] as $issue )
                    {
                        $this->actualizeIssueBranch($repo, $project, $issue['key'], $output);
                    }
                }
            }
            if (!$this->lockComponent($component, true)) {
                $output->writeln("<error>Не удалось разблокировать компонет {$component}</error>");
            }
        }

    }
}