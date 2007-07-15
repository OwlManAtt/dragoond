<?php
/**
 * The core of the Dragoon service.
 *
 * @package Dragoond
 * @author OwlManAtt <owlmanatt@gmail.com>
 * @copyright 2007, Yasashii Syndicate
 * @version 0.0.1 dev
 **/

/**
 * 
 **/
require_once('aphp/aphp.php');
require_once('spyc/spyc.php');

require_once('lib/daemonize.class.php');

/**
 *
 * @package Dragoond
 * @author OwlManAtt <owlmanatt@gmail.com>
 * @copyright 2007, Yasashii Syndicate
 * @version: Release: @package_version@
 **/
class Dragoond extends Daemonize
{
    public function __construct($config)
    {
        parent::__construct();

        // Load & parse YAML.
        // Validate YAML.

    } // end __construct
    
    // Magic goes here.
    protected function doTask()
    {

    } // end doTask
   
    protected function logMessage($msg,$level = DLOG_NOTICE)
    {
        print $msg."\n";
    } // end logMessage
    
} // end Dragoond

$foo = new Dragoond('config/zeferis.yaml');
// $foo->start();

?>
