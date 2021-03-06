<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2012 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 * @package   Zend_Db
 */

namespace Zend\Db\Adapter\Driver\Mysqli;

use Zend\Db\Adapter\Driver\StatementInterface;
use Zend\Db\Adapter\Exception;
use Zend\Db\Adapter\ParameterContainer;

/**
 * @category   Zend
 * @package    Zend_Db
 * @subpackage Adapter
 */
class Statement implements StatementInterface
{

    /**
     * @var \mysqli
     */
    protected $mysqli = null;

    /**
     * @var Mysqli
     */
    protected $driver = null;

    /**
     * @var string
     */
    protected $sql = '';

    /**
     * Parameter container
     *
     * @var ParameterContainer
     */
    protected $parameterContainer = null;

    /**
     * @var \mysqli_stmt
     */
    protected $resource = null;

    /**
     * Is prepared
     *
     * @var boolean
     */
    protected $isPrepared = false;

    /**
     * @var bool
     */
    protected $bufferResults = false;

    /**
     * @param  bool $bufferResults
     */
    public function __construct($bufferResults = false)
    {
        $this->bufferResults = (bool) $bufferResults;
    }

    /**
     * Set driver
     *
     * @param  Mysqli $driver
     * @return Statement
     */
    public function setDriver(Mysqli $driver)
    {
        $this->driver = $driver;
        return $this;
    }

    /**
     * Initialize
     *
     * @param  \mysqli $mysqli
     * @return Statement
     */
    public function initialize(\mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
        return $this;
    }

    /**
     * Set sql
     *
     * @param  string $sql
     * @return Statement
     */
    public function setSql($sql)
    {
        $this->sql = $sql;
        return $this;
    }

    /**
     * Set Parameter container
     *
     * @param ParameterContainer $parameterContainer
     */
    public function setParameterContainer(ParameterContainer $parameterContainer)
    {
        $this->parameterContainer = $parameterContainer;
    }

    /**
     * Get resource
     *
     * @return mixed
     */
    public function getResource()
    {
        return $this->resource;
    }

    /**
     * Set resource
     *
     * @param  \mysqli_stmt $mysqliStatement
     * @return Statement
     */
    public function setResource(\mysqli_stmt $mysqliStatement)
    {
        $this->resource = $mysqliStatement;
        $this->isPrepared = true;
        return $this;
    }

    /**
     * Get sql
     *
     * @return string
     */
    public function getSql()
    {
        return $this->sql;
    }

    /**
     * @return ParameterContainer
     */
    public function getParameterContainer()
    {
        return $this->parameterContainer;
    }

    /**
     * @return bool
     */
    public function isPrepared()
    {
        return $this->isPrepared;
    }

    /**
     * @param string $sql
     */
    public function prepare($sql = null)
    {
        if ($this->isPrepared) {
            throw new Exception\RuntimeException('This statement has already been prepared');
        }

        $sql = ($sql) ?: $this->sql;

        $this->resource = $this->mysqli->prepare($this->sql);
        if (!$this->resource instanceof \mysqli_stmt) {
            throw new Exception\InvalidQueryException(
                'Statement couldn\'t be produced with sql: ' . $sql,
                null,
                new Exception\ErrorException($this->mysqli->error, $this->mysqli->errno)
            );
        }

        $this->isPrepared = true;
    }

    /**
     * Execute
     *
     * @param  ParameterContainer $parameters
     * @return mixed
     */
    public function execute($parameters = null)
    {
        if (!$this->isPrepared) {
            $this->prepare();
        }

        /** START Standard ParameterContainer Merging Block */
        if (!$this->parameterContainer instanceof ParameterContainer) {
            if ($parameters instanceof ParameterContainer) {
                $this->parameterContainer = $parameters;
                $parameters = null;
            } else {
                $this->parameterContainer = new ParameterContainer();
            }
        }

        if (is_array($parameters)) {
            $this->parameterContainer->setFromArray($parameters);
        }

        if ($this->parameterContainer->count() > 0) {
            $this->bindParametersFromContainer();
        }
        /** END Standard ParameterContainer Merging Block */

        if ($this->resource->execute() === false) {
            throw new Exception\RuntimeException($this->resource->error);
        }

        if ($this->bufferResults === true) {
            $this->resource->store_result();
            $this->isPrepared = false;
            $buffered = true;
        } else {
            $buffered = false;
        }

        $result = $this->driver->createResult($this->resource, $buffered);
        return $result;
    }

    /**
     * Bind parameters from container
     */
    protected function bindParametersFromContainer()
    {
        $parameters = $this->parameterContainer->getNamedArray();
        $type = '';
        $args = array();

        foreach ($parameters as $name => &$value) {
            if ($this->parameterContainer->offsetHasErrata($name)) {
                switch ($this->parameterContainer->offsetGetErrata($name)) {
                    case ParameterContainer::TYPE_DOUBLE:
                        $type .= 'd';
                        break;
                    case ParameterContainer::TYPE_NULL:
                        $value = null; // as per @see http://www.php.net/manual/en/mysqli-stmt.bind-param.php#96148
                    case ParameterContainer::TYPE_INTEGER:
                        $type .= 'i';
                        break;
                    case ParameterContainer::TYPE_STRING:
                    default:
                        $type .= 's';
                        break;
                }
            } else {
                $type .= 's';
            }
            $args[] = &$value;
        }

        if ($args) {
            array_unshift($args, $type);
            call_user_func_array(array($this->resource, 'bind_param'), $args);
        }
    }

}
