<?php

namespace Click2mice\Sheldon\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DesintegrateCommand extends ProcessCommand
{
    protected function configure()
    {
        $this
            ->setName('desintegrate')
            ->setDescription('Разбирает ветку RC для указанной версии указанного компонента')
            ->setHelp('Разбирает ветку RC для указанной версии указанного компонента, а все тикеты версии переводит обратно в статус "Ready for RC"')
            ->addArgument(
            'component',
                InputArgument::REQUIRED,
                'Название компонента (например: site или order-api)'
            )
            ->addArgument(
            'version',
                InputArgument::REQUIRED,
                'Числовой номер версии (например: 13083 или 1.15.2)'
            )->addOption(
            'branch-name',
                'b',
                InputOption::VALUE_OPTIONAL,
                'Название бранча'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $version = $input->getArgument( 'version' );
        $component = $input->getArgument( 'component' );
        $project = $this->getProject();

        $versionName = $this->getComponentVersionName( $component, $version );

        $repoUrl = $this->getComponentRepoUrl( $component );
        if ( ! $versionName || ! $repoUrl ) {
            $output->writeln( sprintf( '<error>unknown component: %s</error>', $component ) );
            return;
        }

        $output->writeln( "Компонент: <comment>$component</comment>" );
        $output->writeln( "Версия: <comment>$versionName</comment>" );
        $output->writeln( "URL репозитория: <comment>$repoUrl</comment>" );

        // формируем название бранча
        if ( ! ( $integrateBranch = $input->getOption('branch-name' ) ) ) {
            $integrateBranch = $this->getRCBranchName($version);
        }
        $output->writeln( "Ветка: <comment>$integrateBranch</comment>" );

        $versionData = $this->getVersion($project, $versionName);
        if (empty($versionData)) {
            $output->writeln('<error>Версия ' . $versionName . ' не найдена в проекте</error>');
            return;
        }

        if ($versionData['released']) {
            $output->writeln('<error>Версия ' . $versionName . ' выпущена</error>');
            return;
        }
        if (!$this->lockComponent($component)) {
            $this->waitAndLockComponent($component, $output);
        }
        // подготовка временного клона репозитория
        $repo = $this->prepareRepo( $repoUrl, $output );

        $output->write( 'Получение тикетов... ' );

        $jql = "fixVersion = '$versionName' AND project = '$project' AND component = '$component' AND status IN ('"
            . implode("', '", $this->config['commands.options']['Desintegrate'][$project . ".statuses"]) .
            "') ORDER BY key ASC";
        $output->writeln("\n<info>[JQL] " . $jql . "</info>");

        $issues = $this->getJiraClient()->getIssuesByJql( $jql );
        if ( $issues )
        {
            $output->writeln( '<info> найдено ' . $issues['total'] . '</info>');
            if ( $issues['total'] )
            {
                foreach ( $issues['issues'] as $issue ) {
                    $issueKey = $issue['key'];
                    $output->write( 'Process ' . $issueKey . '... ' );
                    $this->getJiraClient()->addIssueTransitionByName(
                        $issueKey,
                        $this->config['commands.options']['Desintegrate'][$project . ".trans_name"]
                    );
                    $output->writeln( 'OK' );
                }
            }
        }
        else {
            $output->writeln( '<error>ошибка</error>' );
        }

        $output->write( 'Удаление ветки ' . $integrateBranch . ' из origin репозитория... ' );
        $repo->run('push origin :' . $integrateBranch);
        $output->writeln( 'OK' );

        $output->write( 'Перенос версии в архив... ' );
        if ( $this->getJiraClient()->editVersion( $versionData['id'], false, true ) ) {
            $output->writeln( '<info>OK</info>' );
        }
        else {
            $output->writeln( '<error>ошибка</error>' );
        }
        if (!$this->lockComponent($component, true)) {
            $output->writeln("<error>Не удалось разблокировать компонет {$component}</error>");
        }
    }

}