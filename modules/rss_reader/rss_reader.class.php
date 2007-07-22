<?php
class RssReader extends DragoonModule
{
    protected $module_description = 'Datasource Fetcher';
    protected $module_version = '1.0.0';
    protected $module_author = 'OwlManAtt <owlmanatt@gmail.com>';
    protected $module_type = 'Datasource Handler';
    
    public function __construct(&$dragoon,&$db)
	{
        parent::__construct(&$dragoon,&$db);

        $this->logMessage("Constructing datasource handler: RSS Reader module."); 
	} // end __construct

    public function load()
    {
        $this->logMessage('load()ing datasource handler: RSS Reader module...');

        return true;
    } // end load

    public function unload()
    {
        $this->logMessage('Unloading datasource handler: RSS Reader module....');

        return true;
    } // end unload

    public function handle($file_path)
    {
        $this->logMessage("RSS Reader is handling $file_path...",'debug');

    } // end filehandler
    
} // end RssReader
?>
