<?php
/**
 * PHPCI - Continuous Integration for PHP
 *
 * @copyright    Copyright 2014, Block 8 Limited.
 * @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
 * @link         https://www.phptesting.org/
 */

namespace PHPCensor\Plugin;

use PHPCensor;
use PHPCensor\Builder;
use PHPCensor\Model\Build;
use PHPCensor\Model\BuildError;
use PHPCensor\Plugin;
use PHPCensor\ZeroConfigPluginInterface;

/**
 * PHP Code Sniffer Plugin - Allows PHP Code Sniffer testing.
 * 
 * @author       Dan Cryer <dan@block8.co.uk>
 * @package      PHPCI
 * @subpackage   Plugins
 */
class PhpCodeSniffer extends Plugin implements ZeroConfigPluginInterface
{
    /**
     * @var array
     */
    protected $suffixes;

    /**
     * @var string
     */
    protected $directory;

    /**
     * @var string
     */
    protected $standard;

    /**
     * @var string
     */
    protected $tab_width;

    /**
     * @var string
     */
    protected $encoding;

    /**
     * @var int
     */
    protected $allowed_errors;

    /**
     * @var int
     */
    protected $allowed_warnings;

    /**
     * @var string, based on the assumption the root may not hold the code to be
     * tested, exteds the base path
     */
    protected $path;

    /**
     * @var array - paths to ignore
     */
    protected $ignore;

    /**
     * @return string
     */
    public static function pluginName()
    {
        return 'php_code_sniffer';
    }

    /**
     * {@inheritdoc}
     */
    public function __construct(Builder $builder, Build $build, array $options = [])
    {
        parent::__construct($builder, $build, $options);

        $this->suffixes         = ['php'];
        $this->directory        = $this->builder->buildPath;
        $this->standard         = 'PSR2';
        $this->tab_width        = '';
        $this->encoding         = '';
        $this->path             = '';
        $this->ignore           = $this->builder->ignore;
        $this->allowed_warnings = 0;
        $this->allowed_errors   = 0;

        if (isset($options['zero_config']) && $options['zero_config']) {
            $this->allowed_warnings = -1;
            $this->allowed_errors   = -1;
        }

        if (isset($options['suffixes'])) {
            $this->suffixes = (array)$options['suffixes'];
        }

        if (!empty($options['tab_width'])) {
            $this->tab_width = ' --tab-width='.$options['tab_width'];
        }

        if (!empty($options['encoding'])) {
            $this->encoding = ' --encoding=' . $options['encoding'];
        }
    }

    /**
     * Check if this plugin can be executed.
     *
     * @param $stage
     * @param Builder $builder
     * @param Build   $build
     *
     * @return bool
     */
    public static function canExecute($stage, Builder $builder, Build $build)
    {
        if ($stage == 'test') {
            return true;
        }

        return false;
    }

    /**
    * Runs PHP Code Sniffer in a specified directory, to a specified standard.
    */
    public function execute()
    {
        list($ignore, $standard, $suffixes) = $this->getFlags();

        $phpcs = $this->builder->findBinary('phpcs');

        $this->builder->logExecOutput(false);

        $cmd = $phpcs . ' --report=json %s %s %s %s %s "%s"';
        $this->builder->executeCommand(
            $cmd,
            $standard,
            $suffixes,
            $ignore,
            $this->tab_width,
            $this->encoding,
            $this->builder->buildPath . $this->path
        );

        $output = $this->builder->getLastOutput();
        list($errors, $warnings) = $this->processReport($output);

        $this->builder->logExecOutput(true);

        $success = true;
        $this->build->storeMeta('phpcs-warnings', $warnings);
        $this->build->storeMeta('phpcs-errors', $errors);

        if ($this->allowed_warnings != -1 && $warnings > $this->allowed_warnings) {
            $success = false;
        }

        if ($this->allowed_errors != -1 && $errors > $this->allowed_errors) {
            $success = false;
        }

        return $success;
    }

    /**
     * Process options and produce an arguments string for PHPCS.
     * @return array
     */
    protected function getFlags()
    {
        $ignore = '';
        if (count($this->ignore)) {
            $ignore = ' --ignore=' . implode(',', $this->ignore);
        }

        if (strpos($this->standard, '/') !== false) {
            $standard = ' --standard='.$this->directory.$this->standard;
        } else {
            $standard = ' --standard='.$this->standard;
        }

        $suffixes = '';
        if (count($this->suffixes)) {
            $suffixes = ' --extensions=' . implode(',', $this->suffixes);
        }

        return [$ignore, $standard, $suffixes];
    }

    /**
     * Process the PHPCS output report.
     * @param $output
     * @return array
     * @throws \Exception
     */
    protected function processReport($output)
    {
        $data = json_decode(trim($output), true);

        if (!is_array($data)) {
            $this->builder->log($output);
            throw new \Exception('Could not process the report generated by PHP Code Sniffer.');
        }

        $errors = $data['totals']['errors'];
        $warnings = $data['totals']['warnings'];

        foreach ($data['files'] as $fileName => $file) {
            $fileName = str_replace($this->builder->buildPath, '', $fileName);

            foreach ($file['messages'] as $message) {
                $this->build->reportError(
                    $this->builder,
                    'php_code_sniffer',
                    'PHPCS: ' . $message['message'],
                    $message['type'] == 'ERROR' ? BuildError::SEVERITY_HIGH : BuildError::SEVERITY_LOW,
                    $fileName,
                    $message['line']
                );
            }
        }

        return [$errors, $warnings];
    }
}
