<?php

namespace Click2mice\Sheldon\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SyncCodeReviewsCommand extends ProcessCommand
{

    const CACHE_KEY_COMPLETED = 'proccess_automation_issue_completed';

    protected function configure()
    {
        $this
            ->setName('sync-code-reviews')
            ->setDescription('Создает код-ревью по тикетам')
        ;
    }

    protected function notifyAboutCompletedReview($reviewKey, $issue, $reviewers, $teamLead, OutputInterface $outPut)
    {
        if (!isset($this->config['users'][$teamLead]['slack'])) {
            $outPut->writeln("В конфигурации отсутствует информация о {$teamLead}");
            return;
        }
        $done = true;
        foreach($reviewers as $reviewer){
            $done &= $reviewer['completed'];
        }
        $cacheKey = self::CACHE_KEY_COMPLETED . $reviewKey;
        if (!\MCache::getInstance()->get($cacheKey)) {
            if ($done) {
                \MCache::getInstance()->set($cacheKey, 1);
                $outPut->writeln("Отправка сообщения в slack тимлиду {$teamLead} о завершенном ревью {$reviewKey}");
                $this->sendSlackMessage("Ревью тикета *{$issue['fields']['summary']}* {$this->config['jira.url']}/browse/{$issue['key']} просмотрено всеми участниками {$this->config['crucible.url']}/cru/{$reviewKey}/", "@" . $this->config['users'][$teamLead]['slack']);
            }
        } else if (!$done) {
            \MCache::getInstance()->delete($cacheKey);
        }
    }


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $crucibleClient = $this->getCrucibleClient();

        // собираем массив существующих ревью, замэпленных на тикеты
        $issueReviews = array();
        $output->write('Поиск открытых ревью... ');

        $projects = array_unique($this->config['crucible.project_table']);
        foreach ($projects as $crucible_project) {
            $reviews = $crucibleClient->getReviews(array('project' => $crucible_project));

            if ($reviews === false) {
                $output->writeln('<error>Crucible communication error! Fix, then restart</error>');
                return;
            }

            foreach ($reviews['reviewData'] as $review) {
                if (!empty($review['jiraIssueKey'])) {
                    $issueReviews[strtoupper($review['jiraIssueKey'])][] = $review['permaId']['id'];
                }
            }
        }

        $output->writeln('OK');

        foreach ($this->config['components'] as $component => $componentParams) {
            if (isset($componentParams['crucible_repo'])) {
                if (!$this->lockComponent($component)) {
                    $this->waitAndLockComponent($component, $output);
                }
                $crucibleRepo = $componentParams['crucible_repo'];
                $project = $this->getProject($component);

                $output->write(
                    'Получение тикетов компонента <comment>' . $component . '</comment> в статусах ' . ($component !== 'db-wm2' ? '"Ready for code review" или "On code review"... ': '"Need verification" или "Ready for review"...')
                );
                $jql = ($component == 'db-wm2')
                    ? "project = '$project' AND (issuetype='Development task' AND status='Need verification' OR issuetype='Bug report' AND status='Ready for review') ORDER BY key ASC"
                    : "issuetype IN ('"
                    . implode("', '", $this->config['commands.options']['SyncCodeReviews'][$project . ".issuetypes"]) .
                    "') AND component = '$component' AND project = '$project' AND status IN ('"
                    . implode("', '", $this->config['commands.options']['SyncCodeReviews'][$project . ".statuses"]) .
                    "') ORDER BY key ASC";
                $output->writeln("\n<info>[JQL] " . $jql . "</info>");
                $issues = $this->getJiraClient()->getIssuesByJql(
                    $jql,
                    implode(
                        ',',
                        [
                            'summary',
                            $this->config['jira.fields.developer'],
                            $this->config['jira.fields.team_leader'],
                            $this->config['jira.fields.code_reviewers']
                        ]
                    )
                );
                if ($issues) {
                    $output->writeln('<comment> найдено ' . $issues['total'] . '</comment>');
                    if ($issues['total']) {
                        $repoUrl = $componentParams['repo_url'];

                        // подготовка временного клона репозитория
                        $repo = $this->prepareRepo($repoUrl, $output);

                        foreach ($issues['issues'] as $issue) {
                            $issueDeveloper     = $issue['fields'][$this->config['jira.fields.developer']]['name'];
                            if (!$issueDeveloper) {
                                $issueDeveloper = $this->config['crucible.username'];
                            }

                            $issueModerator = $issueDeveloper;
                            $issueCodeReviewers = [];
                            if (isset($issue['fields'][$this->config['jira.fields.team_leader']])) {
                                $issueCodeReviewers[] = $issue['fields'][$this->config['jira.fields.team_leader']]['name'];
                                $issueModerator = $issue['fields'][$this->config['jira.fields.team_leader']]['name'];
                            }
                            if (isset($issue['fields'][$this->config['jira.fields.code_reviewers']])) {
                                foreach ($issue['fields'][$this->config['jira.fields.code_reviewers']] as $issueCodeReviewer) {
                                    $issueCodeReviewers[] = $issueCodeReviewer['name'];
                                }
                            }

                            if ($this->actualizeIssueBranch($repo, $project, $issue['key'], $output)) {
                                $issueBranch = $this->getIssueBranch($issue['key']);
                                $output->write('Переключение на ветку ' . $issueBranch . '... ');
                                $repo->checkout($issueBranch);
                                $output->writeln('OK');

                                // получаем все коммиты, содержащие в комментарии номер тикета
                                $logOutput = $repo->run('log --grep="^' . $issue['key'] . '\([^0-9]\|$\)" --pretty=format:%H');
                                if ($logOutput) {
                                    $changesets = explode("\n", $logOutput);

                                    if (isset($issueReviews[$issue['key']])) {
                                        // код ревью для тикета уже существует
                                        $reviewKeys = $issueReviews[$issue['key']];
                                        $output->writeln(
                                            'Открытое ревью для тикета ' . $issue['key'] . ' уже существует'
                                        );
                                    } else {
                                        // код ревью для тикета еще не существует, поэтому надо создать его
                                        $output->write('Создание ревью... ');
                                        $review     = $crucibleClient->createReview(
                                            $this->config['crucible.project_table'][$project],
                                            $issue['key'] . ' ' . $issue['fields']['summary'],
                                            $issueDeveloper,
                                            $this->config['crucible.username'],
                                            $issueModerator,
                                            $issue['key']
                                        );
                                        $reviewKeys = array();
                                        if ($review) {
                                            $reviewKeys[] = $review['permaId']['id'];
                                            $output->writeln('<comment>' . $review['permaId']['id'] . '</comment>');
                                        } else {
                                            $output->writeln('<error>ошибка</error>');
                                        }
                                    }

                                    if ($reviewKeys) {
                                        $reviewLinks = array();
                                        $changesetsAdded = false;
                                        // возможна ситуация, когда для тикета существует несколько код ревью, поэтому
                                        // нужно в каждый добавить чейнджсеты и ревьюверов
                                        foreach ($reviewKeys as $reviewKey) {
                                            $review      = $crucibleClient->getReview($reviewKey, true);
                                            $reviewState = $review['state'];
                                            $isActive    = true;

                                            foreach ($issueCodeReviewers as $issueCodeReviewer) {
                                                $crucibleClient->addReviewReviewers($reviewKey, $issueCodeReviewer);
                                                $output->writeln(
                                                    'Добавление <comment>' . $issueCodeReviewer . '</comment> в качестве ревьювера <comment>' . $reviewKey . '</comment>'
                                                );
                                            }
                                            if ($this->config['crucible.global_reviewers']) {
                                                $crucibleClient->addReviewReviewers(
                                                    $reviewKey,
                                                    $this->config['crucible.global_reviewers']
                                                );
                                                $output->writeln(
                                                    'Добавление проектного ревьювера <comment>' . $this->config['crucible.global_reviewers'] . '</comment> в <comment>' . $reviewKey . '</comment>'
                                                );
                                            }

                                            $addedChangesets = [];
                                            foreach($review['reviewItems']['reviewItem'] as $reviewItem) {
                                                foreach($reviewItem['expandedRevisions'] as $expandedRevision) {
                                                    $addedChangesets[] = $expandedRevision['revision'];
                                                }
                                            }
                                            $addedChangesets = array_unique($addedChangesets);

                                            if (array_diff_key(array_flip($changesets), array_flip($addedChangesets))) {
                                                switch ($reviewState) {
                                                    case \CrucibleClient::REVIEW_STATE_DRAFT:
                                                        $crucibleClient->approveReview($reviewKey);
                                                        $output->writeln(
                                                            'Открытие ревью <comment>' . $reviewKey . '</comment>'
                                                        );
                                                        break;
                                                    case \CrucibleClient::REVIEW_STATE_CLOSED:
                                                        $crucibleClient->reopenReview($reviewKey);
                                                        $output->writeln(
                                                            'Переоткрытие ревью <comment>' . $reviewKey . '</comment>'
                                                        );
                                                        break;
                                                    case \CrucibleClient::REVIEW_STATE_REVIEW:
                                                        $output->writeln(
                                                            'Ревью <comment>' . $reviewKey . '</comment> уже открыто'
                                                        );
                                                        break;
                                                    default:
                                                        $isActive = false;
                                                        $output->writeln(
                                                            'Ревью <comment>' . $reviewKey . '</comment> в статусе <comment>' . $reviewState . '</comment>'
                                                        );
                                                        break;
                                                }

                                                if ($isActive) {
                                                    $changesetsAdded = true;
                                                    foreach ($changesets as $changeset) {
                                                        $output->write(
                                                            'Добавление изменений <comment>' . $changeset . '</comment> в ревью <comment>' . $reviewKey . '</comment>... '
                                                        );
                                                        $addResult = $crucibleClient->addReviewChangesets(
                                                            $reviewKey,
                                                            $crucibleRepo,
                                                            array($changeset)
                                                        );
                                                        $output->writeln(
                                                            $addResult ? '<info>OK</info>' : '<error>ошибка</error>'
                                                        );
                                                    }
                                                }
                                            } else {
                                                $output->writeln(
                                                    "Ревью <comment>$reviewKey</comment> уже актуализировано."
                                                );
                                                if ($reviewState == \CrucibleClient::REVIEW_STATE_REVIEW && !empty($issueCodeReviewers)) {
                                                    $this->notifyAboutCompletedReview($reviewKey, $issue, $review['reviewers']['reviewer'], reset($issueCodeReviewers), $output);
                                                }

                                            }
                                        }
                                        if ($changesetsAdded) {
                                            // переводим тикет в статус On code review (либо он уже там, но тогда ничего не произойдет)
                                            $this->getJiraClient()->addIssueTransitionByName(
                                                $issue['key'],
                                                $this->config['commands.options']['SyncCodeReviews'][$project . ".trans_name"]
                                            );
                                        }
                                    }
                                } else {
                                    $output->writeln('Не найдено коммитов с префиксом ' . $issue['key']);
                                    $this->addUniqueIssueComment($issue['key'], 'Ошибка создания код-ревью: Не найдено коммитов с префиксом ' . $issue['key']);
                                }
                            }

                        }
                    }
                } else {
                    $output->writeln('<error>ошибка</error>');
                }
                if (!$this->lockComponent($component, true)) {
                    $output->writeln("<error>Не удалось разблокировать компонет {$component}</error>");
                }
            }
        }
        return 0;
    }
}