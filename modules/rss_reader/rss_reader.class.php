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

        $file_path_parts = explode('/',$file_path);
        $file_name_parts = explode('.',$file_path_parts[sizeof($file_path_parts)-1]);
        $datasource_id = $file_name_parts[0];

        if(file_exists($file_path) == false)
        {
            $this->logMessage("Invalid file $file_path given.",'notice');
            return false;
        }
        
        $dom= new DomDocument();
        $dom->preserveWhiteSpace = false;
        $result = $dom->load($file_path);

        if($result== false)
        {
            $this->logMessage("RSS reader could not parse $file_path.",'notice');
            
            return false;
        } // cannot instantiate

        // Change it to SimpleXML for easy-of-use.
        $xmldoc = simplexml_import_dom($dom);
        
        if($this->dragoon->getModule('data_storage') == false)
        {
            $this->logMessage('Required module data_storage not loaded. Cannot process RSS!','notice');
            return false;
        } // end require data storage
        
        foreach($xmldoc->channel->item as $item)
        {
            // Calculate a checksum for the item.
            $checksum = md5($item->title.$item->guid.$item->link);
            if($checksum == false)
            {
                $this->logMessage('Checksum was blank. Skipping checks.','notice');
            }
            else
            {
                $exists = $this->dragoon->getModule('data_storage')->findByChecksum($checksum);
                if(sizeof($exists) > 0)
                {
                    // $this->logMessage("Item {$item->title} for datasource $datasource already recorded.",'debug');
                    continue; // Next item!
                }
            } // end checksum <> null
            
            $data = $this->dragoon->getModule('data_storage')->factory();
            $data->setDatasourceId($datasource_id);
            $data->setDatetimeAdded($data->sysdate());
            
            // Try and determine the date.
            $date = -1;
            if($item->pubDate != null)
            {
                $date = strtotime($item->pubDate);
            }
            elseif($item->date != null)
            {
                $try_date = strtotime($item->date);
                if($try_date > -1)
                {
                    $date = $try_date;
                }
                else
                {
                    // Reddit does some weird shit with a dc:date.
                    if(preg_match('/^(\d\d\d\d-\d\d-\d\d)T(\d\d:\d\d:\d\d)/',$item->date,$MATCH) == true)
                    {
                        $date = strtotime("{$MATCH[1]} {$MATCH[2]}");
                    }
                    else
                    {
                        // TODO :(
                    }
                } // end date 
            } // end pubdate
            
            $data->setDataDatetime('Y-m-d H:i:s',$date);
            $data->setDataTitle($item->title);
            $data->setDataLink($item->link);
            $data->setDataDescription($item->description);
            $data->setDataAuthor($item->author);
            $data->setDataCategory($item->category);
            $data->setChecksum($checksum);
            $data->save();
        } // end loop over rss items

        $this->logMessage("Finished handling datasource $datasource_id.",'debug');
        return true;
    } // end filehandler
    
} // end RssReader
?>
