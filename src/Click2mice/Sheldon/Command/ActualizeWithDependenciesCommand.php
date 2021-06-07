<?php

namespace Click2mice\Sheldon\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ActualizeWithDependenciesCommand extends ProcessCommand
{
    protected function configure()
    {
        $this
            ->setName('actualize-with-dependencies')
            ->setDescription(
                'Актуализирует ветки тикетов, готовых к тестированию или прошедших его, зависимостями этих тикетов'
            )
            ->setHelp('')
            ->addArgument(
                'issue-key',
                InputArgument::OPTIONAL,
                'Номер тикета, который необходимо актуализировать'
            );;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<comment>Актуализация тикетов на ' . date('Y-m-d H:i:s') . '</comment>');

        $project = $this->getProject();

        $issueKey = $input->getArgument('issue-key');

        if ($issueKey) {
            // пока берем только первую компоненту
            $issue = $this->getJiraClient()->getIssue($issueKey);
            if (!$issue) {
                $output->writeln("<error>Отсутствует тикет - '$issueKey'</error>");
            }
            if (isset($issue['fields']['components'][0]['name'])) {
                $issueComponent = $issue['fields']['components'][0]['name'];
            } else {
                $output->writeln("<error>У тикета '$issueKey' отсутствуют компоненты</error>");
                return;
            }
            $repoUrl = $this->config['components'][$issueComponent]['repo_url'];
            $repo    = $this->prepareRepo($repoUrl, $output);

            try {
                $issue = $this->getIssueInfoForMerging($project, $issueKey, $repo, $output);
                if (!$issue) {
                    $output->writeln('<comment>Нет необходимости актуализировать бранч</comment>');
                    return;
                }
                $this->actualizeRequiredIssueRecursive($issue, $project, $issueComponent, $repo, $output);
            } catch (\Exception $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
            }

            return;
        }

        $components = $this->config['components'];

        foreach ($components as $component => $componentParams) {
            if (!$this->lockComponent($component)) {
                $this->waitAndLockComponent($component, $output);
            }
            $output->write(
                'Получение тикетов компонента <comment>' . $component . '</comment> в статусе "Ready for testing.php"... '
            );
            // подготовка временного клона репозитория
            $repoUrl = $componentParams['repo_url'];
            $repo    = $this->prepareRepo($repoUrl, $output);

            $jql = "issuetype IN ('"
                . implode(
                    "', '",
                    $this->config['commands.options']['ActualizeWithDependencies'][$project . ".issuetypes"]
                ) .
                "') AND component = '$component' AND project = '$project' AND status IN ('"
                . implode(
                    "', '",
                    $this->config['commands.options']['ActualizeWithDependencies'][$project . ".statuses"]
                ) .
                "') ORDER BY key ASC";
            $output->writeln("\n<info>[JQL] " . $jql . "</info>");

            $issues = $this->getJiraClient()->getIssuesByJql($jql);
            if ($issues) {
                $output->writeln('<comment> найдено ' . $issues['total'] . '</comment>');
                if ($issues['total']) {
                    foreach ($issues['issues'] as $issue) {
                        try {
                            $issue = $this->getIssueInfoForMerging($project, $issue['key'], $repo, $output);
                            if (!$issue) {
                                $output->writeln('<comment>Нет необходимости актуализировать бранч</comment>');
                                return;
                            }
                            $this->actualizeRequiredIssueRecursive($issue, $project, $component, $repo, $output);
                        } catch (\Exception $e) {
                            $output->writeln('<error>' . $e->getMessage() . '</error>');
                        }
                    }
                }
            }
            if (!$this->lockComponent($component, true)) {
                $output->writeln("<error>Не удалось разблокировать компонет {$component}</error>");
            }
        }

    }

    protected function getIssueInfoForMerging($project, $issueKey, \GitRepo $repo, OutputInterface $output)
    {
        $issueStatus    = $this->getIssueStatus($issueKey);
        $remoteBranches = $repo->list_remote_branches();
        if ($issueStatus == 'Released') {
            return null;
        }
        if (!in_array(
            $issueStatus,
            $this->config['commands.options']['ActualizeWithDependencies'][$project . ".allowed_statuses"]
        )
        ) {
            throw new \Exception(
                "Тикет " . $issueKey . " находится в недопустимом статусе - '$issueStatus'"
            );
        }

        $issueBranch = $this->getIssueBranch($issueKey);

        if ($issueBranch == 'master') {
            $output->writeln(
                'Git branch, указанный в тикете ' . $issueKey . ' = master – ПРОПУСК'
            );
            return null; // с бранчом ничего делать не нужно, поэтому вернем null
        }

        $issueStatus = $this->getIssueStatus($issueKey);

        if (!in_array('origin/' . $issueBranch, $remoteBranches)) {
            $output->writeln('<error>Remote issue branch does not exists</error> ');
            $this->stepBackIssue($project, $issueKey, $issueStatus, $output);
            $this->addUniqueIssueComment(
                $issueKey,
                'Ошибка актуализации бранча тикета: указанный бранч ' . $issueBranch . ' не существует. Проверьте правильность значения поля Git branch.'
            );
            throw new \Exception("Удаленная ветка недоступна");
        }
        return [
            "issueKey"   => $issueKey,
            "status"     => $issueStatus,
            "gitBranch"  => $issueBranch,
            "actualized" => false
        ];
    }

    /**
     * @param                 $parentIssue - родительский тикет
     * @param                 $project     - проект
     * @param                 $component   - компонента
     * @param \GitRepo        $repo        - репозиторий
     * @param OutputInterface $output
     * @return mixed - актуализированный тикет
     * @throws \Exception
     */
    protected function actualizeRequiredIssueRecursive(
        $parentIssue,
        $project,
        $component,
        \GitRepo $repo,
        OutputInterface $output
    ) {
        if ($parentIssue['actualized']) {
            return $parentIssue;
        }
        $childrenIssues = $this->getRequiredIssues($parentIssue['issueKey'], $project, $component, $repo, $output);
        $localBranches  = $repo->list_branches();
        if (!empty($childrenIssues)) {
            foreach ($childrenIssues as $childrenIssue) {
                $child = $this->actualizeRequiredIssueRecursive($childrenIssue, $project, $component, $repo, $output);
                if (in_array($parentIssue['gitBranch'], $localBranches)) {
                    $output->write('Переключение на master... ');
                    $repo->checkout('master');
                    $output->writeln('OK');
                    $output->write('Удаление локальной ветки тикета... ');
                    $repo->delete_branch($parentIssue['gitBranch'], true);
                    $output->writeln('OK');
                }

                $output->write('Переключение на ' . $parentIssue['gitBranch'] . '... ');
                $repo->checkout($parentIssue['gitBranch']);
                $output->writeln('OK');

                try {
                    $output->write('Мерж ' . $child['issueKey'] . ' в ' . $parentIssue['gitBranch'] . '... ');
                    $this->carefulMerge($repo, $child['gitBranch'], $output);
                    $output->writeln('OK');
                } catch (\Exception $e) {
                    $this->stepBackIssue($project, $parentIssue['issueKey'], $parentIssue['status'], $output);
                    $this->addUniqueIssueComment(
                        $parentIssue['issueKey'],
                        'Ошибка актуализации бранча тикета: не удалось смержить бранч ' . $child['gitBranch'] . ' в бранч ' . $parentIssue['gitBranch'] . '. Требуется ручная актуализация.' . "\n\n" . $e->getMessage(
                        )
                    );
                    throw new \Exception("<error>Ошибка при мерже</error>");
                }

                try {
                    $output->write('Push ' . $parentIssue['gitBranch'] . ' to origin... ');
                    $repo->push('origin', $parentIssue['gitBranch']);
                    $output->writeln('OK');
                } catch (\Exception $e) {
                    $output->writeln('<error>Ошибка при пуше в origin</error>');
                    throw new \Exception('Ошибка при пуше в origin бранча - ' . $parentIssue['gitBranch']);
                }

            }

            $parentIssue['actualized'] = true;
            $returnIssue               = $parentIssue;
            $output->write('<comment>Тикет ' . $parentIssue['issueKey'] . ' успешно актуализирован</comment>');
        } else {
            if (!$this->actualizeIssueBranch($repo, $project, $parentIssue['issueKey'], $output)) {
                throw new \Exception('Ошибка актуализации мастером');
            }
            $parentIssue['actualized'] = true;
            $returnIssue               = $parentIssue;
            $output->write('<comment>Тикет ' . $parentIssue['issueKey'] . ' успешно актуализирован</comment>');
        }

        return $returnIssue;
    }

    /**
     * Получить тикеты, от которых зависит текущий тикет (1 уровень вложенности)
     * @param                 $issueKey  - номер тикета
     * @param                 $component - Если не null, тогда получаем тикеты только текущей компоненты
     * @param                 $project   - текущий проект, если null, то из всех
     * @param \GitRepo        $repo
     * @param OutputInterface $output
     * @throws
     * @return bool|array
     */
    protected function getRequiredIssues(
        $issueKey,
        $project = null,
        $component = null,
        \GitRepo $repo,
        OutputInterface $output
    ) {
        $jql   = "issueKey = '$issueKey' AND project = '$project' AND component = '$component'";
        $issue = $this->getJiraClient()->getIssuesByJql(
            $jql,
            implode(
                ',',
                [
                    'status',
                    'issuelinks',
                    $this->config['jira.fields.developer'],
                    $this->config['jira.fields.git_branch']
                ]
            )
        );

        $mergeBranches = [];

        if ($issue['total'] == 1) {
            $links = $issue['issues'][0]['fields']['issuelinks'];
            foreach ($links as $link) {
                $linkIssue = array_key_exists(
                    'outwardIssue',
                    $link
                ) ? $link['outwardIssue'] : $link['inwardIssue'];
                $direction = array_key_exists('outwardIssue', $link) ? 'outward' : 'inward';

                $linkIssue = $this->getJiraClient()->getIssue($linkIssue['key']);
                if (!$linkIssue) {
                    $output->writeln("<error>Отсутствует тикет зависимости - '$issueKey'</error>");
                }
                if (isset($linkIssue['fields']['components'][0]['name'])) {
                    $linkComponent = $linkIssue['fields']['components'][0]['name'];
                } else {
                    $output->writeln(
                        "<comment>У зависимости " . $linkIssue['key'] . " отсутствуют компоненты</comment>"
                    );
                    continue;
                }
                if ($component == $linkComponent && $link['type']['name'] == 'Requirement' && $direction == 'outward') {
                    $issue = $this->getIssueInfoForMerging($project, $linkIssue['key'], $repo, $output);
                    if ($issue) {
                        $mergeBranches[] = $issue;
                    }
                }
            }
        }
        return $mergeBranches;
    }
}