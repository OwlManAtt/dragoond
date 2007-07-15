<?php
class JabberFrontend extends DragoonModule
{
    protected $module_description = 'Jabber Frontend';
    protected $module_version = '1.0.0';
    protected $module_author = 'OwlManAtt <owlmanatt@gmail.com>';
    protected $module_type = 'Frontend';

    public function __construct()
    {
        $this->logMessage('__construct()ing jabber frontend module...');
    } // end __construct

    public function load()
    {
        $this->logMessage('load()ing jabber frontend module...');
    } // end load
    
    public function unload()
    {
        $this->logMessage('unload()ing jabber frontend module...');
    } // end unload
    
} // end JabberFrontend

?>
