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
foreach(glob('lib/core/*.class.php') as $filename)
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
    protected $loaded_modules = array();
    protected $module_load_queue = array();
    protected $module_unload_queue = array();
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

    public function stop()
    {
        $this->logMessage('Dragoon received stop command.','notice');
        
        // Move module names to unload queue, fire the processor off, and then
        // do the teardown.
        $this->logMessage('Clearing module load queue & queing all loaded modules for unload...');
        $this->module_load_queue = array(); // Clear any pending modules out.
        $this->module_unload_queue = array_keys($this->loaded_modules);
        $this->handleModuleQueues();
        $this->logMessage('All modules should have been unloaded.','info');
        
        $this->logMessage('Dropping database connection...','debug');
        $this->db->disconnect();

        $this->logMessage('Escalating shutdown to daemonizer.','debug');
        parent::stop();
    } // end stop

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

        if(is_array($config['default_modules']))
        {
            $this->module_load_queue = $config['default_modules'];
        }

        return $return;
    } // end configure 

    /**
     * Get the Dragoon's name.
     *
     * @return string
     **/
    public function getDragoonName()
    {
        return $this->dragoon_name;
    } // end getDragoonName

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
        // Deal with loading/unloading modules.
        $this->handleModuleQueues();

        // Deal with the scheduled methods.
        foreach($this->loaded_modules as $module_name => $MODULE)
        {
            foreach($MODULE['registered_run_methods'] as $method)
            {
                call_user_func_array(array(&$MODULE['instance'],$method['method']),$method['args']);
            } // end method loop
        } // end module loop
        
        // Rest to give the CPU a bread. 
        sleep(2);
    } // end doTask

    public function queueModuleLoad($module_name)
    {
        $this->module_load_queue[] = $module_name;
    } // end queueModuleLoad

    public function queueModuleUnload($module_name)
    {
        $this->module_unload_queue[] = $module_name;
    } // end queueModuleUnload

    public function queueModuleReload($module_name)
    {
        $this->queueModuleUnload($module_name);
        $this->queueModuleLoad($module_name);
    } // end queueModuleReload

    private function handleModuleQueues()
    {
        if(sizeof($this->module_unload_queue) > 0)
        {
            $this->module_unload_queue = array_unique($this->module_unload_queue);
            
            foreach($this->module_unload_queue as $index => $module)
            {
                if(array_key_exists($module,$this->loaded_modules) == false)
                {
                    $this->logMessage("Module '$module' not loaded - cannot unload.",'info');
                    continue;
                }

                $this->loaded_modules[$module]['instance']->unload();
                unset($this->loaded_modules[$module]);
                unset($this->module_unload_queue[$index]);
            } // end unload loop
        } // end unload

        if(sizeof($this->module_load_queue) > 0)
        {
            foreach($this->module_load_queue as $index => $module)
            {   
                $this->module_load_queue = array_unique($this->module_load_queue);

                if(array_key_exists($module,$this->loaded_modules) == true)
                {
                    $this->logMessage("Could not load '$module' - it is already loaded.",'info');
                    continue;
                } // end module is loaded

                $module_path = "{$this->module_dir}/$module/$module.class.php";

                $this->logMessage("Module $module_path being loaded...",'debug');

                // Hack module loader - dynamically rename the class definition
                // so it's unique and eval it in. Ideally, this would use runkit - see below.
                $class = str_replace('_',null,$module);
                $class_with_suffix = $class.'__'.time();
               
                $file = ''; 
                $file = trim(`cat $module_path`);

                // Rename the class, strip shit tags.
                $file = preg_replace("/class $class extends DragoonModule/i","class $class_with_suffix extends DragoonModule",$file);
                $file = preg_replace('/^<\?php/i',null,$file);
                $file = preg_replace('/\?>$/',null,$file);

                // *gulp*
                eval($file);
                unset($file);
                
                // Runkit does not function properly. This is a desired way to load modules,
                // but it won't work. Please se PECL bug #11656 for details.
                // runkit_import($module_path,RUNKIT_IMPORT_CLASSES | RUNKIT_IMPORT_OVERRIDE);
                
                $this->logMessage("Loading class $class...");

                $php = '$module_instance = new '.$class_with_suffix.'(&$this);';
                eval($php);
                
                $this->loaded_modules[$module] = $module_instance->getModuleInfo();
                $this->loaded_modules[$module]['registered_run_methods'] = array();
                $this->loaded_modules[$module]['instance'] = $module_instance;
                if($this->loaded_modules[$module]['instance']->load() == false)
                {
                    $this->logMessage("$module could not be successfully loaded.",'notice');
                    unset($this->loaded_modules[$module]);
                }

                unset($this->module_load_queue[$index]);
            } // end load loop
        } // end load
    } // end handleModuleQueues

    public function registerRunMethod($module,$method_name,$args=array())
    {
        $this->loaded_modules[$module]['registered_run_methods'][] = array(
            'method' => $method_name,
            'args' => $args,
        );

        return true;
    } // end registerRunMethod
    
   
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
