<?php
/**
 * The CJPHP Standard Conennector, an excellent socket wrapper. 
 *
 * @author Nathan "Fritzy" Fritz
 * @copyright 2004 Nathan "Fritzy" Fritz
 **/

/**
 * @author Nathan "Fritzy" Fritz
 **/
class CJP_StandardConnector
{
	protected $active_socket;

	public function openSocket($server, $port)
	{
		if($this->active_socket = fsockopen($server, $port))
		{
			socket_set_blocking($this->active_socket, 0);
			socket_set_timeout($this->active_socket, 31536000);

			return TRUE;
		}
		else
		{
			return FALSE;
		}
	} // end openSocket

	public function closeSocket()
	{
		return fclose($this->active_socket);
	} // end closeSocket

	public function writeToSocket($data)
	{
		return fwrite($this->active_socket, $data);
	} // end writeTosocket

	public function readFromSocket($chunksize)
	{
		set_magic_quotes_runtime(0);
		$buffer = fread($this->active_socket, $chunksize);
		set_magic_quotes_runtime(get_magic_quotes_gpc());

		return $buffer;
	} // end readFromSocket
} // end CJP_StandardConnector

?>
