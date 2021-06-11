<?php

namespace Click2mice\Sheldon\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class StatCommand extends ProcessCommand
{
    protected function configure()
    {
        $this
        ->setName('stat')
        ->setDescription('Собирает статистику по тикетам')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->getProject();

        $statJql = array(
            'support_backlog' => "assignee = 'support.backlog' AND project = '$project'",
            'qa_backlog' => "assignee = 'qa.backlog' AND project = '$project'",
            'qa_verification' => "status IN ('Verification') AND project = '$project'",
            'qa_ready_for_testing' => "status IN ('Ready for testing.php', 'In testing.php') AND project = '$project' AND " . $this->config['jira.jql.qa_engineer'] . " IN ('maksim.senchuk','evgeniya.baslovyak','igor.korolev','stanislav.trubachev','anton.ohontsev','dmitriy.kulgavyiy','evgeniy.ivonin') AND issuetype in ('Development task', 'Bug report')",
            'dev_ready_for_testing' => "status IN ('Ready for testing.php', 'In testing.php') AND project = '$project' AND " . $this->config['jira.jql.qa_engineer'] . " NOT IN ('maksim.senchuk','evgeniya.baslovyak','igor.korolev','stanislav.trubachev','anton.ohontsev','dmitriy.kulgavyiy','evgeniy.ivonin') AND issuetype in ('Development task', 'Bug report')",
            'total_unresolved' => 'project = "' . $project .'" AND resolution = Unresolved AND issuetype in ("Development task", "Bug report", Research, "Markup task")',
            'total_resolved' => 'project = "' . $project .'" AND resolution != Unresolved AND issuetype in ("Development task", "Bug report", Research, "Markup task")',
        );

        $now = new \DateTime();

        $statValue = array(
            'time' => $now->format('Y-m-d H:i:s')
        );

        foreach ($statJql as $stat => $jql) {
            $issues = $this->getJiraClient()->getIssuesByJql($jql);
            $statValue[$stat] = 0;
            if ( $issues ) {
                $statValue[$stat] = $issues['total'];
            }
        }
        print implode(';', $statValue) . "\n";
    }
}