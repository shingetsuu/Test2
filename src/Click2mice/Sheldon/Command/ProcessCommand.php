<?php

namespace Click2mice\Sheldon\Command;

use Maknz\Slack\Client as SlackClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\TableHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Click2mice\JiraClient\Clients\Common;
use Click2mice\JiraClient\Factory;
use Click2mice\Sheldon\Helper\VersionHelper;

abstract class ProcessCommand extends Command
{
    const VERSION_ALL = 'all';
    const VERSION_RELEASED = 'released';
    const VERSION_PLANNED = 'planned';
    const VERSION_NOT_RELEASED = 'not_released';

    protected $config = array();

    /** @var string */
    private $project;

    /** @var Common */
    private $jiraClient;

    /** @var \CrucibleClient */
    private $crucibleClient;

    private $lockHandle;
    private $lockFile;
    private $componentsLockDir;
    private $componentLockHandle;

    /**
     * @var SlackClient
     */
    private $slackClient;

    protected $isLockEnabled = false;

    public function __construct( $config = array() )
    {
        parent::__construct();
        $this->config = $config;
        $this->project = $config['jira.default_project'];
        $this->lockFile = ROOT_PATH . '/runtime/lock.file';
        $this->componentsLockDir = ROOT_PATH . '/' . $this->config['components.lock_dir'];
    }

    /**
     * @return Common
     */
    protected function getJiraClient()
    {
        if (is_null($this->jiraClient)) {
            $this->jiraClient = Factory::getInstance(
                '6.4.1',
                $this->config['jira.url'],
                $this->config['jira.username'],
                $this->config['jira.password']
            );
        }
        return $this->jiraClient;
    }

    /**
     * @return \CrucibleClient
     */
    protected function getCrucibleClient()
    {
        if ( is_null( $this->crucibleClient ) ) {
            $this->crucibleClient = new \CrucibleClient($this->config['crucible.url'], $this->config['crucible.username'], $this->config['crucible.password']);

        }
        return $this->crucibleClient;
    }

    /**
     * @param string|null $component
     * @return string
     */
    protected function getProject($component = null)
    {
        if (isset($component, $this->config['components'][$component]['jira.project'])) {
            return $this->config['components'][$component]['jira.project'];
        }
        return $this->project;
    }

    /**
     * Подготавливает временный клон репозитория к работе
     * @param                                                   $url
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return \GitRepo
     */
    protected function prepareRepo( $url, OutputInterface $output )
    {
        $path = ROOT_PATH . '/runtime/repo_' . md5( $url );

        try {
            $repo = \Git::open( $path );
            $output->writeln( 'Временный репозиторий существует (' . $path . ')' );
        }
        catch ( \Exception $e ) {
            $output->writeln( 'Временный репозиторий не существует' );
            $output->write( "Клонируется <comment>$url</comment> в $path... " );
            if ( file_exists( $path ) ) {
                shell_exec( 'rm -rf ' . $path );
            }
            $repo = new \GitRepo( $path, true, false );
            $repo->clone_remote($url, '');
            $output->writeln( 'done' );
        }

        try {
            $output->write( 'Переключение на master... ' );
            $repo->checkout( 'master' );
            $output->writeln( 'OK' );

            $output->write( 'Fetch repo... ' );
            $repo->fetch();
            $repo->run('fetch --tags');
            $output->writeln( 'OK' );

            $output->write( 'Pull master... ' );
            $repo->pull( 'origin', 'master' );
            $output->writeln( 'OK' );

            $output->writeln( '<info>Repo is ready for working</info>' );
        }
        catch ( \Exception $e )
        {
            $output->writeln( '<error>error</error>' );
            shell_exec( 'rm -rf ' . $path );
            $output->writeln( 'Временный репозиторий удален' );
            $output->writeln( '<info>Повторная попытка...</info>' );
            return $this->prepareRepo( $url, $output );
        }

        return $repo;
    }

    /**
     * Возвращает адрес репозитория для компонента
     * @param $component
     * @return string|null
     */
    protected function getComponentRepoUrl( $component )
    {
        if ( isset( $this->config['components'][$component] ) && isset( $this->config['components'][$component]['repo_url'] ) ) {
            return $this->config['components'][$component]['repo_url'];
        }
        return null;
    }

    /**
     * Возвращает имя версии компонента по числовой версии
     * @param $component
     * @param $numericVersion
     * @return string|null
     */
    protected function getComponentVersionName( $component, $numericVersion )
    {
        if ( isset( $this->config['components'][$component] ) && isset( $this->config['components'][$component]['version_mask'] ) ) {
            $versionName = str_replace( '%VERSION%', '%s', $this->config['components'][$component]['version_mask'] );
            return sprintf( $versionName, $numericVersion );
        }
        return null;
    }

    /**
     * Возвращает числовую версию по имени версии компонента
     * @param $component
     * @param $versionName
     * @return string|null
     */
    protected function getComponentNumericVersion( $component, $versionName )
    {
        if ( isset( $this->config['components'][$component] ) && isset( $this->config['components'][$component]['version_mask'] ) ) {
            $versionPattern = '|^' . str_replace( '%VERSION%', '([0-9\.]+)', $this->config['components'][$component]['version_mask'] ) . '$|';
            if ( preg_match( $versionPattern, $versionName, $matches )) {
                return $matches[1];
            }
        }
        return null;
    }

    /**
     * Добавляет комментарий к тикету только если последний комментарий Шелдона не тот же самый или если были совершен(ы) переход(ы)
     * @param string $issueKey
     * @param string $issueComment
     * @param bool $addDeveloperLink Добавить ссылку на разработчика
     * @return bool
     */
    protected function addUniqueIssueComment($issueKey, $issueComment, $addDeveloperLink = false)
    {
        $issueData = $this->getJiraClient()->getIssue($issueKey);
        $comments = $issueData['fields']['comment']['comments'];
        if ($comments && is_array($comments)) {
            $myLastComment = null;
            foreach ($comments as $comment) {
                if ($comment['author']['name'] == $this->config['jira.username']) {
                    if (!$myLastComment ||
                        new \DateTime($myLastComment['updated']) < new \DateTime($comment['updated'])
                    ) {
                        $myLastComment = $comment;
                    }
                }
            }

            if ($myLastComment) {
                $lastCommentLineIndependent  = str_replace("\r\n", "\n", $myLastComment['body']);
                $issueCommentLineIndependent = str_replace("\r\n", "\n", $issueComment);
                $lastTransitionTimestamp     = $this->getLastTransitionTimestamp($issueKey);
                $commentOutdated             = (new \DateTime($myLastComment['updated'])) < $lastTransitionTimestamp;

                if ($issueCommentLineIndependent === $lastCommentLineIndependent && !$commentOutdated) {
                    $issueComment = null;
                }
            }
        }
        if ($issueComment) {
            if ($addDeveloperLink && isset($issueData['fields']['customfield_10203']['key'])) {
                $developerKey = $issueData['fields']['customfield_10203']['key'];
                $issueComment = "[~$developerKey] \n" . $issueComment;
            }
            return $this->getJiraClient()->addIssueComment($issueKey, $issueComment);
        }
        return true;
    }

    /**
     * Возвращает дату и время последнего перехода
     * @param string $issueKey
     * @param string $issueComment
     * @return bool|\DateTime
     */
    protected function getLastTransitionTimestamp($issueKey)
    {
        $transitions = $this->getJiraClient()->getTransitionsHistory($issueKey);
        if (!$transitions) {
            return false;
        }
        $last = new \DateTime(array_pop($transitions)['created']);

        foreach ($transitions as $transition) {
            $timestamp = new \DateTime($transition['created']);
            if ($timestamp > $last) {
                $last = $timestamp;
            }
        }
        return $last;
    }

    /**
     * Возвращает бранч тикета
     * @param $issueKey
     * @return mixed
     */
    protected function getIssueBranch($issueKey)
    {
        $issueData = $this->getJiraClient()->getIssue( $issueKey );
        return isset($issueData['fields'][$this->config['jira.fields.git_branch']])
            ? $issueData['fields'][$this->config['jira.fields.git_branch']]
            : (strripos($issueKey, 'SI') !== false ? 'master' : '');
    }

    /**
     * Возвращает статус тикета
     * @param $issueKey
     * @return mixed
     */
    protected function getIssueStatus($issueKey)
    {
        $issueData = $this->getJiraClient()->getIssue( $issueKey );
        return $issueData['fields']['status']['name'];
    }

    /**
     * Актуализирует бранч тикета
     * @param \GitRepo $repo
     * @param string $project
     * @param $issueKey
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return bool
     */
    protected function actualizeIssueBranch(\GitRepo $repo, $project, $issueKey, OutputInterface $output)
    {
        $output->writeln( 'Обработка тикета <comment>' . $issueKey . '</comment>' );

        $issueBranch = $this->getIssueBranch($issueKey);

        if ($issueBranch == 'master') {
            $output->writeln( 'Git branch, указанный в тикете = master – ПРОПУСК' );
            return true;
        }

        $issueStatus = $this->getIssueStatus($issueKey);

        $localBranches = $repo->list_branches();
        $remoteBranches = $repo->list_remote_branches();

        if ( in_array( $issueBranch, $localBranches ) ) {
            $output->write( 'Переключение на master... ' );
            $repo->checkout( 'master' );
            $output->writeln( 'OK' );
            $output->write( 'Удаление локальной ветки тикета... ' );
            $repo->delete_branch( $issueBranch, true );
            $output->writeln( 'OK' );
        }

        if ( ! in_array( 'origin/' . $issueBranch, $remoteBranches ) ) {
            $output->writeln( '<error>Remote issue branch does not exists</error> ' );
            $this->stepBackIssue($project, $issueKey, $issueStatus, $output);
            $this->addUniqueIssueComment( $issueKey, 'Ошибка актуализации бранча тикета: указанный бранч ' . $issueBranch . ' не существует. Проверьте правильность значения поля Git branch.' );
            return false;
        }

        $output->write( 'Переключение на ' . $issueBranch . '... ' );
        $repo->checkout( $issueBranch );
        $output->writeln( 'OK' );

        try {
            $output->write( 'Мерж master в ' . $issueBranch . '... ' );
            $this->carefulMerge($repo, 'master', $output);
            $output->writeln( 'OK' );
        } catch ( \Exception $e ) {
            $output->writeln( '<error>error while merging</error>' );
            $this->stepBackIssue($project, $issueKey, $issueStatus, $output);
            $this->addUniqueIssueComment( $issueKey, 'Ошибка актуализации бранча тикета: не удалось смержить бранч master в бранч ' . $issueBranch . '. Требуется ручная актуализация.' . "\n\n" . $e->getMessage(), true);
            return false;
        }

        try {
            $output->write( 'Push ' . $issueBranch . ' to origin... ' );
            $repo->push( 'origin', $issueBranch );
            $output->writeln( 'OK' );
            $output->writeln( 'Тикет ' . $issueKey . ' успешно актуализирован' );
        } catch ( \Exception $e ) {
            $output->writeln( '<error>Ошибка при пуше в origin</error>' );
            return false;
        }

        return true;
    }

    /**
     * Откатывает статус тикета на один шаг назад в зависимости от текущего статуса
     * @param string $project
     * @param $issueKey
     * @param $issueStatus
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function stepBackIssue($project, $issueKey, $issueStatus, OutputInterface $output)
    {
        $transitionName = null;
        
        switch ($issueStatus) {
            case $this->config['commands.options']['Process'][$project . ".status"]:
                $transitionName = $this->config['commands.options']['Process'][$project . ".trans_name"];
                break;
            default:
                break;
        }

        if ($transitionName) {
            $output->write('Смена статуса тикета <comment>' . $issueKey . '</comment> на "' . $transitionName . '"... ');
            if ($this->getJiraClient()->addIssueTransitionByName($issueKey, $transitionName)) {
                $output->writeln('OK');
            } else {
                $output->writeln('<error>ошибка</error>');
            }
        }
    }

    protected function lock( OutputInterface $output )
    {
        if (!$this->isLockEnabled) {
            return;
        }
        $this->lockHandle = fopen( $this->lockFile, 'a+' );

        if($this->lockHandle) {
            $pid = posix_getpid();
            fseek($this->lockHandle, 0);
            $lockPid = (int) trim(fgets($this->lockHandle));

            if($pid !== $lockPid) {
                if ( ! flock( $this->lockHandle, LOCK_EX | LOCK_NB ) ) {
                        $output->writeln( '<comment>Пожалуйста, дождитесь окончания предыдущего процесса...</comment>' );
                        while ( ! flock( $this->lockHandle, LOCK_EX ) ) {
                            sleep( 1 );
                        }
                }

                ftruncate($this->lockHandle, 0);
                fputs($this->lockHandle, $pid);
            }
        } else {
            throw new \Exception("Can't lock file: ".$this->lockFile);
        }
    }

    protected function initialize( InputInterface $input, OutputInterface $output )
    {
        parent::initialize($input, $output);
        $this->checkConfig( $output );
        $this->lock( $output );
    }

    /**
     * Проверяет необходимые параметры в конфиге и запрашивает их, если они не заданы
     * Сохраняет введенные значения в congig.local.php
     * @param OutputInterface $output
     */
    protected function checkConfig( OutputInterface $output )
    {
        $requiredParams = array(
            'jira.url',
            'jira.username',
            'jira.password',
            'jira.default_project',
//            'crucible.url',
//            'crucible.username',
//            'crucible.password',
//            'crucible.project_table',
        );

        $requiredValues = array();
        foreach ( $requiredParams as $requiredParam ) {
            if ( empty( $this->config[$requiredParam] ) ) {
                $output->write( 'Введите значение параметра <comment>' . $requiredParam . '</comment>: ' );
                $fp = fopen("php://stdin","r");
                $requiredValues[$requiredParam] = trim(fgets($fp, 1024));
            }
        }

        if ( $requiredValues ) {
            $paramsValues = array();
            $configPath = ROOT_PATH . '/config/config.local.php';
            if ( file_exists( $configPath ) ) {
                $paramsValues = array_merge( $paramsValues, include $configPath );
            }
            $paramsValues = array_merge( $paramsValues, $requiredValues );
            $paramsCode = '';
            foreach ( $paramsValues as $param => $value ) {
                $this->config[$param] = $value;
                $paramsCode .= "\t\t'$param' => '" . addslashes( $value ) . "',\n";
            }
            $fp = fopen( $configPath, 'w+' );
            fwrite( $fp, "<?php\n" .
                "\treturn array(\n" .
                $paramsCode .
                "\t);\n"
            );
            fclose( $fp );
            $output->writeln( '<info>' . $configPath . ' обновлен</info>' );
        }

    }

    /**
     * @return VersionHelper
     */
    protected function getVersionHelper()
    {
        return $this->getHelperSet()->get( 'version' );
    }

    /**
     * @return TableHelper очищенный от заголовков и строчек хелпер
     */
    protected function getTableHelper()
    {
        /** @var TableHelper $table */
        $table = $this->getHelperSet()->get( 'table' );
        $table->setHeaders( array() );
        $table->setRows( array() );

        return $table;
    }

    /**
     * @return FormatterHelper
     */
    protected function getFormatterHelper()
    {
        return $this->getHelperSet()->get( 'formatter' );
    }

    /**
     * Проверяет наличие composer и подгружает при необходимости
     * @param \GitRepo        $repo
     * @param OutputInterface $output
     */
    protected function requireComposer(\GitRepo $repo, OutputInterface $output)
    {
        if (!file_exists($repo->get_repo_path() . '/composer.phar')) {
            $output->write('Скачивание composer.phar... ');
            shell_exec(
                'cd ' . $repo->get_repo_path() . ' && curl -sS https://getcomposer.org/installer | /usr/bin/php'
            );
            $output->writeln('OK');
        }
    }

    /**
     * Скачивает composer и собирает зависимости
     * @param \GitRepo        $repo
     * @param OutputInterface $output
     */
    protected function installComposerPackages(\GitRepo $repo, OutputInterface $output)
    {
        $this->requireComposer($repo, $output);
        $output->write('Установка пакетов composer... ');
        shell_exec('cd ' . $repo->get_repo_path() . ' && /usr/bin/php composer.phar install');
        $output->writeln('OK');
    }
    /**
     * Скачивает composer и обновляет зависимости и composer.lock
     * @param \GitRepo        $repo
     * @param OutputInterface $output
     */
    protected function updateComposerLock(\GitRepo $repo, OutputInterface $output)
    {
        $this->requireComposer($repo, $output);
        $output->write('Обновление пакетов composer... ');
        shell_exec('cd ' . $repo->get_repo_path() . ' && rm composer.lock');
        shell_exec('cd ' . $repo->get_repo_path() . ' && /usr/bin/php composer.phar update');
        $output->writeln('OK');
    }

    /**
     * Удаляет composer.phar, если таковой имеется и скачивает новую версию composer
     * @param \GitRepo        $repo
     * @param OutputInterface $output
     */
    protected function updateComposerPhar(\GitRepo $repo, OutputInterface $output)
    {
        shell_exec('cd ' . $repo->get_repo_path() . ' && rm composer.phar');
        $this->requireComposer($repo, $output);
    }

    /**
     * Готовит бранч для интеграции
     * @param \GitRepo        $repo
     * @param string          $integrateBranch
     * @param OutputInterface $output
     */
    protected function prepareIntegrateBranch(\GitRepo $repo, $integrateBranch, OutputInterface $output)
    {
        $localBranches  = $repo->list_branches();
        $remoteBranches = $repo->list_remote_branches();

        if (in_array($integrateBranch, $localBranches)) {
            $output->write('Удаление существующей локальной интеграционной ветки... ');
            $repo->delete_branch($integrateBranch, true);
            $output->writeln('OK');
        }

        if (!in_array('origin/' . $integrateBranch, $remoteBranches)) {
            $output->write('Создание новой интеграционной ветки из master... ');
            $repo->create_branch($integrateBranch);
            $output->writeln('OK');
        }

        $output->write('Переключение на ветку ' . $integrateBranch . '... ');
        $repo->checkout($integrateBranch);
        $output->writeln('OK');

        $output->write('Вмерживание master to ' . $integrateBranch . '... ');
        try {
            $this->carefulMerge($repo, 'master', $output);
        } catch (\Exception $e) {
            $output->writeln("<error>Не удалось актулизировать $integrateBranch</error>");
            $output->writeln('<error>Исключительный неуспех. Прерываю операцию.</error>');
            die(1);
        }
        $output->writeln('OK');
    }

    /**
     * Отправляет сообщение Пенни
     * @param                 $text
     * @param                 $mood
     * @param OutputInterface $output
     */
    protected function sendPennyMessage($text, $mood, OutputInterface $output)
    {
        if ($this->config['penny.enabled']) {
            $output->writeln('Отправка сообщения Penny: ' . $text);
            $urls = [];
            if (isset($this->config['penny.url'])) {
                if (is_array($this->config['penny.url'])) {
                    $urls = $this->config['penny.url'];
                } else {
                    $urls = [$this->config['penny.url']];
                }
            }
            foreach ($urls as $url) {
                $url = $url . '/api/msg';
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_VERBOSE, 1);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
                    'text' => $text,
                    'mood' => $mood
                )));
                curl_exec($ch);
                $info = curl_getinfo($ch);
                $output->writeln('Response status: ' . $info['http_code']);
            }
        }
    }

    /**
     * Отправляет сообщение в Slack
     * @param $text
     * @param string|null $channel
     */
    protected function sendSlackMessage($text, $channel = null)
    {
        if ($this->config['slack.enabled']) {
            if (!$this->slackClient) {
                $this->slackClient = new SlackClient($this->config['slack.endpoint']);
                $this->slackClient->setLinkNames(true);
                if (!empty($this->config['slack.username'])) {
                    $this->slackClient->setDefaultUsername($this->config['slack.username']);
                }
                if (!empty($this->config['slack.channel'])) {
                    $this->slackClient->setDefaultChannel($this->config['slack.channel']);
                }
                if (!empty($this->config['slack.icon'])) {
                    $this->slackClient->setDefaultIcon($this->config['slack.icon']);
                }
            }
            $message = $this->slackClient->createMessage();
            if ($channel) {
                $message->setChannel($channel);
            }
            $message->send($text);
        }
    }

    /**
     * Запускает юнит-тесты
     * @param \GitRepo        $repo
     * @param string          $phpunitCommand
     * @param string          $phpunitOutput
     * @param OutputInterface $output
     * @return bool
     */
    protected function runUnitTests(\GitRepo $repo, $phpunitCommand, &$phpunitOutput, OutputInterface $output)
    {
        exec('cd ' . $repo->get_repo_path() . ' && ' . $phpunitCommand, $phpunitOutput, $retCode);
        $phpunitOutput = implode("\n", $phpunitOutput);
        return $retCode === 0;
    }

    /**
     * Возвращает название бранча для RC
     * @param $version
     * @return string
     */
    protected function getRCBranchName($version)
    {
        return 'RC.' . $version;
    }

    /**
     * Находит версию в проекте по имени
     * @param $project
     * @param $name
     * @return null
     */
    protected function getVersion($project, $name)
    {
        foreach ($this->getJiraClient()->getVersions($project) as $version) {
            if ($version['name'] === $name) {
                return $version;
            }
        }
        return null;
    }

    /**
     * Возвращает все версии указанного компонента
     *
     * @param string $component
     * @param string $filter
     *
     * @return array
     */
    protected function getComponentVersions($component, $filter = self::VERSION_ALL)
    {
        $project = $this->getProject($component);
        $versions = $this->getJiraClient()->getVersions($project);

        $result = array();

        foreach ($versions as $version) {
            $numericVersion = $this->getComponentNumericVersion($component, $version['name']);
            if ($numericVersion) {
                $skip = false;
                switch ($filter) {
                    case self::VERSION_PLANNED:
                        if (($version['released'] || $version['archived'])) {
                            $skip = true;
                        };
                        break;
                    case self::VERSION_RELEASED:
                        if ($version['released'] == false) {
                            $skip = true;
                        }
                        break;
                    case self::VERSION_NOT_RELEASED:
                        if ($version['released']) {
                            $skip = true;
                        }
                        break;
                    default:
                        break;
                }
                if ($skip) {
                    continue;
                }
                $version['numeric'] = $numericVersion;
                $result[]           = $version;
            }
        }
        return $result;
    }

    /**
     * @param \GitRepo        $repo
     * @param string          $branch
     * @param OutputInterface $output
     * @throws \Exception
     */
    protected function carefulMerge(\GitRepo $repo, $branch, OutputInterface $output)
    {
        $current = $repo->active_branch(false);
        try {
            $repo->merge($branch);
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $rethrow      = true;
            if (strpos($errorMessage, 'Automatic merge failed; fix conflicts and then commit the result.') !== false &&
                substr_count($errorMessage, 'CONFLICT') === 1
            ) {
                $unmergedFiles = trim($repo->run("diff --name-only --diff-filter=U"));
                if ($unmergedFiles == 'composer.lock') {
                    $output->write('Обновление composer.lock... ');
                    $this->updateComposerLock($repo, $output);
                    $output->write('Коммит composer.lock... ');
                    try {
                        $repo->commit(
                            "Merge branch '{$branch}' into {$current}" . PHP_EOL . "Conflicts:" . PHP_EOL . "composer.lock"
                        );
                        $output->writeln('<info>OK</info>');
                        $rethrow = false;
                    } catch (\Exception $commitException) {
                        $output->writeln('<error>ошибка</error>');
                    }
                }
            }
            if ($rethrow) {
                $repo->run('reset --hard HEAD');
                throw $e;
            }
        }
    }

    /**
     * @param array $jiraUser Массив с информацией о пользователе из jira
     * @return string
     */
    protected function getNameWithSlackMention($jiraUser)
    {
        $result = '';
        if (isset($jiraUser['displayName'])) {
            $result = $jiraUser['displayName'];
        }
        if (isset($jiraUser['name'])) {
            $userName = $jiraUser['name'];
            if ($userName && isset($this->config['users'][$userName]['slack'])) {
                $result .= sprintf(' (@%s)', $this->config['users'][$userName]['slack']);
            }
        }
        return $result;
    }

    protected function lockComponent($component, $unclock = false)
    {
        $filename = $this->componentsLockDir . '/' . $component;
        if ($unclock) {
            if (file_exists($filename)) {
                return
                    flock($this->componentLockHandle, LOCK_UN | LOCK_NB) &&
                    fclose($this->componentLockHandle) &&
                    unlink($filename);
            }
        } else {
            $pid = posix_getpid();
            if (file_exists($filename)) {
                $this->componentLockHandle = fopen($filename, 'a+');
                if (!$this->componentLockHandle) {
                    return false;
                }
                fseek($this->componentLockHandle, 0);
                $lockPid = (int)trim(fgets($this->componentLockHandle));
                if ($lockPid === $pid) {
                    return true;
                }
                if (!flock($this->componentLockHandle, LOCK_EX | LOCK_NB)) {
                    return false;
                }
                return 
                    ftruncate($this->componentLockHandle, 0) &&
                    fputs($this->componentLockHandle, $pid) !== false;
            }

            if (!file_exists($this->componentsLockDir)) {
                mkdir($this->componentsLockDir, 0744, true);
            }

            $this->componentLockHandle = fopen($filename, 'a+');

            return
                flock($this->componentLockHandle, LOCK_EX | LOCK_NB) &&
                ftruncate($this->componentLockHandle, 0) &&
                (fputs($this->componentLockHandle, $pid) !== false);
        }
        return true;
    }

    protected function waitAndLockComponent($component, $output)
    {
        $output->writeln("Компонент <comment>{$component}</comment> заблокирован. Жду, когда освободится...");
        while (!$this->lockComponent($component)) {
            sleep(1);
        }
    }
}