<?php

namespace Click2mice\Sheldon\Command;

use Symfony\Component\Console\Helper\DialogHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


class WriteCommentCommand extends ProcessCommand
{
    protected function configure()
    {
        $this
        ->setName('WriteComment')
        ->setDescription('Пишет коммент :[~ASSIGNEE], прошу обратить внимание на тикет')
        ->setHelp(
                'Получает тикеты с project = WD AND updatedDate <= (недели назад) AND status = "Ready For Review"' . "\n" .
                'В этих тикетах оставляет комментарий "[~ASSIGNEE], прошу обратить внимание на тикет", где ASSIGNEE - логин, на котором висит задача. Во время тестирования выводить это в консоль'  );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $weekAgo  = date('Y-m-d',strtotime('-7 days')) ;//неделю назад
        $ticketIssue = $this->getJiraClient()->getIssuesByJql(
         "project = 'WD' AND updated <= '$weekAgo' AND  status IN ('Ready For Review')");

        if (isset($ticketIssue)) {
            foreach($ticketIssue['issues'] as $ticketIssue)
            {

                $ticketIssue = $this->getJiraClient()->getIssue($ticketIssue['key']);
                $this->getJiraClient()->addIssueComment($ticketIssue['key'],'[~'.$ticketIssue['fields']['assignee']['name'].'], прошу обратить внимание на тикет.');
            }
        }

    }
}