<?php

class JiraClientMock extends \Click2mice\JiraClient\Clients\Common
{
    protected $fixtures = array(

    );

    public function __construct()
    {

    }

    public function getProjects()
    {
        // TODO: Implement getProjects() method.
    }

    public function getComponents($project)
    {
        // TODO: Implement getComponents() method.
    }

    /**
     * Возвращает список версий в проекте
     * @param $project
     * @return bool|array
     */
    public function getVersions($project)
    {
        // TODO: Implement getVersions() method.
    }

    /**
     * Создает версию
     * @param $project
     * @param $version
     * @return mixed
     */
    public function createVersion($project, $name, $description = '')
    {
        // TODO: Implement createVersion() method.
    }

    /**
     * Возвращает кол-во тикетов по версии
     * @param $versionId
     * @return bool|int
     */
    public function getVersionIssuesCount($versionId)
    {
        // TODO: Implement getVersionIssuesCount() method.
    }

    /**
     * Возвращает список тикетов по результатам поиска по JQL
     * @param      $jql
     * @param null $fields
     * @return bool|array
     */
    public function getIssuesByJql($jql, $fields = null)
    {
        // TODO: Implement getIssuesByJql() method.
    }

    /**
     * Возвращает список возможных переходов у тикета
     * @param $issueKey
     * @return bool|mixed
     */
    public function getIssueTransitions($issueKey)
    {
        // TODO: Implement getIssueTransitions() method.
    }

    /**
     * Совершает переход для тикета (меняет статус)
     * @param       $issueKey
     * @param       $transitionId
     * @param array $fields
     * @param null  $comment
     * @param array $update
     * @return bool
     */
    public function addIssueTransition($issueKey, $transitionId, $fields = null, $comment = null, $update = null)
    {
        // TODO: Implement addIssueTransition() method.
    }

    /**
     * Совершает переход для тикета по названию перехода
     * @param       $issueKey
     * @param       $transitionName
     * @param array $fields
     * @param null  $comment
     * @param array $update
     * @return bool
     */
    public function addIssueTransitionByName($issueKey, $transitionName, $fields = null, $comment = null, $update = null)
    {
        // TODO: Implement addIssueTransitionByName() method.
    }

    /**
     * Возвращает данные тикета
     * @param $issueKey
     * @return bool|mixed
     */
    public function getIssue($issueKey)
    {
        // TODO: Implement getIssue() method.
    }

    /**
     * Меняет аттрибуты версии
     * @param      $versionId
     * @param null $released
     * @param null $archived
     * @param null $releaseDate
     * @return bool
     */
    public function editVersion($versionId, $released = null, $archived = null, $releaseDate = null)
    {
        // TODO: Implement editVersion() method.
    }

    /**
     * Добавляет комментарий к тикету
     * @param $issueKey
     * @param $comment
     * @return mixed
     */
    public function addIssueComment($issueKey, $comment)
    {
        // TODO: Implement addIssueComment() method.
    }

    /**
     * Устанавливает в тикете значение поля, принимающего множество значений
     * @param $issueKey
     * @param $field
     * @param $value
     * @return mixed
     */
    public function setIssueMultiFieldValue($issueKey, $field, $values)
    {
        // TODO: Implement setIssueMultiFieldValue() method.
    }

    /**
     * Устанавливает значение поля fixVersions для тикета
     * @param $issueKey
     * @param $versionId
     * @return mixed
     */
    public function setIssueFixVersion($issueKey, $versionId)
    {
        // TODO: Implement setIssueFixVersion() method.
    }

    /**
     * Возвращает данные пользователя
     * @param $username
     * @return mixed
     */
    public function getUser($username)
    {
        // TODO: Implement getUser() method.
    }

    public function addLinkToIssue($issueKey, $linkKey, $type)
    {
        // TODO: Implement addLinkToIssue() method.
    }

    public function deleteLinkFromIssue($linkId)
    {
        // TODO: Implement deleteLinkFromIssue() method.
    }

    public function createIssue($fields)
    {
        // TODO: Implement createIssue() method.
    }

    public function getComponentInfo($project, $component)
    {
        // TODO: Implement getComponentInfo() method.
    }

    public function getTransitionsHistory($issueKey)
    {
        // TODO: Implement getTransitionsHistory() method.
    }

}