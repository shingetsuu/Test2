<?php

namespace Click2mice\JiraClient\Clients;


abstract class Common
{
    const RESPONSE_MAX_RESULTS = 10000;

    const HTTP_OK         = 200;
    const HTTP_CREATED    = 201;
    const HTTP_NO_CONTENT = 204;

    protected $url;
    protected $username;
    protected $password;

    /** @var \GuzzleHttp\Client */
    protected $restClient;

    /** @var \SoapClient */
    protected $soapClient;
    protected $soapAuthToken;

    public function __construct( $url, $username, $password )
    {
        $this->url = $url;
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * Возвращает адрес REST API для Jira
     * @return mixed
     */
    protected function getRestUrl()
    {
        return $this->url;
    }

    /**
     * Проверяет код результата на принадлежность классу 2xx или на совпадение с заданным кодом
     * @param \GuzzleHttp\Message\ResponseInterface $result
     * @return bool
     */
    protected function checkStatusCode($result)
    {
        $status = $result->getStatusCode();
        return (bool)preg_match('/^2\d\d$/', $status);
    }

    /**
     * @return \GuzzleHttp\Client
     */
    public function getRestClient()
    {
        if (is_null($this->restClient)) {
            $this->restClient = new \GuzzleHttp\Client(
                [
                    'base_url' => $this->getRestUrl(),
                    'defaults' => [
                        'auth'    => [$this->username, $this->password],
                    ]
                ]
            );
        }

        return $this->restClient;
    }

    /**
     * Возвращает клиента для SOAP
     * @return \SoapClient
     */
    public function getSoapClient()
    {
        if ( is_null( $this->soapClient ) ) {
            $this->soapClient = new \SoapClient( "$this->url/rpc/soap/jirasoapservice-v2?wsdl" );
        }
        return $this->soapClient;
    }

    /**
     * Возвращает токен авторизации для SOAP
     * @return mixed
     */
    protected function getSoapAuthToken()
    {
        if ( is_null( $this->soapAuthToken ) ) {
            $this->soapAuthToken = $this->getSoapClient()->login( $this->username, $this->password );
        }
        return $this->soapAuthToken;
    }

    /**
     * Возвращает список проектов
     * @return mixed
     */
    abstract public function getProjects();

    /**
     * Возвращает список компонентов в проекте
     * @param $project
     * @return mixed
     */
    abstract public function getComponents( $project );

    /**
     * Возвращает список версий в проекте
     * @param $project
     * @return bool|array
     */
    abstract public function getVersions( $project );

    /**
     * Создает версию
     * @param $project
     * @param $name
     * @param $description
     * @return mixed
     */
    abstract public function createVersion($project, $name, $description = '');

    /**
     * Возвращает кол-во тикетов по версии
     * @param $versionId
     * @return bool|int
     */
    abstract public function getVersionIssuesCount( $versionId );

    /**
     * Возвращает список тикетов по результатам поиска по JQL
     * @param $jql
     * @return bool|array
     */
    abstract public function getIssuesByJql( $jql, $fields = null );

    /**
     * Возвращает список возможных переходов у тикета
     * @param $issueKey
     * @return bool|mixed
     */
    abstract public function getIssueTransitions( $issueKey );

    /**
     * Совершает переход для тикета (меняет статус)
     * @param $issueKey
     * @param $transitionId
     * @param array $fields
     * @param array $update
     * @return bool
     */
    abstract public function addIssueTransition(
        $issueKey,
        $transitionId,
        $fields = null,
        $comment = null,
        $update = null
    );

    /**
     * Совершает переход для тикета по названию перехода
     * @param $issueKey
     * @param $transitionName
     * @param array $fields
     * @param array $update
     * @return bool
     */
    abstract public function addIssueTransitionByName(
        $issueKey,
        $transitionName,
        $fields = null,
        $comment = null,
        $update = null
    );

    /**
     * Возвращает данные тикета
     * @param $issueKey
     * @return bool|mixed
     */
    abstract public function getIssue( $issueKey );

    /**
     * Меняет аттрибуты версии
     * @param $versionId
     * @param null $released
     * @param null $archived
     * @param null $releaseDate
     * @return bool
     */
    abstract public function editVersion( $versionId, $released = null, $archived = null, $releaseDate = null );

    /**
     * Добавляет комментарий к тикету
     * @param $issueKey
     * @param $comment
     * @return mixed
     */
    abstract public function addIssueComment( $issueKey, $comment );

    /**
     * Устанавливает в тикете значение поля, принимающего множество значений
     * @param $issueKey
     * @param $field
     * @param $value
     * @return mixed
     */
    abstract public function setIssueMultiFieldValue( $issueKey, $field, $values );

    /**
     * Устанавливает значение поля fixVersions для тикета
     * @param $issueKey
     * @param $versionId
     * @return mixed
     */
    abstract public function setIssueFixVersion( $issueKey, $versionId );

    /**
     * Возвращает данные пользователя
     * @param $username
     * @return mixed
     */
    abstract public function getUser( $username );

    /**
     * Добавляет зависимость к тикету
     * @param $issueKey
     * @param $linkKey
     * @param $type
     * @return bool
     */
    abstract public function addLinkToIssue($issueKey, $linkKey, $type);

    /**
     * Удаляет зависимость из тикета
     * @param $linkId
     * @return bool
     */
    abstract public function deleteLinkFromIssue($linkId);

    /**
     * Создаёт тикет
     * @param $fields
     * @return bool|array
     */
    abstract public function createIssue($fields);

    /**
     * Возвращает инфу о компоненте
     * @param $component
     * @return bool|array
     */
    abstract public function getComponentInfo($project, $component);

    /**
     * Возвращает историю переходов
     * @param $issueKey
     * @return bool|array
     */
    abstract public function getTransitionsHistory($issueKey);
}