<?php
/**
 * PHPCI - Continuous Integration for PHP
 *
 * @copyright    Copyright 2014, Block 8 Limited.
 * @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
 * @link         https://www.phptesting.org/
 */

namespace PHPCensor\Model;

use PHPCensor\Model\Base\ProjectBase;
use PHPCensor\Model\Build;
use b8\Store;

/**
* Project Model
* @uses         PHPCensor\Model\Base\ProjectBase
* @author       Dan Cryer <dan@block8.co.uk>
* @package      PHPCI
* @subpackage   Core
*/
class Project extends ProjectBase
{
    /**
     * Return the latest build from a specific branch, of a specific status, for this project.
     * @param string $branch
     * @param null $status
     * @return mixed|null
     */
    public function getLatestBuild($branch = 'master', $status = null)
    {
        $criteria = ['branch' => $branch, 'project_id' => $this->getId()];

        if (isset($status)) {
            $criteria['status'] = $status;
        }

        $order  = ['id' => 'DESC'];
        $builds = Store\Factory::getStore('Build')->getWhere($criteria, 1, 0, [], $order);

        if (is_array($builds['items']) && count($builds['items'])) {
            $latest = array_shift($builds['items']);

            if (isset($latest) && $latest instanceof Build) {
                return $latest;
            }
        }

        return null;
    }

    /**
     * Return the previous build from a specific branch, for this project.
     * @param string $branch
     * @return mixed|null
     */
    public function getPreviousBuild($branch = 'master')
    {
        $criteria = ['branch' => $branch, 'project_id' => $this->getId()];
        $order    = ['id' => 'DESC'];
        $builds   = Store\Factory::getStore('Build')->getWhere($criteria, 1, 1, [], $order);

        if (is_array($builds['items']) && count($builds['items'])) {
            $previous = array_shift($builds['items']);

            if (isset($previous) && $previous instanceof Build) {
                return $previous;
            }
        }

        return null;
    }

    /**
     * Store this project's access_information data
     * @param string|array $value
     */
    public function setAccessInformation($value)
    {
        if (is_array($value)) {
            $value = json_encode($value);
        }

        parent::setAccessInformation($value);
    }

    /**
     * Get this project's access_information data. Pass a specific key or null for all data.
     * @param string|null $key
     * @return mixed|null|string
     */
    public function getAccessInformation($key = null)
    {
        $info = $this->data['access_information'];

        // Handle old-format (serialized) access information first:
        if (!empty($info) && !in_array(substr($info, 0, 1), ['{', '['])) {
            $data = unserialize($info);
        } else {
            $data = json_decode($info, true);
        }

        if (is_null($key)) {
            $rtn = $data;
        } elseif (isset($data[$key])) {
            $rtn = $data[$key];
        } else {
            $rtn = null;
        }

        return $rtn;
    }

    /**
     * Get the value of Branch / branch.
     *
     * @return string
     */
    public function getBranch()
    {
        if (empty($this->data['branch'])) {
            return $this->getType() === 'hg' ? 'default' : 'master';
        } else {
            return $this->data['branch'];
        }
    }

    /**
     * Return the name of a FontAwesome icon to represent this project, depending on its type.
     * @return string
     */
    public function getIcon()
    {
        switch ($this->getType()) {
            case 'github':
                $icon = 'github';
                break;

            case 'bitbucket':
                $icon = 'bitbucket';
                break;

            case 'remote':
            case 'gitlab':
            default:
                $icon = 'code-fork';
                break;
        }

        return $icon;
    }
}
