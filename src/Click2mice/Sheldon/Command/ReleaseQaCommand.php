<?php

namespace Click2mice\Sheldon\Command;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ReleaseQaCommand extends ProcessCommand
{
    protected function configure()
    {
        $this
            ->setName('release-qa')
            ->setDescription('Помечает версию и тикеты в Jira как released')
            ->setHelp('')
            ->addArgument(
                'component',
                InputArgument::REQUIRED,
                'Название компонента (например: site или order-api)'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $component = $input->getArgument( 'component' );

        $repoUrl = $this->getComponentRepoUrl( $component );
        if ( ! $repoUrl ) {
            $output->writeln( sprintf( '<error>неизвестный компонент: %s</error>', $component ) );
            return;
        }

        $project = $this->getProject($component);

        $output->writeln( "Компонент: <comment>$component</comment>" );
        $output->writeln( "URL репозитория: <comment>$repoUrl</comment>" );

        $output->writeln( 'Поиск невыпущенных версий... ' );
        // получаем список версий
        if ( $versionList = $this->getJiraClient()->getVersions( $project ) ) {
            foreach ( $versionList as $versionItem ) {
                $numericVersion = $this->getComponentNumericVersion($component, $versionItem['name']);
                if ($numericVersion && !$versionItem['released']) {

                    $output->writeln('<info>Выполнение команды:</info> sync-qa-version <comment>'.$versionItem['name'].'</comment>');

                    $args = array(
                        'command' => 'sync-qa-version',
                        'component' => $component,
                        'version' => $numericVersion,
                    );

                    $releaseCommandInput = new ArrayInput($args);

                    $releaseCommand = $this->getApplication()->find('sync-qa-version');
                    $returnCode = $releaseCommand->run($releaseCommandInput, $output);
                    if($returnCode) {
                        $output->writeln( '<error>execution failed</error>' );
                    }
                }
            }
        }
        else {
            $output->writeln( '<error>ОШИБКА</error>' );
            return;
        }
    }
}