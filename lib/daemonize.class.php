<?php
/**
 * A very basic class to daemonize another class. 
 *
 * Upgraded to PHP5 / misc. improvements by OwlManAtt around 2007-07-15. Originally from 
 * <http://www.phpclasses.org/browse/file/8958.html>.
 * 
 * @package Daemonize 
 * @author Michal Golebiowski
 * @author OwlManAtt <owlmanatt@gmail.com>
 * @license GPL? (Unknown)
 */

/**
 * Log message levels.
 **/
define('DLOG_TO_CONSOLE', 1);
define('DLOG_NOTICE', 2);
define('DLOG_WARNING', 4);
define('DLOG_ERROR', 8);
define('DLOG_CRITICAL', 16);

/**
 * Daemon base class
 *
 * Requirements:
 * Unix like operating system
 * PHP 4 >= 4.3.0 or PHP 5
 * PHP compiled with:
 * --enable-sigchild
 * --enable-pcntl
 *
 * @package Deamonize 
 * @author Michal 'Seth' Golebiowski <seth at binarychoice dot pl>
 * @author OwlManAtt <owlmanatt@gmail.com>
 * @copyright Copyright 2005 Seth
 */
abstract class Daemonize
{
   /**
    * User ID
    * 
    * @var int
    */
   public $userID = 99;

   /**
    * Group ID
    * 
    * @var integer
    */
   public $groupID = 99;
   
   /**
    * Terminate daemon when set identity failure ?
    * 
    * @var bool
    */
   public $requireSetIdentity = false;

   /**
    * Path to PID file
    * 
    * @var string
    */
   public $pidFileLocation = '/tmp/daemon.pid';

   /**
    * Home path
    * 
    * @var string
    */
   public $homePath = '/';
   /**#@-*/


   /**
    * Current process ID
    * 
    * @var int
    */
   protected $_pid = 0;

   /**
    * Is this process a children
    * 
    * @var boolean
    */
   protected $_isChildren = false;

   /**
    * Is daemon running
    * 
    * @var boolean
    */
   protected $_isRunning = false;
 

   /**
    * Constructor
    *
    * @access public
    * @return void
    */
   public function __construct()
   {
      error_reporting(0);
      set_time_limit(0);
      ob_implicit_flush();

      register_shutdown_function(array(&$this, 'releaseDaemon'));
   } // end __construct

   /**
    * Starts daemon
    *
    * @access public
    * @return bool
    */
    public function start()
    {
        $this->logMessage('Starting daemon');

        if(!$this->_daemonize())
        {
            $this->logMessage('Could not start daemon',DLOG_ERROR);

            return false;
        } // end deamonize fail

        $this->logMessage('Running...');
        $this->_isRunning = true;

        while($this->_isRunning)
        {
            $this->_doTask();
        }

        return true;
    } // end start

   /**
    * Stops daemon
    *
    * @access public
    * @return void
    */
    public function stop()
    {
        $this->logMessage('Stoping daemon');
        $this->_isRunning = false;
    } // end stop

   /**
    * Do task
    *
    * @access protected
    * @return void
    */
    abstract protected function doTask();

    /**
     * Logs message
     *
     * @access protected
     * @return void
     **/
    abstract protected function logMessage($msg, $level = DLOG_NOTICE);
   
   /**
    * Daemonize
    *
    * Several rules or characteristics that most daemons possess:
    * 1) Check is daemon already running
    * 2) Fork child process
    * 3) Sets identity
    * 4) Make current process a session laeder
    * 5) Write process ID to file
    * 6) Change home path
    * 7) umask(0)
    * 
    * @access private
    * @return void
    */
    protected function _daemonize()
    {
        ob_end_flush();

        if($this->_isDaemonRunning())
        {
            // Deamon is already running. Exiting
            return false;
        }

        if(!$this->_fork())
        {
            // Coudn't fork. Exiting.
            return false;
        }

        if(!$this->_setIdentity() && $this->requireSetIdentity)
        {
            // Required identity set failed. Exiting
            return false;
        }

        if(!posix_setsid())
        {
            $this->logMessage('Could not make the current process a session leader',DLOG_ERROR);

            return false;
        }

        if(!$fp = @fopen($this->pidFileLocation,'w'))
        {
            $this->logMessage('Could not write to PID file', DLOG_ERROR);

            return false;
        } // end pidfail
        else
        {
            fputs($fp,$this->_pid);
            fclose($fp);
        } // end pid success

        @chdir($this->homePath);
        umask(0);

        declare(ticks = 1);

        pcntl_signal(SIGCHLD, array(&$this, 'sigHandler'));
        pcntl_signal(SIGTERM, array(&$this, 'sigHandler'));

        return true;
    } // and _daemonize

   /**
    * Cheks is daemon already running
    *
    * @access private
    * @return bool
    */
    protected function _isDaemonRunning()
    {
        $oldPid = @file_get_contents($this->pidFileLocation);

        if ($oldPid !== false && posix_kill(trim($oldPid),0))
        {
            $this->logMessage('Daemon already running with PID: '.$oldPid,(DLOG_TO_CONSOLE | DLOG_ERROR));

            return true;
        }
        else
        {
            return false;
        }
    } // end _isDaemonRunning

   /**
    * Forks process
    *
    * @access private
    * @return bool
    */
    protected function _fork()
    {
        $this->logMessage('Forking...');

        $pid = pcntl_fork();

        if($pid == -1) // error
        {
            $this->logMessage('Could not fork', DLOG_ERROR);

            return false;
        }
        elseif($pid) // parent
        {
            $this->logMessage('Killing parent');

            exit();
        }
        else // children
        {
            $this->_isChildren = true;
            $this->_pid = posix_getpid();

            return true;
        }
    } // end _fork

   /**
    * Sets identity of a daemon and returns result
    *
    * @access private
    * @return bool
    */
    protected function _setIdentity()
    {
        if(!posix_setgid($this->groupID) || !posix_setuid($this->userID))
        {
            $this->logMessage('Could not set identity', DLOG_WARNING);

            return false;
        }
        else
        {
            return true;
        }
    } // end _setIdentity

   /**
    * Signals handler
    *
    * @access public
    * @return void
    */
    protected function sigHandler($sigNo)
    {
        switch($sigNo)
        {
            case SIGTERM:   // Shutdown
            {
                $this->logMessage('Shutdown signal');
                exit();
                
                break;
            } // end SIGTERM

            case SIGCHLD:   // Halt
            {
                $this->logMessage('Halt signal');
                while (pcntl_waitpid(-1, $status, WNOHANG) > 0);

                break;
            } // end SIGCHLD
        } // end signal switch
    } // end sigHandler

   /**
    * Releases daemon pid file
    * This method is called on exit (destructor like)
    *
    * @access public
    * @return void
    */
    public function releaseDaemon()
    {
        if($this->_isChildren && file_exists($this->pidFileLocation))
        {
            $this->logMessage('Releasing daemon');

            unlink($this->pidFileLocation);
        }
    } // end releaseDaemon
} // end Daemonize
?>
