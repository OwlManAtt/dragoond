<?php
interface DragoonModuleInterface
{
    #protected $module_description;
    #protected $module_version;
    #protected $module_author;
    #protected $module_type;

    public function load();
    public function unload();
    public function reload();

    public function getModuleInfo();
} // end DragoonModule

abstract class DragoonModule implements DragoonModuleInterface
{
    protected $module_description = 'Module stub.';
    protected $module_version = '0.0.0';
    protected $module_author = 'N/A';
    protected $module_type = 'FAKE';

    public function reload()
    {
        $this->unload();
        $this->load();
    } // end reload

    public function getModuleInfo()
    {
        return array(
            'description' => $this->module_description,
            'version' => $this->module_version,
            'author' => $this->module_author,
            'type' => $this->module_type,
            'class' => get_class($this),
        );        
    } // end getModuleInfo

    protected function logMessage($message,$level='notice')
    {
        $log = Log::singleton('composite');

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

        $log->log($message,$level);

        return null;  
    } // end logMessage
    
} // end DragoonModule

?>
