<?php

namespace Click2mice\Sheldon\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ActualizeNeedActualizationCommand extends ProcessCommand
{
    protected $actualized;

    protected function configure()
    {
        $this
            ->setName('actualize-need-actualization')
            ->setDescription('Актуализирует ветки тикетов, находящихся в статусе "Need actualization"');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<comment>Актуализация тикетов на ' . date('Y-m-d H:i:s') . '</comment>');
        $this->actualized = array();
        $components       = $this->config['components'];

        foreach ($components as $component => $componentParams) {
            $project = $this->getProject($component);
            $output->write(
                'Получение тикетов компонента <comment>' . $component . '</comment> в статусе "Need actualization"... '
            );
            if (!$this->lockComponent($component)) {
                $this->waitAndLockComponent($component, $output);
            }
            $jql = "issuetype IN ('"
                . implode(
                    "', '",
                    $this->config['commands.options']['ActualizeNeedActualization'][$project . ".issuetypes"]
                ) .
                "') AND component = '$component' AND project = '$project' AND status IN ('"
                . implode(
                    "', '",
                    $this->config['commands.options']['ActualizeNeedActualization'][$project . ".statuses"]
                ) .
                "') ORDER BY key ASC";
            $output->writeln("\n<info>[JQL] " . $jql . "</info>");

            $issues = $this->getJiraClient()->getIssuesByJql($jql);
            if ($issues) {
                $output->writeln('<comment> найдено ' . $issues['total'] . '</comment>');
                if ($issues['total']) {
                    $repoUrl = $componentParams['repo_url'];

                    // подготовка временного клона репозитория
                    $repo = $this->prepareRepo($repoUrl, $output);

                    foreach ($issues['issues'] as $issue) {
                        $this->actualizeIssueBranch($repo, $project, $issue['key'], $output);
                    }
                }
            }
            if (!$this->lockComponent($component, true)) {
                $output->writeln("<error>Не удалось разблокировать компонет {$component}</error>");
            }
        }
    }

    /**
     * Актуализирует бранч тикета
     * @param \GitRepo                                          $repo
     * @param string                                            $project
     * @param                                                   $issueKey
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return bool
     */
    protected function actualizeIssueBranch(\GitRepo $repo, $project, $issueKey, OutputInterface $output)
    {
        $output->writeln('Обработка тикета <comment>' . $issueKey . '</comment>');

        $issueBranch = $this->getIssueBranch($issueKey);

        if ($issueBranch == 'master') {
            $output->writeln('Git branch, указанный в тикете = master – ПРОПУСК');
            return true;
        }

        $issueStatus = $this->getIssueStatus($issueKey);
        if (!isset($this->actualized[$issueBranch]) || !$this->actualized[$issueBranch]) {
            $localBranches  = $repo->list_branches();
            $remoteBranches = $repo->list_remote_branches();

            if (in_array($issueBranch, $localBranches)) {
                $output->write('Переключение на master... ');
                $repo->checkout('master');
                $output->writeln('OK');
                $output->write('Удаление локальной ветки тикета... ');
                $repo->delete_branch($issueBranch, true);
                $output->writeln('OK');
            }

            if (!in_array('origin/' . $issueBranch, $remoteBranches)) {
                $output->writeln('<error>Remote issue branch does not exists</error> ');
                $this->stepBackIssue($project, $issueKey, $issueStatus, $output);
                return false;
            }
            $output->write('Переключение на ' . $issueBranch . '... ');
            $repo->checkout($issueBranch);
            $output->writeln('OK');

            try {
                $output->write('Мерж master в ' . $issueBranch . '... ');
                $this->carefulMerge($repo, 'master', $output);
                $output->writeln('OK');
            } catch (\Exception $e) {
                $output->writeln('<error>error while merging</error>');
                $this->stepBackIssue($project, $issueKey, $issueStatus, $output);
                return false;
            }

            try {
                $output->write('Push ' . $issueBranch . ' to origin... ');
                $repo->push('origin', $issueBranch);
                $output->writeln('OK');
            } catch (\Exception $e) {
                $output->writeln('<error>Ошибка при пуше в origin</error>');
                return false;
            }
            $this->actualized[$issueBranch] = true;
        }
        try {
            $this->doneActualization($project, $issueKey, $output);
            $output->writeln('Тикет ' . $issueKey . ' успешно актуализирован');
        } catch (\Exception $e) {
            $output->writeln('<error>Ошибка при совершении перехода</error>');
            return false;
        }

        return true;
    }

    /**
     * Нажимает "Done actualization"
     * @param string                                            $project
     * @param                                                   $issueKey
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function doneActualization($project, $issueKey, OutputInterface $output)
    {
        $doneTransition = $this->config['commands.options']['ActualizeNeedActualization'][$project . ".done"];
        $upd            = [
            'worklog' => [
                [
                    'add' => [
                        'timeSpent' => $this->config['commands.options']['ActualizeNeedActualization'][$project . ".timeSpent"]
                    ]
                ]
            ]
        ];
        if (!$this->getJiraClient()->addIssueTransitionByName($issueKey, $doneTransition, null, null, $upd)) {
            $output->writeln("<error>Не могу перевести в {$doneTransition}</error>");
            throw new \Exception("Невозможно перевести в " . $doneTransition);
        }
    }
}