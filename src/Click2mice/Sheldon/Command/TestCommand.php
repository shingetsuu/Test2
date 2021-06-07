<?php

namespace Click2mice\Sheldon\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TestCommand extends ProcessCommand
{
    protected function configure()
    {
        $this
            ->setName('test')
            ->setDescription('Тестирует тикеты по компонентам')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln( '<comment>Тест тикетов ' . date( 'Y-m-d H:i:s' ) . '</comment>' );

        $project = $this->getProject();

        foreach ( $this->config['components'] as $component => $componentParams )
        {
            if ( isset( $componentParams['phpunit_command'] ) ) {
                if (!$this->lockComponent($component)) {
                    $this->waitAndLockComponent($component, $output);
                }
                $output->write( 'Получение тикетов компонента <comment>' . $component . '</comment> в статусе "Ready for testing.php" and QA Engineer... ' );

                $jql = "issuetype IN ('"
                    . implode("', '", $this->config['commands.options']['Test'][$project . ".issuetypes"]) .
                    "') AND component = '$component' AND project = '$project' AND status IN ('"
                    . implode("', '", $this->config['commands.options']['Test'][$project . ".statuses"]) .
                    "') AND " . $this->config['jira.jql.qa_engineer'] . " = '" . $this->config['jira.username'] . "' ORDER BY key ASC";
                $output->writeln("\n<info>[JQL] " . $jql . "</info>");

                $issues = $this->getJiraClient()->getIssuesByJql($jql);
                if ( $issues ) {
                    $output->writeln( '<comment> найдено ' . $issues['total'] . '</comment>');
                    if ( $issues['total'] )
                    {
                        $repoUrl = $componentParams['repo_url'];

                        // подготовка временного клона репозитория
                        $repo = $this->prepareRepo( $repoUrl, $output );

                        foreach ( $issues['issues'] as $issue )
                        {
                            $issueKey = $issue['key'];
                            if ($this->actualizeIssueBranch($repo, $project, $issueKey, $output)) {

                                // переходим к тестированию
                                $this->getJiraClient()->addIssueTransitionByName(
                                    $issueKey,
                                    $this->config['commands.options']['Test'][$project . ".test_trans_name"]
                                );

                                $issueBranch = $this->getIssueBranch( $issueKey );

                                $args = array(
                                    'command' => 'test-commit',
                                    'component' => $component,
                                    'commit' => $issueBranch,
                                    '--return-phpunit-output' => true,
                                );

                                $testCommitCommandInput = new ArrayInput($args);
                                $testCommitCommandOutput = new \BufferedOutput();

                                /** @var TestCommitCommand $testCommitCommand */
                                $testCommitCommand = $this->getApplication()->find($args['command']);

                                $output->writeln( 'Запуск тестов...' );

                                $returnCode = $testCommitCommand->run($testCommitCommandInput, $testCommitCommandOutput);

                                list(,$phpunitOutput) = explode("\n\n", $testCommitCommandOutput->fetch(), 2);

                                if (!$returnCode) {
                                    $output->writeln( '<info>Тикет ' . $issueKey . ' протестирован и готов к RC</info>' );
                                    $this->getJiraClient()->addIssueTransitionByName(
                                        $issueKey,
                                        $this->config['commands.options']['Test'][$project . ".complete_trans_name"]
                                    );
                                }
                                else {
                                    $output->writeln( '<error>Тикет ' . $issueKey . '  содержит ошибки</error>' );
                                    $this->getJiraClient()->addIssueTransitionByName(
                                        $issueKey,
                                        $this->config['commands.options']['Test'][$project . ".err_trans_name"]
                                    );
                                    $this->getJiraClient()->addIssueComment( $issueKey, $phpunitOutput );
                                }
                            }
                        }
                    }
                }
                else {
                    $output->writeln( '<error>ошибка</error>' );
                }
                if (!$this->lockComponent($component, true)) {
                    $output->writeln("<error>Не удалось разблокировать компонет {$component}</error>");
                }

            }
        }
    }
}