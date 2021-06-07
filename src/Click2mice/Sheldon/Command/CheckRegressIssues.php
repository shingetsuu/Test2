<?php

namespace Click2mice\Sheldon\Command;

use Symfony\Component\Console\Helper\DialogHelper;
use Symfony\Component\Console\Helper\ProgressHelper;
use Symfony\Component\Console\Helper\TableHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Click2mice\Sheldon\Helper\VersionHelper;

class CheckRegressIssues extends ProcessCommand
{

    protected $isLockEnabled = false;

    const CACHE_KEY_OPENED = 'proccess_automation_check_regress_issues_opened';
    const CACHE_KEY_RFT = 'proccess_automation_check_regress_issues_rft';

    protected function configure()
    {
        $this->setName('check-regress-issues');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln("Получение тикетов, найденных на регрессе, но не взятых в разработку\n");
        $issues = $this->findOpenedIssues();
        $this->processIssues($output, $issues, self::CACHE_KEY_OPENED, function($issue){
                $componentName = '';
                if (!empty($issue['fields']['components'])) {
                    foreach ($issue['fields']['components'] as $component) {
                        $componentName .= $component['name'];
                    }
                }
                return sprintf(
                    "Баг %s *%s %s*, найденный на регрессе, находится в статусе %s. Исполнитель: %s.\n%s\n",
                    $componentName,
                    $issue['key'],
                    $issue['fields']['summary'],
                    $issue['fields']['status']['name'],
                    $this->getNameWithSlackMention($issue['fields']['assignee']),
                    $this->config['jira.url'] .'/browse/' . $issue['key']
                );
            });

        $issues = $this->findIssuesRFT();
        $this->processIssues($output, $issues, self::CACHE_KEY_RFT, function($issue){
                $componentName = '';
                if (!empty($issue['fields']['components'])) {
                    foreach ($issue['fields']['components'] as $component) {
                        $componentName .= $component['name'];
                    }
                }
                $qaEngineer = $issue['fields']['assignee'];
                if ($qaEngineer['name'] == 'qa.backlog' && !empty($issue['fields']['reporter']['name'])) {
                    $qaEngineer = $issue['fields']['reporter'];
                }
                return sprintf(
                    "Баг %s *%s %s*, найденный на регрессе, готов к тестированию. Исполнитель: %s.\n%s\n",
                    $componentName,
                    $issue['key'],
                    $issue['fields']['summary'],
                    $this->getNameWithSlackMention($qaEngineer),
                    $this->config['jira.url'] .'/browse/' . $issue['key']
                );
            });
    }

    protected function processIssues(OutputInterface $output, $issues, $cacheKey, $messageCallback)
    {
        $count = count($issues['issues']);
        $output->writeln("Найдено тикетов: <info>{$count}</info>\n");
        if ($count < 1) {
            return;
        }
        $cachedIssues = \MCache::getInstance()->get($cacheKey);
        if (!$cachedIssues) {
            $cachedIssues = '';
        }
        $output->writeln("<info>В кеше найдены тикеты: {$cachedIssues}</info>\n");
        \MCache::getInstance()->delete($cacheKey);
        $cachedIssues = explode(';', $cachedIssues);
        $filteredIssues = array_filter(
            $issues['issues'],
            function ($issue) use ($cachedIssues) {
                return !in_array($issue['key'], $cachedIssues);
            }
        );

        foreach ($filteredIssues as $issue) {
            $issue = $this->getJiraClient()->getIssue($issue['key']);
            $message = $messageCallback($issue);
            $output->writeln($message);
            $this->sendSlackMessage($message);
        }
        $setIssues = [];
        foreach ($issues['issues'] as $issue) {
            $setIssues[] = $issue['key'];
        }
        \MCache::getInstance()->set($cacheKey, implode(';', $setIssues));
    }

    protected function findOpenedIssues()
    {
        $jql = '"Phase of detection"="Regression Test" AND status IN (Open, "To Do", Reopened, "Need estimation")';
        return $this->getJiraClient()->getIssuesByJql($jql);
    }

    protected function findIssuesRFT()
    {
        $jql = '"Phase of detection"="Regression Test" AND status IN ("Ready for testing.php")';
        return $this->getJiraClient()->getIssuesByJql($jql);
    }
}