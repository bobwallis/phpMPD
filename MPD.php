<?php
/**
 * MPD.php: A PHP class for controlling MPD
 *
 * @author Robert Wallis <bob.wallis@gmail.com>
 * @copyright Copyright (c) 2011, Robert Wallis
 * @license http://www.opensource.org/licenses/gpl-3.0 GPLv3
 * @link https://github.com/bobwallis/phpMPD Project Page
 * @link http://www.musicpd.org/doc/protocol/ MPD Protocol Documentation
 * @example /examples/example.php Examples of how to use this class
 * @package MPD
 */

/**
 * A custom exception class
 * @package MPD
 */
class MPDException extends \Exception {}

/**
 * A PHP class for controlling MPD
 * @package MPD
 */
class MPD {

	// Connection, read, write errors
	const MPD_CONNECTION_FAILED = -1;
	const MPD_CONNECTION_NOT_OPENED = -2;
	const MPD_WRITE_FAILED = -3;
	const MPD_STATUS_EMPTY = -4;
	const MPD_UNEXPECTED_OUTPUT = -5;
	const MPD_TIMEOUT = -5;

	// MPD Errors
	const MPD_NOT_LIST = 1;
	const MPD_ARG = 2;
	const MPD_PASSWORD = 3;
	const MPD_PERMISSION = 4;
	const MPD_UNKOWN = 5;
	const MPD_NO_EXIST = 50;
	const MPD_PLAYLIST_MAX = 51;
	const MPD_SYSTEM = 52;
	const MPD_PLAYLIST_LOAD = 53;
	const MPD_UPDATE_ALREADY = 54;
	const MPD_PLAYER_SYNC = 55;
	const MPD_EXIST = 56;
	const MPD_COMMAND_FAILED = -100;

	// MPD Responses
	const MPD_OK = 'OK';
	const MPD_ERROR = 'ACK';

	// Connection and details
	private $_connection = null;
	private $_host = 'localhost';
	private $_port = 6600;
	private $_password = null;
	private $_version = '0';

	/**
	 * Set connection paramaters.
	 * @param $host Host to connect to, (default: localhost)
	 * @param $port Port to connect through, (default: 6600)
	 * @param $password Password to send, (default: null)
	 * @return void
	 */
	function __construct( $host = 'localhost', $port = 6600, $password = null ) {
		$this->_host = $host;
		$this->_port = $port;
		$this->_password = $password;
	}

	/**
	 * Connects to the MPD server
	 * @return bool
	 */
	public function connect() {
		// Check whether the socket is already connected
		if( $this->isConnected() ) {
			return true;
		}

		// Open the socket
		$connection = @fsockopen( $this->_host, $this->_port, $errn, $errs, 5 );
		if( $connection == false ) {
			throw new MPDException( 'Connection failed: '.$errs, self::MPD_CONNECTION_FAILED );
		}
		$this->_connection = $connection;

		// Clear connection messages
		while( !feof( $this->_connection ) ) {
			$response = trim( fgets( $this->_connection ) );
			// If the connection messages have cleared
			if( strncmp( self::MPD_OK, $response, strlen( self::MPD_OK ) ) == 0 ) {
				$this->_connected = true;
				// Read off MPD version
				list( $this->_version ) = sscanf( $response, self::MPD_OK . " MPD %s\n" );
				// Send the connection password
				if( !is_null( $this->_password ) ) {
					$this->password( $this->_password );
				}
				return true;
				break;
			}
			// Catch MPD errors on connection
			if( strncmp( self::MPD_ERROR, $response, strlen( self::MPD_ERROR ) ) == 0 ) {
				$this->_connected = false;
				preg_match( '/^ACK \[(.*?)\@(.*?)\] \{(.*?)\} (.*?)$/', $response, $matches );
				throw new MPDException( 'Connection failed: '.$matches[4], self::MPD_CONNECTION_FAILED );
				return false;
				break;
			}
		}
		throw new MPDException( 'Connection failed', self::MPD_CONNECTION_FAILED );
	}

	/**
	 * Disconnects from the MPD server
	 * @return bool
	 */
	public function disconnect() {
		if( !is_null( $this->_connection ) ) {
			$this->close();
			fclose( $this->_connection );
			$this->_connection = null;
			$this->_connected = false;
		}
		return true;
	}


	private $_connected = false;
	/**
	* Checks whether the socket has connected
	* @return bool
	*/
	public function isConnected() {
		return $this->_connected;
	}

	/**
	 * Writes data to the MPD socket
	 * @param string $data The data to be written
	 * @return bool
	 */
	private function write( $data ) {
		if( !$this->isConnected() ) {
			$this->connect();
		}
		if( !fwrite( $this->_connection, $data."\r\n" ) ) {
			throw new MPDException( 'Failed to write to MPD socket', self::MPD_WRITE_FAILED );
			return false;
		}
		return true;
	}

	/**
	 * Reads data from the MPD socket
	 * @return array Array of lines of data
	 */
	private function read() {
		// Check for a connection
		if( !$this->isConnected() ) {
			$this->connect();
		}

		// Set up output array and get stream information
		$output = array();
		$info = stream_get_meta_data( $this->_connection );

		// Wait for output to finish or time out
		while( !feof( $this->_connection ) && !$info['timed_out'] ) {
			$line = trim( fgets( $this->_connection ) );
			$info = stream_get_meta_data( $this->_connection );
			$matches = array();
			if( empty( $line ) ) {
				continue;
			}
			elseif( strcmp( self::MPD_OK, $line ) == 0 ) {
				break;
			}
			elseif( strncmp( self::MPD_ERROR, $line, strlen( self::MPD_ERROR ) ) == 0 && preg_match( '/^ACK \[(.*?)\@(.*?)\] \{(.*?)\} (.*?)$/', $line, $matches ) ) {
				throw new MPDException( 'Command failed: '.$matches[4], self::MPD_COMMAND_FAILED );
			}
			else {
				$output[] = $line;
			}
		}

		if( $info['timed_out'] ) {
			throw new MPDException( 'Command timed out', self::MPD_TIMEOUT );
		}
		else {
			return $output;
		}
	}

	/**
	 * Runs a given command with arguments
	 * @param string $command The command to execute
	 * @param string|array $args The command's argument(s)
	 * @param int $timeout The script's timeout, in seconds
	 * @return array Array of parsed output
	 */
	private function runCommand( $command, $args = array(), $timeout = null ) {
		// Cast the command and arguments to strings, and format properly
		$toWrite = strval( $command );
		foreach( (is_array( $args )? $args : array( $args )) as $arg ) {
			$toWrite .= ' "'.str_replace( '"', '\"', strval( $arg ) ) .'"';
		}

		// Write command to MPD socket
		$this->write( $toWrite );

		// Read and return output
		if( is_int( $timeout ) ) {
			stream_set_timeout( $this->_connection, $timeout );
		}
		$output = $this->read();
		return $this->parseOutput( $output );
	}

	private function parseOutput( $output ) {
		$parsedOutput = array();

		// Check for empty output, meaning that just 'OK' was printed
		if( $output === array() ) {
			return true;
		}

		// Output lines should look like 'key: value'.
		// Explode the lines, and filter out any empty values
		$output = array_filter( array_map( function( $line ) {
			$parts = explode( ': ', $line, 2 );
			return (count( $parts ) == 2)? $parts : false;
		}, $output ) );

		// If there's only one line of output, just return the value
		if( count( $output ) == 1 ) {
			return $output[0][1];
		}

		// Iterate over the lines. We collect key=>value pairs into a single array until
		// we get a key that we've already had, in which case we'll append the array to
		// the $parsedOutput array, and begin collecting again.
		$collection = array();
		foreach( $output as $line ) {
			if( array_key_exists( $line[0], $collection ) ) {
				$parsedOutput[] = $collection;
				$collection = array( $line[0] => $line[1] );
			}
			else {
				$collection[$line[0]] = $line[1];
			}
		}
		$parsedOutput[] = $collection;

		// If we have a single collection, return it
		if( count( $parsedOutput ) == 1 ) {
			return $parsedOutput[0];
		}

		// If there's only one key in a collection, then collapse it to just the value.
		// Then return
		return array_map( function( $collection ) {
			return (count( $collection ) == 1)? array_pop( $collection ) : $collection;
		}, $parsedOutput );
	}

	private $_commands = array( 'add', 'addid', 'clear', 'clearerror', 'close', 'commands', 'consume', 'count', 'crossfade', 'currentsong', 'decoders', 'delete', 'deleteid', 'disableoutput', 'enableoutput', 'find', 'findadd', 'idle', 'kill', 'list', 'listall', 'listallinfo', 'listplaylist', 'listplaylistinfo', 'listplaylists', 'load', 'lsinfo', 'mixrampdb', 'mixrampdelay', 'move', 'moveid', 'next', 'notcommands', 'outputs', 'password', 'pause', 'ping', 'play', 'playid', 'playlist', 'playlistadd', 'playlistclear', 'playlistdelete', 'playlistfind', 'playlistid', 'playlistinfo', 'playlistmove', 'playlistsearch', 'plchanges', 'plchangesposid', 'previous', 'random', 'rename', 'repeat', 'replay_gain_mode', 'replay_gain_status', 'rescan', 'rm', 'save', 'search', 'seek', 'seekid', 'setvol', 'shuffle', 'single', 'stats', 'status', 'sticker', 'stop', 'swap', 'swapid', 'tagtypes', 'update', 'urlhandlers' );
	public function __call( $name, $arguments ) {
		if( in_array( $name, $this->_commands ) ) {
			return $this->runCommand( $name, $arguments );
		}
	}
}