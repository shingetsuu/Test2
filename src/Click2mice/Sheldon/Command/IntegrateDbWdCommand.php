<?php

namespace Click2mice\Sheldon\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrateDbWdCommand extends ProcessCommand
{
    protected function configure()
    {
        $this
            ->setName('integrate-db-wd')
            ->setDescription('Собирает мигрейшены для указанной версии')
            ->setHelp(
                'Собирает мигрейшены для указанной версии из всех тикетов, находящихся в статусе Ready for RC. В случае успешного мержа переводит тикеты в статус Integrated to RC. Если мерж не удался, то оставляет комментарий в тикете с сообщением об ошибке. Все коммиты делаются от текущего пользователя, запускающего скрипт (при наличии ssh-ключа для git).' . "\n" .
                'Мигрейшены складываются в папку по номеру версии, а также формируются файлы со списком апгрейдов и даунгрейдов.'
            )
            ->addArgument(
                'version-name',
                InputArgument::REQUIRED,
                'Полное название версии (например: db-wm2-13083)'
            )->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Ничего не пушить и не менять в Jira'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $versionName = $input->getArgument('version-name');
        $dryRun = $input->getOption('dry-run');
        $numericVersion = \Helper::getNumericVersion($versionName);
        $output->writeln("Версия: <comment>$versionName</comment>");
        $output->writeln('Пробный запуск: <comment>' . ($dryRun ? 'да' : 'нет') . '</comment>');
        $output->writeln("Числовая версия: <comment>$numericVersion</comment>");
        $component = 'db-wm2';
        if (!$this->lockComponent($component)) {
            $this->waitAndLockComponent($component, $output);
        }
        // подготовка временного клона репозитория
        $repoUrl = 'git@git.lan:db-migration.git';
        $repo = $this->prepareRepo($repoUrl, $output);

        $output->write('Получение тикетов... ');

        $issues = $this->getVersionIssues($versionName, $dryRun);
        if (!$issues) {
            $output->writeln('<error>ошибка</error>');
            return 1;
        }

        $output->writeln('<info> найдено ' . $issues['total'] . '</info>');

        if ($issues['total']) {
            // create version directory if not exists
            $versionDir = $repo->get_repo_path() . '/wm2/' . $numericVersion;
            if (!is_dir($versionDir)) {
                $output->writeln('Директория для версии не найдена. Создается новая: <comment>' . $versionDir . '</comment>');
                mkdir($versionDir);
            }

            $upgradeAllSqlFilename = $versionDir . '/all.sql';
            $downgradeAllSqlFilename = $versionDir . '/all.downgrade.sql';

            $mergedIssueKeys = array();

            foreach ($issues['issues'] as $issue) {
                $issueKey = $issue['key'];
                $output->write('Обработка ' . $issueKey . '... ');

                // текст комментария, который нужно добавить к тикету
                $issueComment = null;

                $allowIntegrateError = $this->checkIntegrationLimitations($issueKey, $versionName);

                if (empty($allowIntegrateError)) {
                    $issueDir = $issueName = str_replace('-', '', $issueKey);
                    try {
                        // find dir
                        // no dir - exception, comment to ticket, move ticket to other state
                        $issuePath = $repo->get_repo_path() . '/wm2/' . $issueDir;
                        if (!is_dir($issuePath)) {
                            $output->writeln('<error>issue dir ' . $issueDir . ' does not exists</error>');

                            if (!$dryRun) {
                                $comment = 'Ошибка автоматической интеграции: директория тикета ' . $issueDir . ' не существует.';
                                $this->getJiraClient()->addIssueTransitionByName(
                                    $issueKey,
                                    'Reopened',
                                    null,
                                    $comment
                                );
                            }
                        } else {
                            // move dir in git
                            $output->writeln($issueDir . ' -> wm2/' . $numericVersion);
                            if (!$dryRun) {
                                // todo: check execution status
                                $repo->run("mv {$issuePath} {$versionDir}");

                                $issuePath = $versionDir . '/' . $issueDir;
                            }

                            // append to all.sql/all.downgrade.sql
                            $upgradeIssueSqlFilename = $issueName . '.sql';
                            $downgradeIssueSqlFilename = $issueName . '.downgrade.sql';
                            $upgradeResult = $this->addIssueSqlToAllSql(
                                $repo,
                                $output,
                                $issuePath,
                                $upgradeIssueSqlFilename,
                                $upgradeAllSqlFilename,
                                $dryRun,
                                $issueDir
                            );
                            $downgradeResult = $this->addIssueSqlToAllSql(
                                $repo,
                                $output,
                                $issuePath,
                                $downgradeIssueSqlFilename,
                                $downgradeAllSqlFilename,
                                $dryRun,
                                $issueDir
                            );
                            if (!$upgradeResult || !$downgradeResult) {
                                $issueComment = 'Ошибка автоматической интеграции: проверьте наличие ' .
                                    (($upgradeResult || $downgradeResult) ? 'файла' : 'файлов') .
                                    (!$upgradeResult ? $upgradeIssueSqlFilename : '') .
                                    (($upgradeResult || $downgradeResult) ? '' : ',') .
                                    (!$downgradeResult ? $downgradeIssueSqlFilename : '');
                                $output->writeln('<error>ошибка при мерже</error>');
                            } else {
                                $mergedIssueKeys[] = $issueKey;
                                $output->writeln('<info>успешно помержено</info>');
                            }
                        }
                    } catch(\Exception $e) {
                        $issueComment = 'Ошибка автоматической интеграции: не удалось переместить директорию ' . $issueDir . ' в версию ' . $numericVersion . '. Требуется ручная интеграция.' . "\n\n" . $e->getMessage();
                        $output->writeln('<error>ошибка при мерже</error>');
                    }
                } else {
                    $issueComment = 'Ошибка автоматической интеграции: ' . $allowIntegrateError;
                    $output->writeln('<error>интеграция не разрешена</error>');
                }
                if ($issueComment && !$dryRun) {
                    $repo->run('reset --hard HEAD');
                    $this->getJiraClient()->addIssueTransitionByName(
                        $issueKey,
                        'Reopened',
                        null,
                        $issueComment
                    );
                    $this->addUniqueIssueComment($issueKey, $issueComment);
                    return 1;
                }
            }

            if (!empty($mergedIssueKeys)) {
                if (!$dryRun) {
                    // commit
                    $output->writeln('Commiting ' . $versionName);

                    $message = 'Adding ' . implode(', ', $mergedIssueKeys) . ' to wm2/' . $numericVersion;
                    $repo->commit($message);

                    // push
                    $output->write('Push ' . $versionName . ' to origin... ');
                    $repo->push('origin', 'master');
                    $output->writeln('OK');

                    // ok - change ticket status
                    foreach ($mergedIssueKeys as $mergedIssueKey) {
                        $output->write('Изменяем статус тикета ' . $mergedIssueKey . ' в JIRA на "Integrated to RC"... ');
                        if ($this->getJiraClient()->addIssueTransitionByName($mergedIssueKey, 'Integrate to RC')) {
                            $output->writeln('OK');
                        } else {
                            $output->writeln('<error>ошибка</error>');
                        }
                    }
                } else {
                    $output->writeln('<comment>Включен пробный режим: не вносится изменений в репозиторий и JIRA</comment>');
                }
            }
        }
        if (!$this->lockComponent($component, true)) {
            $output->writeln("<error>Не удалось разблокировать компонет {$component}</error>");
        }
        return 0;
    }

    /**
     * @param $versionName
     * @param $dryRun
     * @return array
     */
    protected function getVersionIssues($versionName, $dryRun)
    {
        $project = $this->getProject();
        $jql = "fixVersion = '$versionName' AND project = '$project' AND component = 'db-wm2'";
        $status = array('Ready for RC');
        if ($dryRun) {
            $status[] = 'Ready for testing';
        }

        $jql .= " AND status IN ('" . implode("','", $status) . "')";
        $jql .= " ORDER BY key ASC";

        $issues = $this->getJiraClient()->getIssuesByJql($jql);

        return $issues;
    }

    /**
     * @param $issueKey
     * @param $versionName
     * @return array
     */
    protected function checkIntegrationLimitations($issueKey, $versionName)
    {
        $issueData = $this->getJiraClient()->getIssue($issueKey);

        // проверка зависимостей тикета
        $links = $issueData['fields']['issuelinks'];
        $allowIntegrateError = null;
        foreach ($links as $link) {
            $linkIssue = array_key_exists(
                'outwardIssue',
                $link
            ) ? $link['outwardIssue'] : $link['inwardIssue'];
            $direction = array_key_exists('outwardIssue', $link) ? 'outward' : 'inward';
            if ($link['type']['name'] == 'Requirement' && $direction == 'outward') {
                $linkIssueStatus = $linkIssue['fields']['status']['name'];
                if ($linkIssueStatus != 'Released' && $linkIssueStatus != 'Integrated to RC') {
                    if ($linkIssueStatus == 'Ready for RC') {
                        // необходимо, поскольку fixVersions и другие поля не будут возвращены для связанных тикетов
                        $linkIssue = $this->getJiraClient()->getIssue($linkIssue['key']);
                        if (empty($linkIssue['fields']['fixVersions'])) {
                            $allowIntegrateError = 'обнаружены зависимости от тикетов, готовых к интеграции в RC, версия которых не указана (' . $linkIssue['key'] . ').';
                            break;
                        } else {
                            $notFoundVersion = true;
                            foreach ($linkIssue['fields']['fixVersions'] as $fixVersion) {
                                if ($fixVersion['name'] == $versionName) {
                                    $notFoundVersion = false;
                                    break;
                                }
                            }
                            if ($notFoundVersion) {
                                $allowIntegrateError = 'обнаружены зависимости от тикетов, готовых к интеграции в RC, но не входящих в версию ' . $versionName . ' (' . $linkIssue['key'] . ', versions - ' . implode(", ", $linkIssue['fields']['fixVersions']) . ', ' . $linkIssueStatus . ').';
                                break;
                            }
                        }
                    } else {
                        $allowIntegrateError = 'обнаружены зависимости от тикетов, не прошедших релиз или не готовых для интеграции (' . $linkIssue['key'] . ', ' . $linkIssueStatus . ').';
                        break;
                    }
                }
            }
        }

        return $allowIntegrateError;
    }

    /**
     * @param \GitRepo        $repo
     * @param OutputInterface $output
     * @param                 $issuePath
     * @param                 $issueSqlFilename
     * @param                 $allSqlFilename
     * @param                 $dryRun
     * @param                 $issueDir
     * @return boolean
     */
    protected function addIssueSqlToAllSql(\GitRepo $repo, OutputInterface $output, $issuePath, $issueSqlFilename, $allSqlFilename, $dryRun, $issueDir)
    {
        if (file_exists($issuePath . '/' . $issueSqlFilename)) {
            $allSqlFilenameShort = str_replace(
                realpath($issuePath . '/../..') . '/',
                '',
                $allSqlFilename
            );
            $output->writeln("Adding to <info>{$allSqlFilenameShort}</info>");
            if (!$dryRun) {
                file_put_contents(
                    $allSqlFilename,
                    'source ' . $issueDir . '/' . $issueSqlFilename . ";\n",
                    FILE_APPEND
                );

                // git add modifications
                $output->writeln("git add {$allSqlFilenameShort}");
                $repo->add($allSqlFilename);
            }
            return true;
        } else {
            $output->writeln("<error>Не найден файл {$issueSqlFilename}</error>");
        }
        return false;
    }
}