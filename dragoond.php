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
 * External libraries. 
 **/
require_once('DB.php');
require_once('Log.php');
require_once('aphp/aphp.php');
require_once('spyc/spyc.php');

/**
 * Dragoond libs.
 **/
require_once('lib/daemonize.class.php');

// Load core classes.
foreach(glob('libs/core/*.class.php') as $filename)
{
    require($filename);
}

/**
 *
 * @package Dragoond
 * @author OwlManAtt <owlmanatt@gmail.com>
 * @copyright 2007, Yasashii Syndicate
 * @version: Release: @package_version@
 **/
class Dragoond extends Daemonize
{
    protected $db = null;
    protected $dragoon_name = 'Uninitialized Dragoon';
    protected $debug_level = 2;
    protected $log = null;
    private $log_dir = '/tmp/dragoond/log';
    private $module_dir = null;
    
    public function __construct($config)
    {
        parent::__construct();

        // This is a temporary logger that will be used to log to the console until we can fork
        // off the real daemon. This is used for reporting configuration problems that prevent
        // correct initialization of the Dragoon.
        $this->log = Log::singleton('console','',$this->dragoon_name,array('timeFormat' => '%Y-%m-%d %H:%M:%S')); 

        $this->logMessage("Reading configuration '$config'...",'debug');
        if($this->configure($config) == false)
        {
            $this->logMessage("Could not configure dragoon. Please check config.",'emergency'); 
        }
        else
        {
            $this->logMessage("{$this->dragoon_name} configured.",'debug');
        }
    } // end __construct

    public function start()
    {
        $composite_log = Log::singleton('composite'); 
        $console_log = Log::singleton('console','',$this->dragoon_name,array('timeFormat' => '%Y-%m-%d %H:%M:%S')); 
        $file_log = Log::singleton('file',"{$this->log_dir}/dragoon.log",$this->dragoon_name,array('timeFormat' => '%Y-%m-%d %H:%M:%S'));

        if($this->debug == 0)
        {
            $file_log->setMask((PEAR_LOG_ALL ^ Log::MASK(PEAR_LOG_INFO)));
        }
        elseif($this->debug == 1)
        {
            $file_log->setMask((PEAR_LOG_ALL ^ Log::MASK(PEAR_LOG_DEBUG)));
        }
        elseif($this->debug >= 2)
        {
            $file_log->setMask(PEAR_LOG_ALL); 
        }
       
        // The console logger will just write out emergency messages. 
        $console_log->setMask(PEAR_LOG_NONE ^ Log::MASK(PEAR_LOG_EMERG));
        $composite_log->addChild($console_log);
        $composite_log->addChild($file_log);
        $this->log = $composite_log;
        
        $this->logMessage("Advanced logging set up; moving to initalize daemon.",'debug');

        return parent::start();
    } // end start

    protected function configure($file)
    {
        $return = true;

        $config = Spyc::YAMLLoad($file);

        try
        {
            $db = $this->dbconnect($config['database_dsn']);
            $this->db = $db;
        }
        catch(SQLError $e)
        {
            $this->logMessage('Could not establish database connection.','critical');
            $this->logMessage($e->__toString(),'debug');

            return false; 
        } // end db connection failure
        
        // Daemon settings.
        $this->userID = $config['uid'];
        $this->groupID = $config['gid'];
        
        // Dragoon settings.
        $this->dragoon_name = $config['name'];
        $this->debug_level = $config['debug'];
        $this->homePath = $config['home_dir'];
        $this->log_dir = $config['log_dir'];
        $this->module_dir = $config['module_dir'];

        return $return;
    } // end configure 

    /**
     * A wrapper for creating a PEAR::DB connection instance.
     *
     * @param array PEAR::DB DSN
     * @throws SQLError
     * @return object PEAR::DB connection
     **/
    protected function dbconnect($DSN)
    {
        $db = DB::connect($DSN,array('debug' => 2,'portability' => DB_PORTABILITY_ALL));

        if(PEAR::isError($db))
        {
            throw new SQLError($db->getDebugInfo(),null);
        } // end iserror

        $db->setFetchMode(DB_FETCHMODE_ASSOC);

        return $db;
    } // end dbconnect

    // Magic goes here.
    protected function doTask()
    {
        $this->db->query('SHOW TABLES');
        sleep(10);
    } // end doTask
   
    protected function logMessage($msg,$level='notice')
    {
        // Friendly names are friendly~
        $PEAR_LEVELS = array(
            'debug' => PEAR_LOG_DEBUG,
            'info' => PEAR_LOG_INFO,
            'notice' => PEAR_LOG_NOTICE,
            'warning' => PEAR_LOG_WARNING,
            'error' => PEAR_LOG_ERR,
            'critical' => PEAR_LOG_CRIT,
            'alert' => PEAR_LOG_ALERT,
            'emergency' => PEAR_LOG_EMERG,
        ); // pear levels

        if(array_key_exists($level,$PEAR_LEVELS))
        {
            $level = $PEAR_LEVELS[$level];
        }
        else
        {
            $level = $PEAR_LEVELS['info'];
        }
        
        $this->log->log($msg,$level);
       
        return null; 
    } // end logMessage
    
} // end Dragoond

?>
