<?php
class DatasourceFetcher extends DragoonModule
{
    protected $module_description = 'Datasource Fetcher';
    protected $module_version = '1.0.0';
    protected $module_author = 'OwlManAtt <owlmanatt@gmail.com>';
    protected $module_type = 'Datasource Handler';
    
    public function __construct(&$dragoon,&$db)
	{
        parent::__construct(&$dragoon,&$db);

        $this->logMessage("Constructing datasource fetcher module."); 
	} // end __construct

    public function load()
    {
        $this->logMessage('load()ing datasource fetcher module...');
        $this->dragoon->registerRunMethod('datasource_fetcher','fetchUpdates');

        return true;
    } // end load

    public function fetchUpdates()
    {
        // $this->logMessage('Running fetchUpdates...','debug'); // FOO
        
        // Find any datasources that are up for a run:
        $resource = $this->db->query("
            SELECT 
                datasource_id
            FROM datasource 
            WHERE (UNIX_TIMESTAMP(datetime_last_fetch) + fetch_frequency_secs) <= UNIX_TIMESTAMP(NOW());
        ");

        // If something is wrong DON'T CRASH THE DRAGOON!
        if(PEAR::isError($resource))
        {
            $this->logMessage("Could not run datasource query: {$resource->getDebugInfo()}",'notice');

            return false;
        } // end isError
        
        while($resource->fetchInto($ROW))
        {
            $this->logMessage("Running source {$ROW['datasource_id']}",'debug'); // FOO

            $source = new Datasource($this->db);
            $source->load($ROW['datasource_id']);

            $filename = "cache/{$source->getDatasourceId()}.{$source->getDatatype()}";
            if(file_exists($filename) == false)
            {
                $this->logMessage("File $filename does not exist; creating.",'debug');
                if(touch($filename) == false)
                {
                    $this->logMessage("Could not create $filename.",'notice');
                    continue;
                } // end touch
            } // end create file
            
            $file_handle = @fopen($filename,'w');

            // If we can't open this for write, the perms on this one file
            // might just be fucked - move on. 
            if($file_handle == false)
            {
                $this->logMessage("Could not open $filename for {$source->getDatasourceId()}.",'notice');
                continue;
            } // end cannot open
          
            // Open the remote resource and slam it into the cache'd file. 
            $url_handle = fopen($source->getDatasourceUrl(),'r');
            
            if(fwrite($file_handle,stream_get_contents($url_handle)) == false)
            {
                $this->logMessage("Error writing to cache $filename.",'notice');
            }

            fclose($url_handle);
            fclose($file_handle);
           
            // TODO - Send the newly-GET'd resource to its handler.
            $datasource->setDatetimeLastFetch($datasource->sysdate()); 
            $datasource->save();
        } // end loop
    } // end fetchUpdates
    
    public function unload()
    {
        $this->logMessage('Unloading datasource_fetcher....');

        return true;
    } // end unload
} // end datasource_fetcher


?>
