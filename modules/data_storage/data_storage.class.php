<?php
class DataStorage extends ActiveTable
{
    protected $table_name = 'data_storage';
    protected $primary_key = 'data_storage_id';

    protected $module_description = 'Datastorage Wrapper';
    protected $module_version = '1.0.0';
    protected $module_author = 'OwlManAtt <owlmanatt@gmail.com>';
    protected $module_type = 'Helper';

    public function __construct(&$dragoon,&$db)
    {
        return parent::__construct(&$db,&$db);
    } // end construct

    // Factory hack - since classes are not truely reloaded,
    // instances will be gotten like this.
    public function factory()
    {
        $this->logMessage("Returning ".__CLASS__,'debug');
        $foo = __CLASS__;
        return new $foo($this->db,$this->db);
    } // end factory

    public function doPruning()
    {
        // TODO - Cross-table DELETE that figures
        // out which data_storage items are old enough
        // to purge from the Dragoon's memory.
    } // end doPruning
    
} // end DataStorage 
?>
