<?php

class ProcessCommandTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var ProcessCommandMock
     */
    protected $command;

    public function setUp()
    {
        $config = array(
            'jira.default_project' => 'SI',
            'components' => array(
                'test' => array(
//                    'repo_url' => 'git@git.lan:legacy-way.git',
                    'version_mask' => 'test-%VERSION%',
//                    'crucible_repo' => 'legacy-way',
//                    'phpunit_command' => '/usr/bin/php -d error_reporting=0 data/vendor/phpunit.php/phpunit.php/phpunit.php.php -c data/test/common_bootstrap.xml',
                ),
                'test2' => array()
            )
        );

        $this->command = new ProcessCommandMock($config);
    }

    public function testGetComponentVersionName()
    {
        $this->assertNull($this->command->getComponentVersionName('abc', '1.0'));
        $this->assertNull($this->command->getComponentVersionName('test2', '1.0'));
        $this->assertEquals('test-1.0', $this->command->getComponentVersionName('test', '1.0'));
    }

    public function testGetComponentNumericVersion()
    {
        $this->assertNull($this->command->getComponentNumericVersion('abc', 'abc-1.0'));
        $this->assertNull($this->command->getComponentNumericVersion('test', 'abc'));
        $this->assertNull($this->command->getComponentNumericVersion('test', 'test'));
        $this->assertNull($this->command->getComponentNumericVersion('test', 'test-test'));
        $this->assertEquals('123', $this->command->getComponentNumericVersion('test', 'test-123'));
        $this->assertEquals('1.0', $this->command->getComponentNumericVersion('test', 'test-1.0'));
    }

}