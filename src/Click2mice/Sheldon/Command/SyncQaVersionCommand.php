<?php

namespace Click2mice\Sheldon\Command;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SyncQaVersionCommand extends ProcessCommand
{
    protected function configure()
    {
        $this
            ->setName('sync-qa-version')
            ->setDescription('It is a kind of magic')
            ->setHelp('')
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

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $version = $input->getArgument('version');
        $component = $input->getArgument('component');
        $tag = null;

        $versionName = $this->getComponentVersionName($component, $version);
        $repoUrl = $this->getComponentRepoUrl($component);
        if (!$versionName || !$repoUrl) {
            $output->writeln(sprintf('<error>неизвестный компонент: %s</error>', $component));
            return 1;
        }

        $output->writeln("Компонент: <comment>$component</comment>");
        $output->writeln("Версия: <comment>$versionName</comment>");
        $output->writeln("URL репозитория: <comment>$repoUrl</comment>");
        if (!$this->lockComponent($component)) {
            $this->waitAndLockComponent($component, $output);
        }
        $output->writeln('Получение QA Release тикетов для версии...');
        // получаем все тикеты по версии
        $jqlQa = "project = 'QA' AND component = '{$component}' AND (Version ~ '{$version}*' OR Version ~ '{$versionName}*') AND status = 'Released'";
        $issues = $this->getJiraClient()->getIssuesByJql(
            $jqlQa,
            implode(
                ',',
                [
                'status',
                $this->config['jira.fields.component_version'],
                $this->config['jira.fields.qa_version_issue_release_date']
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
                        $tag = $version;
                        break;
                    }
                    elseif (preg_match('|^' . $version . '-rc(\d+)$|', $componentVersion)) {
                        // значение в новом формате (14073-rc1)
                        $qaVersionIssue = $issue;
                        $tag = $componentVersion;
                        break;
                    }
                }
            }

            if (is_null($qaVersionIssue)) {
                $output->writeln('<error>Тикеты не найдены</error>');
                return 1;
            }

            $output->writeln(
                'Найден тикет <comment>' . $qaVersionIssue['key'] . '</comment> для версии <comment>' . $versionName . '</comment>'
            );

            $output->writeln('Поиск версии в проекте... ');

            $project = $this->getProject($component);
            $versionData = $this->getVersion($project, $versionName);
            if (empty($versionData)) {
                $output->writeln('<error>Версия ' . $versionName . ' не найдена в проекте</error>');
                return 1;
            } else {
                $message = $versionData['name'];

                if(!empty($versionData['description'])) {
                    $message .= ' - "'.$versionData['description'].'"';
                }

                $message .= ' [id: '.$versionData['id'];

                if(!empty($versionData['released'])) {
                    $message .= '; Released';
                }

                $message .= '; releaseDate: '.(empty($versionData['releaseDate'])? 'EMPTY' : $versionData['releaseDate']);

                $message .= ']';

                $output->writeln('<info>Найдена: '.$message.'</info>');
            }

            $qaVersionIssueReleaseDate = $qaVersionIssue['fields'][$this->config['jira.fields.qa_version_issue_release_date']];
            $projectVersionReleaseDate = $versionData['releaseDate'];

            if ($qaVersionIssueReleaseDate !== $projectVersionReleaseDate) {
                $output->writeln(
                    "<comment>Смена даты релиза для версии с " . $projectVersionReleaseDate . " на " . $qaVersionIssueReleaseDate . "</comment>"
                );

                $this->getJiraClient()->editVersion(
                    $versionData['id'],
                    null,
                    null,
                    $qaVersionIssueReleaseDate
                );
            }

            $issueStatus = $qaVersionIssue['fields']['status']['name'];
            if (!$versionData['released'] && 'Released' === $issueStatus) {
                $output->writeln(
                    "Похоже, что есть невыпущенная версия компонента '" . $versionName . "' и Released QA тикет '" . $qaVersionIssue['key'] . "'"
                );

                $output->writeln(
                    '<info>Выполнение команды:</info> release <comment>' . $versionName . '</comment>'
                );

                $args = array(
                    'command' => 'release',
                    'component' => $component,
                    'tag' => $tag,
                );

                $releaseCommandInput = new ArrayInput($args);
                if (!$this->lockComponent($component, true)) {
                    $output->writeln("<error>Не удалось разблокировать компонет {$component}</error>");
                }
                $releaseCommand = $this->getApplication()->find('release');
                $returnCode = $releaseCommand->run($releaseCommandInput, $output);
                if ($returnCode) {
                    $output->writeln('<error>ошибка при выполнении</error>');
                    return 1;
                }
            }
        } else {
            $output->writeln('<error>ОШИБКА</error>');
            return 1;
        }
        if (!$this->lockComponent($component, true)) {
            $output->writeln("<error>Не удалось разблокировать компонет {$component}</error>");
        }
        $output->writeln('<info>Версия в проекте синхронизирована с QA тикетом</info>');
        return 0;
    }
}