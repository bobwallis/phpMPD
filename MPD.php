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
class MPDException extends Exception {}

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
			// We get empty lines sometimes. Ignore them.
			if( empty( $line ) ) {
				continue;
			}
			// If we get an OK then the command has finished
			elseif( strcmp( self::MPD_OK, $line ) == 0 ) {
				break;
			}
			// Catch errors
			elseif( strncmp( self::MPD_ERROR, $line, strlen( self::MPD_ERROR ) ) == 0 && preg_match( '/^ACK \[(.*?)\@(.*?)\] \{(.*?)\} (.*?)$/', $line, $matches ) ) {
				throw new MPDException( 'Command failed: '.$matches[4], self::MPD_COMMAND_FAILED );
			}
			else {
				$output[] = $line;
			}
		}

		if( $info['timed_out'] ) {
			// I can't work out how to rescue a timed-out socket and get it working again. So just throw it away.
			fclose( $this->_connection );
			$this->_connection = null;
			$this->_connected = false;
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
	public function runCommand( $command, $args = array(), $timeout = null ) {
		// Cast the command and arguments to strings, and format properly
		$toWrite = strval( $command );
		foreach( (is_array( $args )? $args : array( $args )) as $arg ) {
			$toWrite .= ' "'.str_replace( '"', '\"', strval( $arg ) ) .'"';
		}

		// Write command to MPD socket
		$this->write( $toWrite );

		// Set the timeout
		if( is_int( $timeout ) ) {
			stream_set_timeout( $this->_connection, $timeout );
		}
		elseif( is_float( $timeout ) ) {
			stream_set_timeout( $this->_connection, floor( $timeout ), round( ($timeout - floor( $timeout ))*1000000 ) );
		}

		// Read output
		$output = $this->read();

		// Reset timeout
		if( !is_null( $timeout ) ) {
			stream_set_timeout( $this->_connection, ini_get( 'default_socket_timeout' ) );
		}

		// Return output
		return $this->parseOutput( $output, $command );
	}

	/**
	 * Parses an array of lines of output from MPD into tidier forms
	 * @param array $output The output from MPD
	 * @return string|array
	 */
	private function parseOutput( $output, $command = '' ) {
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

		// Some commands have custom parsing
		switch( $command ) {
			case 'decoders':
				// The 'plugin' lines are used as keys for objects that contain
				// arrays of 'mime_type's and 'suffix's
				$collection = array();
				$currentPlugin = '';
				foreach( $output as $line ) {
					if( $line[0] == 'plugin' ) {
						if( $currentPlugin) {
							$parsedOutput[$currentPlugin] = $collection;
						}
						$currentPlugin = $line[1];
						$collection = array();
					}
					else {
						if( !isset( $collection[$line[0]] ) ) {
							$collection[$line[0]] = array();
						}
						$collection[$line[0]][] = $line[1];
					}
				}
				$parsedOutput[$currentPlugin] = $collection;
				break;
			default:
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
				break;
		}

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

	/**
	 * Excecuting the 'idle' function requires turning off timeouts, since it could take a long time
	 * @param array $subsystems An array of particular subsystems to watch
	 * @return string|array
	 */
	public function idle( $subsystems = array() ) {
		$idle = $this->runCommand( 'idle', $subsystems, 1800 );
		// When two subsystems are changed, only one is printed before the OK
		// line. Anyone repeatedly polling a PHP script to simulate continuous
		// listening will miss events as MPD creates a new 'client' on every
		// request. This will frequently happen as it isn't uncommon for 'player'
		// 'mixer', and 'playlist' events to fire at the same time (e.g. when someone
		// double-clicks on a file to add it to the playlist and play in one go
		// while playback is stopped)

		// This is annoying. The best workaround I can think of is to use the
		// 'subsystems' argument to split the idle polling into ones that
		// are unlikely to collide.

		// If the stream is local (so we can assume an extremely fast connection to it)
		// then try to avoid missing changes by running new 'idle' requests with a
		// short timeout. This will allow us to clear the queue of any additional
		// changed systems without slowing down the script too much.
		// This works reasonably well, but YMMV.
		$idleArray = array( $idle );
		if( stream_is_local( $this->_connection ) || $this->_host == 'localhost' ) {
			try { while( 1 ) { array_push( $idleArray, $this->runCommand( 'idle', $subsystems, 0.1 ) ); } }
			catch( MPDException $e ) { ; }
		}
		return (count( $idleArray ) == 1)? $idleArray[0] : $idleArray;
	}

	private $_commands = array( 'add', 'addid', 'clear', 'clearerror', 'close', 'commands', 'consume', 'count', 'crossfade', 'currentsong', 'decoders', 'delete', 'deleteid', 'disableoutput', 'enableoutput', 'find', 'findadd', 'idle', 'kill', 'list', 'listall', 'listallinfo', 'listplaylist', 'listplaylistinfo', 'listplaylists', 'load', 'lsinfo', 'mixrampdb', 'mixrampdelay', 'move', 'moveid', 'next', 'notcommands', 'outputs', 'password', 'pause', 'ping', 'play', 'playid', 'playlist', 'playlistadd', 'playlistclear', 'playlistdelete', 'playlistfind', 'playlistid', 'playlistinfo', 'playlistmove', 'playlistsearch', 'plchanges', 'plchangesposid', 'previous', 'random', 'rename', 'repeat', 'replay_gain_mode', 'replay_gain_status', 'rescan', 'rm', 'save', 'search', 'seek', 'seekid', 'setvol', 'shuffle', 'single', 'stats', 'status', 'sticker', 'stop', 'swap', 'swapid', 'tagtypes', 'update', 'urlhandlers' );
	public function __call( $name, $arguments ) {
		if( in_array( $name, $this->_commands ) ) {
			return $this->runCommand( $name, $arguments );
		}
	}
}