<?php

define ( 'ROOT_PATH', __DIR__ );


require(__DIR__ . '/vendor/autoload.php');
require_once 'vendor/autoload.php';
require_once 'lib/Helper.php';
require_once 'lib/CrucibleClient.php';
require_once 'lib/BufferedOutput.php';
//require_once 'lib/MCache.php';
require_once 'config/config.php';


use Symfony\Component\Console\Application;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Output\ConsoleOutput;
use Click2mice\Sheldon\Command\ActualizeCommand;
use Click2mice\Sheldon\Command\ActualizeDbSchemaCommand;
use Click2mice\Sheldon\Command\ActualizeNeedActualizationCommand;
use Click2mice\Sheldon\Command\ActualizeWithDependenciesCommand;
use Click2mice\Sheldon\Command\CheckRegressIssues;
use Click2mice\Sheldon\Command\DeleteMergedBranchesCommand;
use Click2mice\Sheldon\Command\IntegrateCommand;
use Click2mice\Sheldon\Command\IntegrateDbCommand;
use Click2mice\Sheldon\Command\IntegrateDbWdCommand;
use Click2mice\Sheldon\Command\KickCommand;
use Click2mice\Sheldon\Command\ReleaseQaCommand;
use Click2mice\Sheldon\Command\SyncQaVersionCommand;
use Click2mice\Sheldon\Command\MakeReleaseInstructionCommand;
use Click2mice\Sheldon\Command\CreateVersionCommand;
use Click2mice\Sheldon\Command\ReleaseCommand;
use Click2mice\Sheldon\Command\StatCommand;
use Click2mice\Sheldon\Command\TestCommand;
use Click2mice\Sheldon\Command\TestCommitCommand;
use Click2mice\Sheldon\Command\BuildCommand;
use Click2mice\Sheldon\Command\SyncCodeReviewsCommand;
use Click2mice\Sheldon\Command\DesintegrateCommand;
use Click2mice\Sheldon\Command\WriteCommentCommand;
use Click2mice\Sheldon\Helper\VersionHelper;
use Click2mice\Sheldon\Command\ActualizeQaCommand;
use Click2mice\Sheldon\Command\CreateQaCommand;
use Click2mice\Sheldon\Command\InstallCommand;

//MCache::setConfig($config);

$application = new Application( 'Process automation console tool', '1.0.0' );
$application->getHelperSet()->set( new VersionHelper() );
$application->add( new IntegrateCommand( $config ) );
$application->add( new IntegrateDbCommand( $config ) );
$application->add( new IntegrateDbWdCommand( $config ) );
$application->add( new ReleaseQaCommand( $config ) );
$application->add( new SyncQaVersionCommand( $config ) );
$application->add( new DeleteMergedBranchesCommand( $config ) );
$application->add( new ReleaseCommand( $config ) );
$application->add( new MakeReleaseInstructionCommand( $config ) );
$application->add( new ActualizeCommand( $config ) );
$application->add( new ActualizeDbSchemaCommand( $config ) );
$application->add( new ActualizeWithDependenciesCommand( $config ) );
$application->add( new TestCommand( $config ) );
$application->add( new TestCommitCommand( $config ) );
$application->add( new StatCommand( $config ) );
$application->add( new CreateVersionCommand( $config ) );
$application->add( new BuildCommand( $config ) );
$application->add( new SyncCodeReviewsCommand( $config ) );
$application->add( new DesintegrateCommand( $config ) );
$application->add( new CheckRegressIssues( $config ) );
$application->add( new KickCommand( $config ) );
$application->add( new WriteCommentCommand( $config ) );
$application->add( new ActualizeNeedActualizationCommand( $config ) );
$application->add(new ActualizeQaCommand($config));
$application->add(new CreateQaCommand($config));
$application->add(new InstallCommand($config));

$output = new ConsoleOutput();
$output->getFormatter()->setStyle( 'header', new OutputFormatterStyle('black', 'white',array()));

$application->run( null, $output );
