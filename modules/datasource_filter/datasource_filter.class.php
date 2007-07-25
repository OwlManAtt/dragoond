<?php
class DatasourceFilter extends ActiveTable
{
    protected $table_name = 'datasource_filter';
    protected $primary_key = 'datasource_filter_id';

    protected $module_description = 'Datasource Filter Wrapper';
    protected $module_version = '1.0.0';
    protected $module_author = 'OwlManAtt <owlmanatt@gmail.com>';
    protected $module_type = 'Helper';

    public function __construct(&$dragoon,&$db)
    {
        return parent::__construct($db,$db);
    } // end construct

    // Factory hack - since classes are not truely reloaded,
    // instances will be gotten like this.
    public function factory()
    {
        $foo = __CLASS__;
        return new $foo($this->db,$this->db);
    } // end factory
} // end DatasourceFilter
?>
