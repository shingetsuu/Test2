<?php

class ProcessCommandMock extends \Wikimart\Sheldon\Command\ProcessCommand
{
    /** @var JiraClientMock */
    protected $jiraClientMock;

    protected function configure()
    {
        parent::configure();
        $this->setName('mock');
    }

    protected function getJiraClient()
    {
        if ( is_null( $this->jiraClientMock ) ) {
            $this->jiraClientMock = new JiraClientMock();

        }
        return $this->jiraClientMock;
    }

    public function getComponentVersionName($component, $numericVersion)
    {
        return parent::getComponentVersionName($component, $numericVersion);
    }

    public function getComponentNumericVersion($component, $versionName)
    {
        return parent::getComponentNumericVersion($component, $versionName);
    }

}