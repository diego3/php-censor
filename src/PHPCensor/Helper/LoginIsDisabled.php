<?php
/**
 * PHPCI - Continuous Integration for PHP
 *
 * @copyright    Copyright 2014, Block 8 Limited.
 * @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
 * @link         https://www.phptesting.org/
 */

namespace PHPCensor\Helper;

use b8\Config;

/**
* Login Is Disabled Helper - Checks if login is disabled in the view
* @author       Stephen Ball <phpci@stephen.rebelinblue.com>
* @package      PHPCI
* @subpackage   Web
*/
class LoginIsDisabled
{
    /**
     * Checks if
     * 
     * @param $method
     * @param array $params
     * 
     * @return mixed|null
     */
    public function __call($method, $params = [])
    {
        unset($method, $params);
        
        $config      = Config::getInstance();
        $disableAuth = (boolean)$config->get('php-censor.security.disable_auth', false);

        return $disableAuth;
    }
}
