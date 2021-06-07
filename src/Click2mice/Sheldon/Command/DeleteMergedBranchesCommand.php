<?php

namespace Click2mice\Sheldon\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteMergedBranchesCommand extends ProcessCommand
{
    protected function configure()
    {
        $this
            ->setName('delete-merged-branches')
            ->setDescription( 'Удаляет смерженные бранчи из центрального репозитория' )
            ->setHelp( 'Удаляет смерженные бранчи из центрального репозитория. Параметр days-limit определяет минимальное кол-во дней с последнего коммита (по умолчанию - 15). Параметр force отключает подтверждение удаления (по умолчанию - выключен).' )
            ->addArgument(
                'component',
                InputArgument::REQUIRED,
                'Название компонента (например: site или order-api)'
            )
            ->addOption(
                'days-limit',
                'd',
                InputOption::VALUE_OPTIONAL,
                'Минимальная давность последнего коммита',
                30
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Флаг принудительного удаления без дополнительного вопроса'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $component = $input->getArgument( 'component' );

        if ($component == 'all') {
            foreach ($this->config['components'] as $component => $params) {
                if (!$this->lockComponent($component)) {
                    $this->waitAndLockComponent($component, $output);
                }
                $this->processComponent($input, $output, $component);
                if (!$this->lockComponent($component, true)) {
                    $output->writeln("<error>Не удалось разблокировать компонет {$component}</error>");
                }
            }
        }
        else {
            if (!$this->lockComponent($component)) {
                $this->waitAndLockComponent($component, $output);
            }
            $this->processComponent($input, $output, $component);
            if (!$this->lockComponent($component, true)) {
                $output->writeln("<error>Не удалось разблокировать компонет {$component}</error>");
            }
        }
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @param                 $component
     */
    protected function processComponent(InputInterface $input, OutputInterface $output, $component)
    {
        $repoUrl = $this->getComponentRepoUrl($component);
        if (!$repoUrl) {
            $output->writeln(sprintf('<error>неизвестный компонент: %s</error>', $component));
            return;
        }

        $force     = $input->getOption('force');
        $daysLimit = $input->getOption('days-limit');

        $output->writeln("Компонент: <comment>$component</comment>");
        $output->writeln("URL репозитория: <comment>$repoUrl</comment>");
        $output->writeln("Период (дни): <comment>$daysLimit</comment>");
        $output->writeln('Force: <comment>' . ($force ? 'да' : 'нет') . '</comment>');

        // подготовка временного клона репозитория
        $repo = $this->prepareRepo($repoUrl, $output);

        $repo->run('pull --prune');
        $branches = explode("\n", $repo->run('branch -r --merged'));
        $now      = mktime();
        foreach ($branches as $branch) {
            $branch = trim($branch);
            if (!empty($branch) && strpos($branch, 'origin/HEAD') !== 0 && strpos($branch, 'origin/master') !== 0) {
                $lastCommit = $repo->run('log -1 --pretty=format:"%ct" "' . $branch . '"');
                $daysOld    = floor(($now - $lastCommit) / (60 * 60 * 24));
                if ($daysOld > $daysLimit) {
                    list(,$branchName) = explode('/', $branch, 2);
                    $delete = $force;
                    if (!$delete) {
                        $issue  = null;
                        $issueInfo = 'issue n/a';
                        $issues    = $this->getJiraClient()->getIssuesByJql($this->config['jira.jql.git_branch'] . " ~ '$branchName'", 'summary,status');
                        if ($issues && $issues['total'] > 0) {
                            $issue = $issues['issues'][0];
                        }
                        if (!$issue) {
                            $issueKey = strpos($branchName, '.') === false ? $branchName : mb_substr(
                                $branchName,
                                strrpos($branchName, '.') + 1
                            );
                            $issue = $this->getJiraClient()->getIssue($issueKey);
                        }
                        if ($issue) {
                            $issueInfo = '<comment>' . $issue['key'] . '</comment>: ' . $issue['fields']['summary'] . ' (<comment>' . $issue['fields']['status']['name'] . '</comment>)';
                        }
                        $output->write(
                               'Удаление ветки <comment>' . $branch . '</comment> с последним коммитом <comment>' . $daysOld . '</comment> дней назад (' . $issueInfo . ')? (Y/n) '
                        );
                        $fp     = fopen("php://stdin", "r");
                        $answer = strtolower(rtrim(fgets($fp, 1024)));
                        if ($answer == '' || $answer == 'y') {
                            $delete = true;
                        }
                    }
                    if ($delete) {
                        $repo->run("push origin --delete '$branchName'");
                        $output->writeln('<info>Ветка "' . $branch . '" успешно удалена</info>');
                    }
                }
            }
        }
    }
}