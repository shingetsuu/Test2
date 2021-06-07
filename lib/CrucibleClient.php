<?php

class CrucibleClient
{
    const REVIEW_ACTION_APPROVE = 'action:approveReview';
    const REVIEW_ACTION_CLOSE = 'action:closeReview';
    const REVIEW_ACTION_REOPEN = 'action:reopenReview';

    const REVIEW_STATE_REVIEW = 'Review';
    const REVIEW_STATE_DRAFT = 'Draft';
    const REVIEW_STATE_CLOSED = 'Closed';

    protected $url;
    protected $username;
    protected $password;

    public function __construct( $url, $username, $password )
    {
        $this->url = $url;
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * Делает http-запрос и возвращает результат и информацию о запросе в виде массива
     * @access  protected
     * @param   string $url        Адрес запроса
     * @param   string $method     Метод
     * @param   array  $headers    Заголовки запроса
     * @param   mixed  $postFields Тело POST-запроса
     * @return  array
     */
    protected function httpRequest($url, $method, $headers, $postFields = null)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method == "POST") {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        }
        $result = curl_exec($ch);
        $info   = curl_getinfo($ch);
        $arr    = array('result' => $result, 'info' => $info);

        curl_close($ch);
        return $arr;
    }

    protected function getHeaders()
    {
        return [
            'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password),
            'Content-Type: application/json',
            'Accept: application/json'
        ];
    }

    /**
     * Возвращает всех пользователей
     * @return bool|mixed
     */
    public function getUsers()
    {
        $result = $this->httpRequest("$this->url/rest-service/users-v1", "GET", $this->getHeaders());
        if ($result['info']['http_code'] == 200) {
            return json_decode($result['result'], true);
        }
        else {
            return false;
        }
    }

    /**
     * Создает ревью
     * @param      $project
     * @param      $name
     * @param      $author
     * @param      $creator
     * @param      $moderator
     * @param null $jiraIssueKey
     * @return bool|mixed
     */
    public function createReview($project, $name, $author, $creator, $moderator, $jiraIssueKey = null)
    {
        $data = array(
            'reviewData' => array(
                'projectKey' => $project,
                'name' => $name,
                'author' => array(
                    'userName' => $author
                ),
                'creator' => array(
                    'userName' => $creator
                ),
                'moderator' => array(
                    'userName' => $moderator
                ),
                'type' => 'REVIEW',
                'allowReviewersToJoin' => true,
                'jiraIssueKey' => $jiraIssueKey
            )
        );
        $result = $this->httpRequest(
            "$this->url/rest-service/reviews-v1",
            "POST",
            $this->getHeaders(),
            json_encode($data)
        );
        if ($result['info']['http_code'] == 201) {
            return json_decode($result['result'], true);
        } else {
            return false;
        }
    }

    /**
     * Добавляет ревьюверов в ревью
     * @param $reviewKey
     * @param string $reviewers Список ревьюверов через запятую
     * @return bool
     */
    public function addReviewReviewers($reviewKey, $reviewers)
    {
        $result = $this->httpRequest(
            "$this->url/rest-service/reviews-v1/$reviewKey/reviewers",
            "POST",
            $this->getHeaders(),
            $reviewers
        );
        return $result['info']['http_code'] == 204;
    }

    /**
     * Возвращает возможные переходы для ревью
     * @param $reviewKey
     * @return bool|mixed
     */
    public function getReviewTransitions($reviewKey)
    {
        $result = $this->httpRequest(
            "$this->url/rest-service/reviews-v1/$reviewKey/transitions",
            "GET",
            $this->getHeaders()
        );
        if ($result['info']['http_code'] == 200) {
            return json_decode($result['result'], true);
        } else {
            return false;
        }
    }

    /**
     * Совершает переход для ревью
     * @param $reviewKey
     * @param $action
     * @return bool
     */
    public function postReviewTransition($reviewKey, $action)
    {
        $result = $this->httpRequest(
            "$this->url/rest-service/reviews-v1/$reviewKey/transition?action=$action",
            "POST",
            $this->getHeaders(),
            ""
        );
        return $result['info']['http_code'] == 200;
    }

    /**
     * Стартует ревью
     * @param $reviewKey
     * @return bool
     */
    public function approveReview($reviewKey)
    {
        return $this->postReviewTransition($reviewKey, self::REVIEW_ACTION_APPROVE);
    }

    /**
     * Переоткрывает ревью
     * @param $reviewKey
     * @return bool
     */
    public function reopenReview($reviewKey)
    {
        return $this->postReviewTransition($reviewKey, self::REVIEW_ACTION_REOPEN);
    }

    /**
     * Добавляет коммиты в ревью
     * @param       $reviewKey
     * @param       $repository
     * @param array $changesets Массив хэшей коммитов
     * @return bool|mixed
     */
    public function addReviewChangesets($reviewKey, $repository, array $changesets)
    {
        $data = array(
            'repository' => $repository,
        );

        foreach ($changesets as $changeset) {
            $data['changesets']['changesetData'][] = array('id' => $changeset);
        }
        $result = $this->httpRequest(
            "$this->url/rest-service/reviews-v1/$reviewKey/addChangeset",
            "POST",
            $this->getHeaders(),
            json_encode($data)
        );
        return $result['info']['http_code'] == 200;
    }

    /**
     * Возвращает список ревью по фильтру
     * https://docs.atlassian.com/fisheye-crucible/latest/wadl/crucible.html#d2e591
     * @param array $filter
     * @return bool|mixed
     */
    public function getReviews(array $filter = array())
    {
        $result = $this->httpRequest(
            (empty($filter)) ?
                "$this->url/rest-service/reviews-v1/filter" :
                "$this->url/rest-service/reviews-v1/filter?" . http_build_query($filter),
            "GET",
            $this->getHeaders()
        );
        if ($result['info']['http_code'] == 200) {
            return json_decode( $result['result'], true );
        }
        else {
            return false;
        }
    }

    /**
     * Возвращает ревью
     * @param $reviewKey
     * @param bool|null $detailed
     * @return bool|mixed
     */
    public function getReview($reviewKey, $detailed = false)
    {
        $result = $this->httpRequest(
            "$this->url/rest-service/reviews-v1/$reviewKey" . ($detailed ? "/details" : ''),
            "GET",
            $this->getHeaders()
        );
        if ($result['info']['http_code'] == 200) {
            return json_decode( $result['result'], true );
        }
        else {
            return false;
        }
    }

}