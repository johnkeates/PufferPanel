<?php
/*
 * Class written by xPaw
 *
 * Permission was obtained by PufferPanel Devs to include
 * this file with PufferPanel. By including this file with
 * PufferPanel we assume no rights. All rights remain with
 * the origional author: xPaw.
 *
 * A special thanks to xPaw for granting us permission to
 * include and use this code with PufferPanel.
 *
 * Website: http://xpaw.ru
 * GitHub: https://github.com/xPaw/PHP-Minecraft-Query
 */
 
class MinecraftQueryException extends Exception
{
	// Exception thrown by MinecraftQuery class
}

class GSD_Query extends dbConn {

	public function __construct($serverid){
	
		if($serverid === false)
			$this->_queryData = false;
		else {
		
			$this->mysql = parent::getConnection();
			$this->gsid = (int)$serverid;
			$this->_queryData = array();
			
			/*
			 * Load Information into Script
			 */
			$this->executeQuery = $this->mysql->prepare("SELECT * FROM `servers` WHERE `id` = :sid");
			$this->executeQuery->execute(array(
				':sid' => $this->gsid
			));
			
				if($this->executeQuery->rowCount() == 1){
				
					 $this->row = $this->executeQuery->fetch();
					 
					 foreach($this->row as $this->id => $this->val)
					 	$this->_queryData = array_merge($this->_queryData, array($this->id => $this->val));
					 	
				}else
					$this->_queryData = false;
					
		}
	
	}
	
	public function online($override = false) {
	
		if($this->_queryData === false && $override === false)
			return false;
		else {
			
			/*
			 * Allow Override of Specific Server (used for pinging)
			 */
			$this->_queryData['gsd_id'] = ($override !== false) ? (int)$override : $this->_queryData['gsd_id'];
			
			$this->context = stream_context_create(array(
				"http" => array(
					"method" => "GET",
					"timeout" => 3
				)
			));
			$this->gatherData = @file_get_contents("http://".$this->_queryData['ftp_host'].":8003/gameservers/".$this->_queryData['gsd_id'] , 0, $this->context);
		
			$this->raw = json_decode($this->gatherData, true);
			
				/*
				 * Valid Data was Returned
				 */
				if(json_last_error() == JSON_ERROR_NONE){
				
					if($this->raw['status'] == 0)
						return false;
					else{
						$this->_jsonData = $this->raw['query'];
						return true;
					}
				
				}else
					return false;
		
		}
		
	
	}
	
	public function retrieve($element = null) {
		
		if($this->online() === true)
			if(is_null($element))
				return $this->_jsonData;
			else
				return (array_key_exists($element, $this->_jsonData)) ? $this->_jsonData[$element] : null;
		else
			return null;
		
	}

}

class MinecraftQuery
{
	
	const STATISTIC = 0x00;
	const HANDSHAKE = 0x09;
	
	private $socket;
	private $players;
	private $info;
	
	public function connect($ip, $port = 25565, $timeout = 1){
		
		if(!is_int($timeout) || $timeout < 0)
			throw new InvalidArgumentException('Timeout must be an integer.');
		
		$this->socket = @fsockopen('udp://'.$ip, (int)$port, $errno, $errstr, $timeout);
		
		if($errno || $this->socket === false)
			throw new MinecraftQueryException('Could not create socket: '.$errstr);
		
		stream_set_timeout($this->socket, $timeout);
		stream_set_blocking($this->socket, true);
		
		try
			{
			
				$challenge = $this->getChallenge();
				$this->getStatus($challenge);
				
			}
			
		catch(MinecraftQueryException $e)
			{
		
				fclose($this->socket);
				throw new MinecraftQueryException($e->getMessage());
				
			}
		
		fclose($this->socket);
			
	}
	
	public function getInfo($value = null){
	
		if(is_null($value))
			return isset($this->info) ? $this->info : false;
		else
			return (isset($this->info) && array_key_exists($value, $this->info)) ? $this->info[$value] : false;
	
	}
	
	public function getPlayers(){
	
		return isset($this->players) ? $this->players : false;
		
	}
	
	private function getChallenge(){
	
		$data = $this->writeData(self::HANDSHAKE);
		
		if($data === false)
			throw new MinecraftQueryException('Failed to receive challenge.');
		
		return pack('N', $data);
		
	}
	
	private function getStatus($challenge){
	
		$data = $this->writeData(self::STATISTIC, $challenge.pack('c*', 0x00, 0x00, 0x00, 0x00));
		
		if(!$data)
			throw new MinecraftQueryException('Failed to receive status.');
		
		$last = '';
		$info = array();
		
		$data = substr($data, 11);
		$data = explode("\x00\x00\x01player_\x00\x00", $data);
		
		if(count($data) !== 2)
			throw new MinecraftQueryException('Failed to parse server\'s response.');
		
		$players = substr($data[1], 0, -2);
		$data = explode("\x00", $data[0]);
		
		$keys = array(
			'hostname'   => 'HostName',
			'gametype'   => 'GameType',
			'version'    => 'Version',
			'plugins'    => 'Plugins',
			'map'        => 'Map',
			'numplayers' => 'Players',
			'maxplayers' => 'MaxPlayers',
			'hostport'   => 'HostPort',
			'hostip'     => 'HostIp'
		);
		
		foreach($data as $key => $value)
		{
			
			if(~$key & 1){
				
				if(!array_key_exists($value, $keys)){
				
					$last = false;
					continue;
					
				}
				
				$last = $keys[$value];
				$info[$last] = '';
				
			}else if($last != false)
				$info[$last] = $value;
				
		}
		
		$info['Players'] = intval($info['Players']);
		$info['MaxPlayers'] = intval($info['MaxPlayers']);
		$info['HostPort'] = intval($info['HostPort']);
		
		if($info['Plugins']){
		
			$data = explode(": ", $info['Plugins'], 2);
			
			$info['RawPlugins'] = $info['Plugins'];
			$info['Software'] = $data[0];
			
			if(count($data) == 2)
				$info['Plugins'] = explode("; ", $data[1]);

		}else
			$info['Software'] = 'Vanilla';
		
		$this->info = $info;
		
		if($players)
			$this->players = explode("\x00", $players);

	}
	
	private function writeData($command, $append = ""){
	
		$command = pack('c*', 0xFE, 0xFD, $command, 0x01, 0x02, 0x03, 0x04).$append;
		$length = strlen($command);
		
		if($length !== fwrite($this->socket, $command, $length))
			throw new MinecraftQueryException( "Failed to write on socket." );
		
		$data = fread($this->socket, 2048);
		
		if($data === false)
			throw new MinecraftQueryException( "Failed to read from socket." );
		
		if(strlen($data) < 5 || $data[0] != $command[2])
			return false;
		
		return substr($data, 5);
		
	}
	
}