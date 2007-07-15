<?php
class Datasource extends ActiveTable
{
    protected $table_name = 'datasource';
    protected $primary_key = 'datasource_id';
    protected $LOOKUPS = array(
        array(
            'local_key' => 'data_handler_id',
            'foreign_table' => 'data_handler',
            'foreign_key' => 'data_handler_id',
            'join_type' => 'inner',
        ),
    );

} // end Datasource

class DataHandler extends ActiveTable
{
    protected $table_name = 'data_handler';
    protected $primary_key = 'data_handler_id';
 
} // end DataHandler

?>
