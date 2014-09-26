<?php
/**
 *
 * @author Mihkel Viilveer <mihkel@opus.ee>
 * @date 1.09.2014
 */

namespace opus\elastic\search;

use Elastica\Filter\Term;
use Elastica\Query\Bool;
use Elastica\Query\MatchAll;
use opus\shop\elastic\search\queryHandler\AbstractHandler;
use yii\base\Object;
use yii\di\ServiceLocator;
use yii\elasticsearch\ActiveQuery;
use yii\elasticsearch\ActiveRecord;
use yii\helpers\ArrayHelper;

/**
 * Class AbstractDataProvider
 *
 * @author Mihkel Viilveer <mihkel@opus.ee>
 * @package opus\elastic\search
 */
abstract class AbstractQueryProvider extends Object
{
    /**
     * List of handler classes
     * @var array
     */
    public $handlers = [];
    /**
     * Query instance
     *
     * @var \Elastica\Query\Bool
     */
    protected $query;

    /**
     * Filter instance
     *
     * @var \Elastica\Filter\Bool
     */
    protected $filter;

    /**
     * Search query order
     *
     * @var array
     */
    public $sort = [];

    /**
     * Search query offset
     *
     * @var null
     */
    public $offset = null;

    /**
     * Search query limit
     *
     * @var null
     */
    public $limit = null;

    /**
     * Initial request params
     *
     * @var array
     */
    protected $requestParams = [];

    /**
     * @var ServiceLocator
     */
    protected $locator;

    /**
     * Return the searchable data model
     *
     * @return ActiveRecord
     */
    abstract public function getModel();

    /**
     * Returns new hit instance
     *
     * @return Object
     */
    abstract public function getResultInstance();

    /**
     * @param array $requestParams
     * @param array $config
     */
    public function __construct($requestParams = [], $config = [])
    {
        parent::__construct($config);
        $this->locator = new ServiceLocator();
        $this->locator->setComponents($this->handlers);

        $this->query = new Bool();
        $this->filter = new \Elastica\Filter\Bool();

        $limit = ArrayHelper::getValue($requestParams, 'limit');
        $offset = ArrayHelper::getValue($requestParams, 'offset');
        $sort = ArrayHelper::getValue($requestParams, 'sort');

        unset($requestParams['limit'], $requestParams['offset'], $requestParams['sort']);
        $this->requestParams = $requestParams;

        $this->setAttributes();
        $this->limit = $limit;
        $this->offset = $offset;

        $this->sort = $sort;
    }
    /**
     * This method is used to add conditions to query
     *
     * @param string $attribute
     * @param string|array $value
     * @return $this
     */
    public function setAttribute($attribute, $value)
    {
        if ($this->locator->has($attribute)) {
            /** @var AbstractHandler $specialHandler */
            $specialHandler = $this->locator->get($attribute);
            list($this->query, $this->filter) = $specialHandler->handle([
                'query' => $this->query,
                'filter' => $this->filter,
                'value' => $value
            ]);
        } else {
            $filter = new Term(
                [$attribute => $value]);
            $this->filter->addMust($filter);
        }
        return $this;
    }

    /**
     * Mass sets attributes to query
     */
    protected function setAttributes()
    {
        foreach ($this->requestParams as $field => $value) {
            $this->setAttribute($field, $value);
        }
    }

    /**
     * @param bool $multiSearch
     * @return ActiveQuery|array
     */
    public function getQuery($multiSearch = true)
    {
        $this->query = $this->query == new Bool()
            ? new MatchAll() : $this->query;

        $this->filter = $this->filter == new \Elastica\Filter\Bool()
            ? new \Elastica\Filter\MatchAll() : $this->filter;

        $query = $this->getModel()->find()
            ->query($this->query)
            ->where($this->filter)
            ->limit(null)
            ->offset(null)
            ->orderBy(null);

        if ($multiSearch === true) {
            $query = [
                'query' => [
                    'filtered' => $query->createCommand()->queryParts,
                ],
                'from' => $this->offset,
                'size' => $this->limit,
            ];
            if (!is_null($this->sort)) {
                $query['sort'] = $this->sort;
            }
            return $query;
        }

        return $query
            ->limit($this->limit)
            ->offset($this->offset)
            ->orderBy($this->sort);
    }
}