<?php

namespace Click2mice\Sheldon\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Wikimart\JiraClient\Clients;

class ActualizeQaCommand extends ProcessCommand
{
    protected function configure()
    {
        $this
            ->setName('actualize-qa')
            ->setDescription('Актуализирует Release QA-тикет добавляя или удаляя ссылки по Fix Version')
            ->addArgument(
                'component',
                InputArgument::REQUIRED,
                'Название компонента (например: site или order-api)'
            )
            ->addArgument(
                'version',
                InputArgument::REQUIRED,
                'Числовой номер версии (например: 13083 или 1.15.2)'
            );
    }
    // TODO: протестировать delete MD
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $version   = $input->getArgument('version');
        $component = $input->getArgument('component');
        $tag       = null;

        $versionName = $this->getComponentVersionName($component, $version);
        if (!$versionName) {
            $output->writeln(sprintf('<error>неизвестный компонент: %s</error>', $component));
            return 1;
        }

        $output->writeln("Компонент: <comment>$component</comment>");
        $output->writeln("Версия: <comment>$versionName</comment>");

        $output->writeln('Получение QA тикетов для версии...');
        // получаем все тикеты по версии
        $jqlQa  = "project = 'QA' AND component = '{$component}' AND (Version ~ '{$version}*' OR Version ~ '{$versionName}*')";
        $issues = $this->getJiraClient()->getIssuesByJql(
            $jqlQa,
            implode(
                ',',
                [
                    'issuelinks',
                    $this->config['jira.fields.component_version'],
                ]
            )
        );

        if ($issues) {
            $qaVersionIssue = null;
            if ($issues['total']) {
                foreach ($issues['issues'] as $issue) {
                    $componentVersion = $issue['fields'][$this->config['jira.fields.component_version']];

                    if ($componentVersion == $versionName) {
                        // значение в старом формате (site-14073)
                        $qaVersionIssue = $issue;
                    } elseif (preg_match('|^' . $version . '-rc(\d+)$|', $componentVersion)) {
                        // значение в новом формате (14073-rc1)
                        $qaVersionIssue = $issue;
                    } else {
                        continue;
                    }

                    $output->writeln(
                        'Найден тикет <comment>' . $qaVersionIssue['key'] . '</comment> для версии <comment>' . $versionName . '</comment>'
                    );

                    $project = $this->getProject($component);

                    $output->writeln('Получение существующих зависимостей тикета...');
                    $links = $issue['fields']['issuelinks'];

                    $output->writeln("Поиск тикетов c Fix version: {$versionName} для линковки... ");
                    $jql = "project = '{$project}' AND component = '{$component}' AND fixVersion = '{$versionName}'";
                    $output->writeln("<info>[JQL] " . $jql . "</info>");
                    $issuesToLink  = $this->getJiraClient()->getIssuesByJql(
                        $jql,
                        implode(
                            ',',
                            [
                                $this->config['jira.fields.qa_issue_epic_link'],
                            ]
                        )
                    );
                    $alreadyLinked = array();

                    if ($issuesToLink['total']) {
                        $output->writeln("Линкуем тикеты, которые ещё не связаны... ");
                        foreach ($issuesToLink['issues'] as $issueToLink) {
                            if (!$this->alreadyLinked($links, $issueToLink['key'])) {
                                if (!$this->getJiraClient()->addLinkToIssue(
                                    $issue['key'],
                                    $issueToLink['key'],
                                    $this->config['commands.options']['ActualizeQa'][$project . ".link_type"]
                                )
                                ) {
                                    $output->writeln(
                                        "<error>Ошибка при добавлении ссылки {$issueToLink['key']}</error>"
                                    );
                                } else {
                                    $output->writeln("Ссылка <comment>{$issueToLink['key']}</comment> добавлена");
                                }
                            } else {
                                $alreadyLinked[] = $issueToLink['key'];
                            }
                            $epicLink = $issueToLink['fields'][$this->config['jira.fields.qa_issue_epic_link']];
                            if ($epicLink) {
                                if (!$this->alreadyLinked($links, $epicLink)) {
                                    if (!$this->getJiraClient()->addLinkToIssue(
                                        $issue['key'],
                                        $epicLink,
                                        $this->config['commands.options']['ActualizeQa'][$project . ".epic_link_type"]
                                    )
                                    ) {
                                        $output->writeln(
                                            "<error>Ошибка при добавлении ссылки {$epicLink} по Epic link от {$issueToLink['key']}</error>"
                                        );
                                    } else {
                                        $output->writeln("Ссылка <comment>{$epicLink}</comment> добавлена");
                                    }
                                } else {
                                    $alreadyLinked[] = $epicLink;
                                }
                            }
                        }
                    } else {
                        $output->writeln(
                            "<comment>Тикеты с Fix version: {$versionName} для линковки не найдены</comment>"
                        );
                    }
                    if (!empty($alreadyLinked)) {
                        $output->writeln('Удаление зависимостей с несовпадающей Fix Version или Epic link...');
                        foreach ($links as $link) {
                            if (!in_array($link['outwardIssue']['key'], $alreadyLinked)) {
                                $output->writeln("Удаление ссылки {$link['outwardIssue']['key']}...");
                                $this->getJiraClient()->deleteLinkFromIssue($link['id']);
                            }
                        }
                    }
                }
                if (is_null($qaVersionIssue)) {
                    $output->writeln('<error>Тикеты не найдены</error>');
                    return 1;
                }
                $output->writeln('<info>Актуализировано</info>');
            }
        }
        return 0;
    }

    /**
     * Проверяет, есть ли в массиве ссылок тикет
     * @param $links
     * @param $issueKey
     * @return bool
     */
    protected function alreadyLinked($links, $issueKey)
    {
        foreach ($links as $link) {
            if ($link['outwardIssue']['key'] == $issueKey) {
                return true;
            }
        }
        return false;
    }
}