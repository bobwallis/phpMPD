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
	 * This is an array of commands whose output is expected to be an array
	 */
	private $_expectArrayOutput = array( 'commands', 'decoders', 'find', 'list', 'listall', 'listallinfo', 'listplaylist', 'listplaylistinfo', 'listplaylists', 'lsinfo', 'notcommands', 'outputs', 'playlist', 'playlistfind', 'playlistid', 'playlistinfo', 'playlistsearch', 'plchanges', 'plchangesposid', 'search', 'tagtypes', 'urlhandlers' );

	/**
	 * Parses an array of lines of output from MPD into tidier forms
	 * @param array $output The output from MPD
	 * @return string|array
	 */
	private function parseOutput( $output, $command = '' ) {
		$parsedOutput = array();

		// Output lines should look like 'key: value'.
		// Explode the lines, and filter out any empty values
		$output = array_filter( array_map( function( $line ) {
			$parts = explode( ': ', $line, 2 );
			return (count( $parts ) == 2)? $parts : false;
		}, $output ) );

		// If there's no output the command succeded
		if( count( $output ) == 0 ) {
			// For some commands returning an empty array makes more sense than true
			return (in_array( $command, $this->_expectArrayOutput ))? array() : true;
		}
		// If there's only one line of output, just return the value
		elseif( count( $output ) == 1 ) {
			// Again, for some commands it makes sense to force $output to be an array, even if it contains only one value
			return (in_array( $command, $this->_expectArrayOutput ))? array( $output[0][1] ) : $output[0][1];
		}

		/* The output we recieve will look like one of a few cases:
		 *  1) A list (possible of length 1) of objects with certain single-valued properties
		 *     (e.g. a list of songs in a playlist with metadata)
		 *  2) A list of properties
		 *     (e.g. a list of supported output types)
		 *  3) An unordered set of objects with (possibly array-valued) properties
		 *     (e.g. a list of playlists indexed by name, output plugins indexed by name with arrays of supported extensions/types)
		 *  4) Single/Zero value responses
		 *
		 * Case 4 is taken care of.
		 * We handle case 1 by iterating over the key=>value pairs, dropping
		 * them into a map until we reach a key that we've already seen, in which
		 * case we append the map so far into the output array, and begin a new
		 * collection.
		 * Case 2 can be dealt with by the same algorithm if at the end we collpase
		 * single-property objects to the value of the single property.
		 * Case 1/2 is assumed by default. Case 3 outputs are treated as special cases.
		 *
		 * There is another possible case: A list of objects with possibly array-valued properties.
		 * There isn't an example of this, so no worries.
		 */
		$topKey = false;
		switch( $command ) {
			case 'decoders':
				$topKey = 'plugin';
				break;
			case 'listplaylists':
				$topKey = 'playlist';
				break;
			default:
				break;
		}
		if( $topKey ) {
			$collection = array();
			$currentTopValue = '';
			foreach( $output as $line ) {
				if( $line[0] == $topKey ) {
					if( $currentTopValue ) {
						$parsedOutput[$currentTopValue] = $collection;
					}
					$currentTopValue = $line[1];
					$collection = array();
				}
				else {
					if( !isset( $collection[$line[0]] ) ) {
						$collection[$line[0]] = array();
					}
					$collection[$line[0]][] = $line[1];
				}
			}
			$parsedOutput[$currentTopValue] = $collection;

			// Output will always be an array
			return $parsedOutput;
		}
		else {
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

			// If we have a single collection, return it as a single object if we don't expect an array
			if( count( $parsedOutput ) == 1 ) {
				return (in_array( $command, $this->_expectArrayOutput ))? $parsedOutput : $parsedOutput[0];
			}

			// If there's only one property in an object, then collapse it to just that value.
			// Otherwise just return what we have
			return array_map( function( $collection ) {
				return (count( $collection ) == 1)? array_pop( $collection ) : $collection;
			}, $parsedOutput );
		}
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