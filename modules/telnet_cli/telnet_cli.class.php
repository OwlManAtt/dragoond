<?php
class TelnetCli extends DragoonModule
{
    protected $module_description = 'Telnet CLI/Partial Frontend';
    protected $module_version = '1.0.0';
    protected $module_author = 'OwlManAtt <owlmanatt@gmail.com>';
    protected $module_type = 'Frontend';

    public function __construct(&$dragoon)
    {
        parent::__construct(&$dragoon);
        $this->logMessage('__construct()ing telnet frontend module...');
    } // end __construct

    public function load()
    {
        $this->logMessage('load()ing telnet frontend module...');

    } // end load
    
    public function unload()
    {
        $this->logMessage('unload()ing telnet frontend module...');
    } // end unload

} // end TelnetCli
?>
