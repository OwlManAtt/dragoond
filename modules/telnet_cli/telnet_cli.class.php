<?php
class TelnetCli extends DragoonModule
{
    protected $module_description = 'Telnet CLI/Partial Frontend';
    protected $module_version = '1.0.0';
    protected $module_author = 'OwlManAtt <owlmanatt@gmail.com>';
    protected $module_type = 'Frontend';

    public function __construct()
    {
        $this->logMessage('__construct()ing telnet frontend module...');
    } // end __construct

    public function load()
    {
        $this->logMessage('load()ing telnet frontend module...');

        /*
        $socket = stream_socket_server("tcp://0.0.0.0:8000", $errno, $errstr);
        if (!$socket) 
        {
            return false; 
        } 
        else 
        {
            while($conn = stream_socket_accept($socket)) 
            {
                fwrite($conn,"Greetings. I am Zefiris.\n");
                fwrite($conn,"root@dragoon # ");
                stream_socket_recvfrom($socket,200);
                fclose($conn);

                break;
            }
            fclose($socket);
        }
        */

        $this->logMessage('Telnet module loaded.');
    } // end load
    
    public function unload()
    {
        $this->logMessage('unload()ing telnet frontend module...');
    } // end unload

} // end TelnetCli
?>
