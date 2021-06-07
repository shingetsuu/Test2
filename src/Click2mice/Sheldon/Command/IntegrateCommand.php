<?php

namespace Click2mice\Sheldon\Command;

use Symfony\Component\Console\Helper\DialogHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrateCommand extends ProcessCommand
{
    protected function configure()
    {
        $this
        ->setName('integrate')
        ->setDescription('Собирает ветку RC для указанной версии указанного компонента')
        ->setHelp(
                'Собирает ветку RC для указанной версии указанного компонента из всех тикетов, находящихся в статусе Ready for RC. В случае успешного мержа переводит тикеты в статус Integrated to RC. Если мерж не удался, то оставляет комментарий в тикете с сообщением об ошибке. Все коммиты делаются от текущего пользователя, запускающего скрипт (при наличии ssh-ключа для git).' . "\n" .
                'Название ветки RC формируется из префикса "RC." и числового номера версии. Для legacy-way числовой номер версии - это и есть имя версии, т.е. название бранча будет RC.13083. Для модулей composer, например, имя версии в JIRA - это order-api-1.15.2, а числовой номер - это 1.15.2, т.е. имя бранча будет RC.1.15.2.'
            )
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
        ->addOption(
                'preview-mode',
                'p',
                InputOption::VALUE_NONE,
                'Интегрировать тикеты, не прошедшие тестирование, и не менять статусы тикетов'
            )
        ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Ничего не пушить и не менять в Jira'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $version     = $input->getArgument('version');
        $component   = $input->getArgument('component');
        $previewMode = $input->getOption('preview-mode');
        $dryRun      = $input->getOption('dry-run');
        $project     = $this->getProject();

        /** @var DialogHelper $dialog */
        $dialog = $this->getHelperSet()->get('dialog');
        if (!$this->lockComponent($component)) {
            $this->waitAndLockComponent($component, $output);
        }
        // проверяем наличие не релизнутых версий
        $unreleased = [];
        if ( $versionList = $this->getJiraClient()->getVersions( $project ) ) {
            foreach ( $versionList as $versionItem ) {
                $numericVersion = $this->getComponentNumericVersion($component, $versionItem['name']);
                if ($numericVersion && $numericVersion != $version && !$versionItem['released'] && !$versionItem['archived']) {
                    $unreleased[] = $numericVersion;
                }
            }
        }
        if (!empty($unreleased)) {
            if (!$dialog->askConfirmation(
                $output,
                sprintf(
                    "\n" . '<comment>Обнаружены старые версии не в конечном статусе <bg=yellow;options=bold>%s</bg=yellow;options=bold>. Интегрировать всё равно?</comment> (y/n)  ',
                    implode(', ', $unreleased)
                )
            )) {
                return;
            }
        }

        // ищем ближайшую версию для случая автоматического определения ближайшей версии
        if ($version === 'next') {
            $version = $this->getNextVersion($component, $output);
            if (!$version) {
                return;
            }
        }

        $versionName = $this->getComponentVersionName($component, $version);

        $repoUrl = $this->getComponentRepoUrl($component);
        if (!$versionName || !$repoUrl) {
            $output->writeln(sprintf('<error>unknown component: %s</error>', $component));
            return;
        }

        $output->writeln("Компонент: <comment>$component</comment>");
        $output->writeln("Версия: <comment>$versionName</comment>");
        $output->writeln("URL репозитория: <comment>$repoUrl</comment>");
        $output->writeln('Включать не готовые к RC: <comment>' . ($previewMode ? 'да' : 'нет') . '</comment>');
        $output->writeln('Пробный запуск: <comment>' . ($dryRun ? 'да' : 'нет') . '</comment>');

        // формируем название бранча
        if (!($integrateBranch = $input->getOption('branch-name'))) {
            $integrateBranch = $this->getRCBranchName($version);
        }
        $output->writeln("Branch name: <comment>$integrateBranch</comment>");

        $versionData = $this->getVersion($project, $versionName);
        if (empty($versionData)) {
            $output->writeln('<error>Версия ' . $versionName . ' не найдена в проекте</error>');
            return;
        }

        if ($versionData['released'] || $versionData['archived']) {
            $output->writeln('<error>Версия ' . $versionName . ' выпущена или отправлена в архив</error>');
            return;
        }

        // подготовка временного клона репозитория
        $repo = $this->prepareRepo($repoUrl, $output);

        // подготовка бранча для интеграции
        $this->prepareIntegrateBranch($repo, $integrateBranch, $output);

        $remoteBranches = $repo->list_remote_branches();

        $output->write('Проверка composer-зависимостей... ');
        $resultComposerDependency = \Helper::checkComposerDependencies($repo->get_repo_path() . '/composer.json');
        if ($resultComposerDependency !== true) {
            $output->writeln('<error>некорректная зависимость для пакета ' . $resultComposerDependency . '</error>');
            return;
        } else {
            $output->writeln('OK');
        }

        $mergedIssueKeys = array();

        $output->write('Получение тикетов... ');

        $jql = "fixVersion = '$versionName' AND project = '$project' AND component = '$component'";
        if ($previewMode) {
            $jql .= " AND status IN ('"
                . implode("', '", $this->config['commands.options']['Integrate'][$project . ".preview_statuses"]) .
                "')";
        } else {
            $jql .= " AND status IN ('"
                . implode("', '", $this->config['commands.options']['Integrate'][$project . ".statuses"]) .
                "')";
        }
        $jql .= " ORDER BY key ASC";
        $output->writeln("\n<info>[JQL] " . $jql . "</info>");

        $issues = $this->getJiraClient()->getIssuesByJql(
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

        if ($issues) {
            $this->sendPennyMessage("Запущена интеграция версии $versionName", 'positive', $output);
            $output->writeln('<info> найдено ' . $issues['total'] . '</info>');
            if ($issues['total']) {
                $alreadyConfirmedIssues = [];
                foreach ($issues['issues'] as $issue) {
                    $issueKey = $issue['key'];

                    $issueBranch    = $issue['fields'][$this->config['jira.fields.git_branch']];
                    $issueDeveloper = $issue['fields'][$this->config['jira.fields.developer']];

                    if (!$issueDeveloper) {
                        $issueDeveloper = $this->config['crucible.username'];
                    }
                    
                    $output->write(
                        'Process ' . $issueKey . ' (developer: ' . $issue['fields'][$this->config['jira.fields.developer']]['displayName'] . ')... '
                    );

                    // флаг допустимости интеграции
                    $allowIntegrate      = true;
                    $allowIntegrateError = null;

                    // текст комментария, который нужно добавить к тикету
                    $issueComment = null;

                    // текст сообщения для Пенни
                    $pennyMessage = null;

                    // проверка зависимостей тикета
                    $links = $issue['fields']['issuelinks'];
                    foreach ($links as $link) {
                        $linkIssue = array_key_exists(
                            'outwardIssue',
                            $link
                        ) ? $link['outwardIssue'] : $link['inwardIssue'];
                        $direction = array_key_exists('outwardIssue', $link) ? 'outward' : 'inward';
                        if ($link['type']['name'] == 'Requirement' && $direction == 'outward') {
                            $linkIssueStatus = $linkIssue['fields']['status']['name'];
                            if (!in_array(
                                $linkIssueStatus,
                                $this->config['commands.options']['Integrate'][$project . ".filter_statuses"]
                            )
                            ) {
                                if (in_array(
                                    $linkIssueStatus,
                                    $this->config['commands.options']['Integrate'][$project . ".selection_statuses"]
                                )) {
                                    // необходимо, поскольку fixVersions и другие поля не будут возвращены для связанных тикетов
                                    $linkIssue = $this->getJiraClient()->getIssue($linkIssue['key']);
                                    if (empty($linkIssue['fields']['fixVersions'])) {
                                        $allowIntegrate      = false;
                                        $allowIntegrateError = 'обнаружены зависимости от тикетов, готовых к интеграции в RC, версия которых не указана (' . $linkIssue['key'] . ').';
                                        $pennyMessage        = 'есть зависимости от неготовых задач';
                                    } else {
                                        $notFoundVersion = true;
                                        foreach ($linkIssue['fields']['fixVersions'] as $fixVersion) {
                                            if ($fixVersion['name'] == $versionName) {
                                                $notFoundVersion = false;
                                                break;
                                            }
                                        }
                                        if ($notFoundVersion) {
                                            $allowIntegrate      = false;
                                            $allowIntegrateError = 'обнаружены зависимости от тикетов, готовых к интеграции в RC, но не входящих в версию ' . $versionName . ' (' . $linkIssue['key'] . ', versions - ' . implode(", ", $linkIssue['fields']['fixVersions']) . ', ' . $linkIssueStatus . ').';
                                            $pennyMessage        = 'есть зависимости от неготовых задач';
                                        }
                                    }
                                } else {
                                    $allowIntegrate      = false;
                                    $allowIntegrateError = 'обнаружены зависимости от тикетов, не прошедших релиз или не готовых для интеграции (' . $linkIssue['key'] . ', ' . $linkIssueStatus . ').';
                                    $pennyMessage        = 'есть зависимости от неготовых задач';
                                }
                            }
                        }
                        if (!$allowIntegrate) {
                            break;
                        }
                    }

                    if ($allowIntegrate) {
                        if (in_array('origin/' . $issueBranch, $remoteBranches)) {
                            $remoteBranch = 'remotes/origin/' . $issueBranch;
                            try {
                                $branchesToCheck = $repo->run(
                                    'log --pretty=format:"%s" --grep="^WD" "origin/master".."' . $remoteBranch . '" | grep -o "^WD-[[:digit:]]\+" | sort -u'
                                );
                                $branchesToCheck = implode(
                                    ',',
                                    array_map(
                                        function ($a) {
                                            return "'$a'";
                                        },
                                        array_filter(explode("\n", str_replace("\n\r", "\n", $branchesToCheck)))
                                    )
                                );
                                // проверим, что в бранче тикета не попали коммиты, относящиеся к тикетам, которые не готовы к релизу
                                $jiql = "project = '{$project}' AND component = '{$component}' AND key != '{$issueKey}' AND key in ({$branchesToCheck}) AND status NOT IN ('"
                                    . implode(
                                        "', '",
                                        $this->config['commands.options']['Integrate'][$project . ".check_statuses"]
                                    ) .
                                    "')";
                                $output->writeln("\n<info>[JQL] " . $jiql . "</info>");

                                $commitIssues = $this->getJiraClient()->getIssuesByJql($jiql);
                                if ($commitIssues && $commitIssues['total'] > 0) {
                                    $commitIssueKeys = [];
                                    foreach ($commitIssues['issues'] as $commitIssue) {
                                        $commitIssueKeys[] = $commitIssue['key'];
                                    }
                                    $commitIssueKeys = array_diff($commitIssueKeys, $alreadyConfirmedIssues);
                                    if (!empty($commitIssueKeys)) {
                                        $commitIssueKeysStr = implode(',', $commitIssueKeys);
                                        if (!$dialog->askConfirmation(
                                            $output,
                                            sprintf(
                                                "\n" . '<comment>В бранче тикета имеются коммиты, относящиеся к тикетам <bg=yellow;options=bold>%s</bg=yellow;options=bold>, которые не готовы к релизу. Интегрировать всё равно?</comment> (y/n)  ',
                                                $commitIssueKeysStr
                                            )
                                        )
                                        ) {
                                            $allowIntegrate      = false;
                                            $allowIntegrateError = "найдены коммиты, относящиеся к тикетам не готовым к релизу, но выполненые в бранче тикета $issueKey: {$commitIssueKeysStr}";
                                            $pennyMessage        = 'в бранче есть неготовые задачи';
                                        } else {
                                            $alreadyConfirmedIssues = array_merge(
                                                $alreadyConfirmedIssues,
                                                $commitIssueKeys
                                            );
                                        }
                                    }
                                }
                                if ($allowIntegrate) {
                                    // проверим, что в бранче тикета не выполнены другие тикеты, которые не готовы к релизу
                                    $jiraql = "project = '{$project}' AND component = '{$component}' AND key != '{$issueKey}' AND {$this->config['jira.jql.git_branch']} ~ '{$issueBranch}' AND status NOT IN ('"
                                        . implode(
                                            "', '",
                                            $this->config['commands.options']['Integrate'][$project . ".check_statuses"]
                                        ) .
                                        "')";
                                    $output->writeln("\n<info>[JQL] " . $jiraql . "</info>");

                                    $sameBranchIssues = $this->getJiraClient()->getIssuesByJql($jiraql);
                                    if ($sameBranchIssues && $sameBranchIssues['total'] > 0) {
                                        $sameBranchIssueKeys = array();
                                        foreach ($sameBranchIssues['issues'] as $sameBranchIssue) {
                                            $sameBranchIssueKeys[] = $sameBranchIssue['key'];
                                        }
                                        $allowIntegrate      = false;
                                        $allowIntegrateError = "найдены тикеты, не готовые к релизу, но выполненые в бранче тикета $issueKey: " . implode(
                                                ', ',
                                                $sameBranchIssueKeys
                                            );
                                        $pennyMessage        = 'в бранче есть неготовые задачи';
                                    }
                                }
                            } catch (\Exception $e) {
                                $allowIntegrate      = false;
                                $allowIntegrateError = 'не удалось проверить бранч ' . $remoteBranch . '. Требуется ручная интеграция.' . "\n\n" . $e->getMessage(
                                    );
                                $pennyMessage        = 'не удалось проверить дерево коммитов';
                            }
                        } else {
                            $allowIntegrate      = false;
                            $allowIntegrateError = 'указанный бранч ' . $issueBranch . ' не существует. Проверьте правильность значения поля Git branch.';
                            $pennyMessage        = 'бранч не найден';
                        }
                    }
                    if ($allowIntegrate) {
                        try {
                            $this->carefulMerge($repo, 'remotes/origin/' . $issueBranch, $output);
                            // проверяем зависимости в composer.json
                            $resultComposerDependency = \Helper::checkComposerDependencies(
                                $repo->get_repo_path() . '/composer.json'
                            );
                            if ($resultComposerDependency !== true) {
                                $repo->run('reset --hard HEAD~1');
                                $issueComment = 'Ошибка автоматической интеграции: в composer.json указана недопустимая зависимость от версии ' . $resultComposerDependency . '. Необходимо внести исправления в бранче ' . $issueBranch . ' и повторить интеграцию.';
                                $pennyMessage = 'плохой композер.джэйсон';
                                $output->writeln(
                                    '<error>некорректная зависимость для пакета ' . $resultComposerDependency . '</error>'
                                );
                            } else {
                                $mergedIssueKeys[] = $issueKey;
                                $output->writeln('<info>успешно помержено</info>');
                            }
                        } catch (\Exception $e) {
                            $issueComment = 'Ошибка автоматической интеграции: не удалось смержить бранч ' . $issueBranch . ' в бранч ' . $integrateBranch . '. Требуется ручная интеграция.' . "\n\n" . $e->getMessage(
                                );
                            $pennyMessage = 'конфликты в коде';
                            $output->writeln('<error>ошибка при мерже</error>');
                        }
                    } else {
                        $issueComment = 'Ошибка автоматической интеграции: ' . $allowIntegrateError;
                        $output->writeln('<error>интеграция не разрешена</error>');
                    }

                    if ($issueComment && !$dryRun) {
                        $this->addUniqueIssueComment($issueKey, $issueComment);
                    }

                    if ($pennyMessage && !$dryRun) {
                        $pennyMessage = $issueDeveloper['displayName'] . ', ошибка автоматической интеграции ' . $issueKey . ': ' . $pennyMessage;
                        $this->sendPennyMessage($pennyMessage, 'negative', $output);
                    }
                }
            }
        } else {
            $output->writeln('<error>ошибка</error>');
        }

        if (!$dryRun) {

            if ($mergedIssueKeys) {
                $this->updateComposerLock($repo, $output);

                $output->write('Коммит composer.lock... ');
                try {
                    $repo->commit('Update composer.lock');
                    $output->writeln('OK');
                } catch (\Exception $e) {
                    $output->writeln('коммитить нечего');
                }

                $output->write('Push ' . $integrateBranch . ' to origin... ');
                $repo->push('origin', $integrateBranch);
                $output->writeln('OK');

                foreach ($mergedIssueKeys as $mergedIssueKey) {
                    $output->write('Изменяем статус тикета ' . $mergedIssueKey . ' в JIRA на "Integrated to RC"... ');
                    if ($this->getJiraClient()->addIssueTransitionByName(
                        $mergedIssueKey,
                        $this->config['commands.options']['Integrate'][$project . ".trans_name"]
                    )
                    ) {
                        $output->writeln('OK');
                    } else {
                        $output->writeln('<error>ошибка</error>');
                    }
                }
            } else {
                $output->writeln('<comment>Нет изменений в собираемой ветке</comment>');
            }
        } else {
            $output->writeln('<comment>Включен пробный режим: не вносится изменений в репозиторий и JIRA</comment>');
        }
        if (!$this->lockComponent($component, true)) {
            $output->writeln("<error>Не удалось разблокировать компонет {$component}</error>");
        }
    }

    /**
     * @param string          $component
     * @param OutputInterface $output
     * @return mixed
     */
    protected function getNextVersion($component, OutputInterface $output)
    {
        $deadline = new \DateTime();
        $deadline->modify('+1 day');
        $versions     = $this->getJiraClient()->getVersions($this->getProject());
        $nextVersions = array();
        foreach ($versions as $versionData) {
            $numericVersion = $this->getComponentNumericVersion($component, $versionData['name']);
            if ($numericVersion && !$versionData['released']) {
                $versionData['numericVersion']                = $numericVersion;
                $nextVersions[$versionData['numericVersion']] = $versionData;
            }
        }
        ksort($nextVersions);
        if ($nextVersions) {
            $versionData = reset($nextVersions);
            if (!empty($versionData['releaseDate'])) {
                if ($deadline < new \DateTime($versionData['releaseDate'])) {
                    return $versionData['numericVersion'];
                } else {
                    $output->writeln('<error>Следующая версия ' . $versionData['numericVersion'] . ' заморожена</error>');
                    return null;
                }
            } else {
                $output->writeln('<error>Следующая версия ' . $versionData['numericVersion'] . ' не была запланирована</error>');
                return null;
            }
        } else {
            $output->writeln('<error>Следующая версия не найдена</error>');
            return null;
        }
    }
}