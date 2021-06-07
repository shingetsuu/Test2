<?php

namespace Click2mice\Sheldon\Command;

use Symfony\Component\Console\Helper\DialogHelper;
use Symfony\Component\Console\Helper\ProgressHelper;
use Symfony\Component\Console\Helper\TableHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Click2mice\Sheldon\Helper\VersionHelper;

class CreateVersionCommand extends ProcessCommand
{
    protected function configure()
    {
        $this->setName('create-version')
             ->addArgument(
                 'component',
                 InputArgument::REQUIRED
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $component = $input->getArgument('component');
        $issues    = $this->findReadyForRCIssues($component);
        $count     = count($issues['issues']);
        $output->writeln("\nНайдено тикетов, готовых к интеграции: <info>{$count}</info>\n");

        if ($count == 0) {
            return;
        }


        /** @var TableHelper $table */
        $table = $this->getHelperSet()->get('table');
        $table->setHeaders(array(
            'Key',
            'Summary'
        ));
        foreach ($issues['issues'] as $issue) {
            $table->addRow(array(
                $issue['key'],
                $issue['fields']['summary']
            ));
        }

        $table->render($output);
        $version = $this->getNextVersion($component, $output);

        if (is_null($version)) {
            return;
        }

        /** @var DialogHelper $dialog */
        $dialog = $this->getHelperSet()->get('dialog');

        if ($dialog->askConfirmation($output, sprintf( '<info>Вы уверены, что хотите переместить тикеты (<comment>%d</comment>) в указанную версию <comment>%s</comment>?</info> (y/n)  ', $count, $version['name']))) {
            /** @var ProgressHelper $progress */
            $progress = $this->getHelperSet()->get('progress');
            $progress->start($output, count($issues['issues']));
            foreach ($issues['issues'] as $issue) {
                $this->getJiraClient()->setIssueFixVersion($issue['key'], $version['id']);
                $progress->advance();
            }

            $progress->finish();
        }
    }

    protected function findReadyForRCIssues($component)
    {
        $jql = sprintf('component = "%s" AND status = "%s" AND fixVersion IS EMPTY', $component, 'Ready For RC');

        return $this->getJiraClient()->getIssuesByJql($jql, 'summary');
    }

    protected function getNextVersion($component, OutputInterface $output)
    {
        $availableVersions = $this->getComponentVersions($component, self::VERSION_PLANNED);

        $output->writeln('');

        $autocompleteVariants  = array();
        $latestVersion = $this->getLatestComponentVersion($component);
        $latestReleasedVersion = $this->getLatestComponentVersion($component, self::VERSION_RELEASED);
        $nextVersion           = $this->getVersionHelper()->incrementVersion($latestVersion['numeric'], VersionHelper::POS_MINOR);

        if (empty($availableVersions)) {
            $output->writeln('В JIRA не найдено связанных версий');
        }
        $table = $this->getTableHelper();
        $table->setHeaders(array(
            'Name',
            'Full Name',
            'Description',
            'Release date',
            'r',
            'a'
        ));

        $table->addRow( array(
            $latestReleasedVersion['numeric'],
            $latestReleasedVersion['name'],
           '<-- Последняя выпущенная версия',
           isset($latestReleasedVersion['userReleaseDate']) ? $latestReleasedVersion['userReleaseDate'] : ''
        ));
        $table->addRow(array('-----', '-----', '-----', '-----'));
        $table->addRow(array('', '', '', ''));

        foreach ($availableVersions as $version) {
            $table->addRow(
                array(
                    $version['numeric'],
                    $version['name'],
                    isset($version['description']) ? $version['description'] : '',
                    isset($version['userReleaseDate']) ? $version['userReleaseDate'] : '',
                    $version['released'],
                    $version['archived']
                )
            );

            $autocompleteVariants[] = $version['numeric'];
        }

        $table->addRow(array('', '', '', ''));
        $table->addRow(array('-----', '-----', '-----', '-----'));
        $table->addRow(array(
                $nextVersion,
                $this->getComponentVersionName($component, $nextVersion),
                '<-- Возможная следующая версия',
                'N/A'
            )
        );

        $autocompleteVariants[] = $nextVersion;

        $table->render($output);


        /** @var DialogHelper $dialog */
        $dialog        = $this->getHelperSet()->get('dialog');
        $versionHelper = $this->getVersionHelper();
        $output->writeln($this->getFormatterHelper()->formatBlock(array(
            'Вы можете:',
            '',
            ' - Выбрать одну из указанных выше версий',
            ' - Создать новую версию',
        ), 'header', true));
        $finalVersion = $dialog->askAndValidate($output, 'Укажите версию: ', function ($answer) use ($versionHelper, $latestVersion, $autocompleteVariants) {
            $normalized = $versionHelper->normalizeVersion($answer);
            if (!in_array($normalized, $autocompleteVariants) && version_compare($normalized, $latestVersion['numeric'], '<=')) {
                throw new \Exception("Версия должна быть не ниже, чем {$latestVersion['numeric']}");
            }

            return $normalized;

        }, false, null, $autocompleteVariants);
        $output->writeln(sprintf("Полный номер версии: <info>%s</info>", $finalVersion));
        $found = null;
        foreach ($availableVersions as $version) {
            if ($version['name'] == $this->getComponentVersionName($component, $finalVersion)) {
                $found = $version;
                break;
            }
        }

        if (is_null($found)) {
            if ($dialog->askConfirmation($output, sprintf("\n<info>Создать новую версию <comment>%s</comment>?</info>  [y/n] ", $finalVersion))) {
                $output->writeln("\nсоздается новая версия...");
                $result = $this->getJiraClient()->createVersion(
                    $this->getProject($component),
                    $this->getComponentVersionName($component, $finalVersion)
                );
                if ($result === false) {
                    $output->writeln('<error>Ошибка во время создания версии.</error>');

                    return null;
                }
                $output->writeln( sprintf("Версия <comment>%s</comment> создана. ID: <comment>%d</comment>", $result['name'], $result['id']) );
                $found = $result;
            } else {
                return null;
            }
        }

        return $found;
    }

    /**
     * Получить последнюю версию компонента
     * @param        $component
     * @param string $filter
     * @return null
     */
    protected function getLatestComponentVersion($component, $filter = self::VERSION_ALL)
    {
        $versions = $this->getComponentVersions($component, $filter);

        $maxNumericVersion = null;
        $maxVersionInfo = null;

        foreach( $versions as $version ) {
            if (version_compare($version['numeric'], $maxNumericVersion) > 0) {
                $maxNumericVersion = $version['numeric'];
                $maxVersionInfo = $version;
            }
        }

        return $maxVersionInfo;
    }

}