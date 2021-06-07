<?php

namespace Click2mice\Sheldon\Command;

use Symfony\Component\Console\Helper\DialogHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Click2mice\Sheldon\Helper\VersionHelper;

class KickCommand extends ProcessCommand
{
    protected function configure()
    {
        $this
        ->setName('kick')
        ->setDescription('Выбрасывает ветки из собранного RC')
        ->setHelp(
                'Выбрасывает ветки из собранного RC, делая revert merge этой ветки в RC. В саму ветку при этом попадает RC с revert revert'
            )
        ->addArgument(
                'component',
                InputArgument::REQUIRED,
                'Компонент (например: site)'
            )
        ->addArgument(
                'version',
                InputArgument::REQUIRED,
                'Версия (например: 15083)'
            )
        ->addArgument(
                'jira-issue',
                InputArgument::REQUIRED,
                'Тикет в jira'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $version = $input->getArgument('version');
        $component = $input->getArgument('component');
        $issue = $input->getArgument('jira-issue');
        $project = $this->getProject();
        $versionName = $this->getComponentVersionName($component, $version);
        $repoUrl = $this->getComponentRepoUrl($component);
        if (!$versionName || !$repoUrl) {
            $output->writeln(sprintf('<error>Неизвестный компонент: %s</error>', $component));
            return 1;
        }

        $output->writeln("Компонент: <comment>$component</comment>");
        $output->writeln("Версия: <comment>$versionName</comment>");
        $output->writeln("URL репозитория: <comment>$repoUrl</comment>");
        if (!$this->lockComponent($component)) {
            $this->waitAndLockComponent($component, $output);
        }
        /** @var DialogHelper $dialog */
        $dialog = $this->getHelperSet()->get('dialog');
        $versionBranch = $this->getRCBranchName($version);
        $issueBranch = $this->getIssueBranch($issue);

        if ($issueBranch == 'master' || !$issueBranch) {
            $output->writeln('Git branch, указанный в тикете = master – ПРОПУСК');
            return 1;
        }

        $sameBranchIssues = $this->getJiraClient()->getIssuesByJql(
            "project = '{$project}' AND component = '{$component}' AND key != '{$issue}' AND {$this->config['jira.jql.git_branch']} ~ '{$issueBranch}'"
        );

        if ($sameBranchIssues && $sameBranchIssues['total'] > 0) {
            $sameBranchIssueKeys = array();
            foreach ($sameBranchIssues['issues'] as $sameBranchIssue) {
                $sameBranchIssueKeys[] = $sameBranchIssue['key'];
            }
            $message = "В бранче тикета $issue найдены тикеты :" . implode(
                    ', ',
                    $sameBranchIssueKeys
                ) . '. Продолжить выбрасывание?';
            if ($dialog->askConfirmation($output, $message, false)) {
                $output->writeln('Выбрасывание тикета отменено пользователем');
                return 1;
            }
        }

        $repo = $this->prepareRepo($repoUrl, $output);

        $localBranches = $repo->list_branches();
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
            $output->writeln("<error>Ветка $issueBranch не существует</error>");
            return 1;
        }

        $output->write('Выполнение Revert ' . $issueBranch . '... ');
        $this->prepareIntegrateBranch($repo, $versionBranch, $output);

        $hash = $this->findMerge($output, $repo, $versionBranch, $issueBranch);
        if (!$hash) {
            return 1;
        }
        $output->writeln('Hash: ' . $hash);

        $repo->run("revert --no-edit -m 1 $hash");
        $revertHash = $repo->run("rev-parse HEAD");
        $output->writeln('OK');
        $output->writeln('Revert Hash: ' . $revertHash);

        $output->write('Переключение на ' . $issueBranch . '... ');
        $repo->checkout($issueBranch);
        $output->writeln('OK');

        $output->write('Вмерживание ' . $versionBranch . ' в ' . $issueBranch . '... ');
        try {
            $this->carefulMerge($repo, $versionBranch, $output);
        } catch (\Exception $e) {
            $output->writeln("<error>Не удалось вмержить $versionBranch в $issueBranch</error>");
            return 1;
        }
        $output->writeln('OK');

        $output->write('Выполнение Revert Revert ' . $revertHash . '... ');

        $repo->run("revert --no-edit $revertHash");
        $output->writeln('OK');

        $output->writeln('Удаление тикетов из RC ...');

        $jql = "project = '{$project}' AND component = '{$component}' AND {$this->config['jira.jql.git_branch']} ~ '{$issueBranch}' AND status IN ('"
            . implode("', '", $this->config['commands.options']['Kick'][$project . ".statuses"]) .
            "')";
        $output->writeln("<info>[JQL] " . $jql . "</info>");

        $sameBranchIssues = $this->getJiraClient()->getIssuesByJql($jql);
        if ($sameBranchIssues && $sameBranchIssues['total'] > 0) {
            foreach ($sameBranchIssues['issues'] as $sameBranchIssue) {
                $output->write('Обработка ' . $sameBranchIssue['key'] . '... ');
                $this->getJiraClient()->addIssueTransitionByName(
                    $sameBranchIssue['key'],
                    $this->config['commands.options']['Kick'][$project . ".trans_name"]
                );
                $output->writeln('OK');
            }
        }

        $output->write('Push изменений...');
        $repo->push('origin', $issueBranch);
        $repo->push('origin', $versionBranch);
        $output->writeln('OK');
        if (!$this->lockComponent($component, true)) {
            $output->writeln("<error>Не удалось разблокировать компонет {$component}</error>");
        }
        return 0;
    }

    /**
     * @param OutputInterface $output
     * @param \GitRepo $repo
     * @param string $mainBranch
     * @param string $mergedBranch
     * @param bool $useOrigin
     * @return mixed
     */
    protected function findMerge($output, $repo, $mainBranch, $mergedBranch, $useOrigin = true)
    {
        if ($useOrigin) {
            $mergedBranch = "origin/$mergedBranch";
            $mainBranch = "origin/$mainBranch";
        }
        $ancestryPathRevs = explode(
            "\n",
            $repo->run("rev-list --ancestry-path $mergedBranch..$mainBranch")
        );
        $firstParentRevs = explode(
            "\n",
            $repo->run("rev-list --first-parent $mergedBranch..$mainBranch")
        );
        $revsIntersection = array_filter(
            array_intersect($ancestryPathRevs, $firstParentRevs)
        );
        $hash = end($revsIntersection);
        if (!$hash || !is_string($hash) || strlen($hash) !== 40 || !ctype_xdigit($hash)) {
            $output->writeln("<error>Мерж не найден</error>");
            $output->writeln($hash);
            return false;
        }
        return $hash;
    }
}